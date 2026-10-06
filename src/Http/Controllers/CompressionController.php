<?php

namespace KeyAgency\AssetUsage\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\BackupFailed;
use KeyAgency\AssetUsage\Compression\CompressionService;
use KeyAgency\AssetUsage\Compression\FileChanged;
use KeyAgency\AssetUsage\Compression\NothingToCompress;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Compression\Status;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\AuthorizesAssetUsage;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Support\NavIcon;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\Reference;
use Statamic\Facades\Asset;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Str;

class CompressionController extends CpController
{
    use AuthorizesAssetUsage;

    /** Every extension Compressor::EXTENSIONS accepts, with its type. */
    private const IMAGE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /** The most images one batch of "Compress all" may hold. The page sends fewer. */
    private const MAX_BATCH = 10;

    /**
     * The before and after page. Opening it changes nothing: the page asks for
     * the preview with a POST once it has loaded (see preview()), so a link or
     * a redirect alone can't set the compressor to work.
     */
    public function show(Request $request)
    {
        $asset = $this->findCompressibleAsset($request);
        $service = CompressionService::make();
        $backups = $service->backups();
        $version = Analyzer::version($asset);

        return Inertia::render('asset-usage::Compress', [
            'icon' => NavIcon::svg(),
            'asset' => [
                'id' => $asset->id(),
                'path' => $asset->path(),
                'basename' => $asset->basename(),
                'extension' => strtolower($asset->extension()),
                'container_title' => $asset->container()->title(),
                'edit_url' => $asset->editUrl(),
                'version' => $version,
            ],
            'settings' => $service->analyzer()->compressor()->describe(),
            'threshold' => Settings::compressionThreshold(),
            'pngquantUrl' => Requirements::PNGQUANT_URL,
            'keepOriginalsDays' => Settings::keepOriginalsDays(),
            'backup' => [
                'exists' => $backups->has($asset),
                'expires_at' => $backups->expiresAt($asset)?->toIso8601String(),
                'compressed' => $backups->summary($asset),
            ],
            'beforeUrl' => cp_route('asset-usage.compress.image', ['asset' => $asset->id(), 'variant' => 'before', 'v' => $version]),
            'afterUrl' => cp_route('asset-usage.compress.image', ['asset' => $asset->id(), 'variant' => 'after', 'v' => $version]),
            'previewUrl' => cp_route('asset-usage.compress.preview'),
            'compressUrl' => cp_route('asset-usage.compress.store'),
            'restoreUrl' => cp_route('asset-usage.compress.restore'),
            'backUrl' => cp_route('asset-usage.compression'),
        ]);
    }

    /**
     * Makes the preview, or reuses the one made for this version of the file
     * and these settings.
     */
    public function preview(Request $request)
    {
        $record = CompressionService::make()->preview($this->findCompressibleAsset($request));

        return [
            'record' => $record,
            'sizes' => [
                'before' => Str::fileSizeForHumans($record['before_bytes'], 1),
                'after' => $record['after_bytes'] !== null ? Str::fileSizeForHumans($record['after_bytes'], 1) : null,
            ],
        ];
    }

    /**
     * Served through the CP, so the comparison works for private containers
     * and never depends on the container having a public URL.
     */
    public function image(Request $request)
    {
        $asset = $this->findCompressibleAsset($request);

        /*
         * The type comes from the extension rather than from sniffing the
         * content, and browsers are told not to sniff either: an upload named
         * .png that holds HTML must never run as a page on the CP's origin.
         */
        $headers = [
            'Content-Type' => self::IMAGE_TYPES[strtolower($asset->extension())],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
        ];

        if ($request->input('variant') === 'after') {
            $path = CompressionService::make()->previewPath($asset);

            abort_unless(is_file($path), 404);

            return response()->file($path, $headers);
        }

        return response()->stream(function () use ($asset) {
            fpassthru($stream = $asset->stream());
            is_resource($stream) && fclose($stream);
        }, 200, $headers);
    }

    /**
     * The message is a toast for the page the redirect leads to: one shown
     * here would be gone with this page before anyone could read it.
     */
    public function store(Request $request)
    {
        $asset = $this->findCompressibleAsset($request);
        $version = $request->validate(['version' => 'required|string'])['version'];
        $service = CompressionService::make();

        try {
            $record = $service->compress($asset, $version, User::current());
        } catch (FileChanged) {
            return response()->json(['message' => __('asset-usage::messages.compress.file_changed')], 409);
        } catch (NothingToCompress) {
            return response()->json(['message' => __('asset-usage::messages.compress.nothing_to_compress')], 422);
        } catch (BackupFailed $e) {
            report($e);

            return response()->json(['message' => __('asset-usage::messages.compress.backup_failed')], 500);
        }

        $expiresAt = $service->backups()->expiresAt($asset);

        Toast::success(__($expiresAt ? 'asset-usage::messages.compress.compressed' : 'asset-usage::messages.compress.compressed_kept', [
            'before' => Str::fileSizeForHumans($record['before_bytes'], 1),
            'after' => Str::fileSizeForHumans($record['after_bytes'], 1),
            'date' => $expiresAt?->translatedFormat('j F Y'),
        ]));

        return ['redirect' => cp_route('asset-usage.compression')];
    }

    /**
     * One batch of "Compress all". The page sends a few images at a time, so
     * a long run never meets the time limit of a single request, and it can
     * show how far it is. An image that can't be compressed is reported and
     * skipped; a failed backup ends the run, because every next one would
     * fail the same way.
     */
    public function batch(Request $request)
    {
        $this->authorizeCompress();

        abort_unless(Settings::compressionEnabled(), 404);
        $this->abortUnlessAvailable();

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'ids.*' => ['required', 'string'],
        ])['ids'];

        $service = CompressionService::make();
        $user = User::current();
        $compressed = 0;
        $saved = 0;
        $errors = [];
        $halt = false;

        foreach ($ids as $id) {
            try {
                $result = $this->compressOne($service, $id, $user);
            } catch (BackupFailed $e) {
                report($e);
                $result = __('asset-usage::messages.compress.backup_failed');
                $halt = true;
            }

            if (is_string($result)) {
                $errors[] = ['path' => Reference::parse($id)?->path ?? $id, 'message' => $result];
            } else {
                $compressed++;
                $saved += $result['before_bytes'] - $result['after_bytes'];
            }

            if ($halt) {
                break;
            }
        }

        return [
            'compressed' => $compressed,
            'saved_bytes' => $saved,
            'errors' => $errors,
            'halt' => $halt,
        ];
    }

    public function restore(Request $request)
    {
        $asset = $this->findCompressibleAsset($request);
        $service = CompressionService::make();

        if (! $service->backups()->has($asset)) {
            return response()->json(['message' => __('asset-usage::messages.compress.no_backup')], 404);
        }

        $service->restore($asset, User::current());

        Toast::success(__('asset-usage::messages.compress.restored'));

        return ['redirect' => cp_route('asset-usage.compression')];
    }

    /**
     * Flagged as analysing before anything is queued, so the Tools page says so
     * right away instead of only once a worker picks it up.
     */
    public function analyze()
    {
        $this->authorizeCompress();

        abort_unless(Settings::compressionEnabled(), 404);
        $this->abortUnlessAvailable();

        $analyzer = Analyzer::make();

        if (! $analyzer->store()->isAnalyzing()) {
            $analyzer->store()->markPreparing($analyzer->compressor()->fingerprint());

            AnalyzeAllCompression::dispatch();
        }

        return ['compression' => Status::current($analyzer)];
    }

    public function status()
    {
        $this->authorizeView();

        return ['compression' => Status::current()];
    }

    /**
     * Replacing the file is Statamic's "reupload", so its policy applies on top
     * of the addon's own permission, as do the enabled containers.
     */
    private function findCompressibleAsset(Request $request)
    {
        $this->authorizeCompress();

        abort_unless(Settings::compressionEnabled(), 404);
        $this->abortUnlessAvailable();

        $id = $request->validate(['asset' => 'required|string'])['asset'];

        abort_unless($asset = $this->findImage($id), 404);
        abort_unless(User::current()?->can('reupload', $asset), 403);

        return $asset;
    }

    /**
     * One image of a batch, checked the same way as on the before and after
     * page. Against the current version: there was no preview to keep to,
     * and the size on the disk still catches a file Statamic's meta is behind.
     *
     * @return array|string the record of the file before it was replaced, or why it was skipped
     *
     * @throws BackupFailed
     */
    private function compressOne(CompressionService $service, string $id, $user): array|string
    {
        if (! $asset = $this->findImage($id)) {
            return __('asset-usage::messages.errors.asset_missing');
        }

        if (! $user->can('reupload', $asset)) {
            return __('statamic::error.unauthorized');
        }

        try {
            return $service->compress($asset, Analyzer::version($asset), $user);
        } catch (FileChanged) {
            return __('asset-usage::messages.compress.all_file_changed');
        } catch (NothingToCompress) {
            return __('asset-usage::messages.compress.nothing_to_compress');
        }
    }

    /** An image this addon compresses, in an enabled container. */
    private function findImage(string $id)
    {
        $reference = Reference::parse($id);

        if (! $reference || ! Containers::includes($reference->container) || ! $asset = Asset::find($id)) {
            return null;
        }

        return Analyzer::applies($asset) ? $asset : null;
    }

    private function abortUnlessAvailable(): void
    {
        abort_unless(Requirements::available(), 503, __('asset-usage::messages.compress.unavailable', [
            'driver' => Requirements::check()['driver'],
        ]));
    }
}
