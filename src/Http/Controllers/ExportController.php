<?php

namespace KeyAgency\AssetUsage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use KeyAgency\AssetUsage\Compression\CompressionResult;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Export\Pdf;
use KeyAgency\AssetUsage\Export\Report;
use KeyAgency\AssetUsage\Export\Thumbnails;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\AuthorizesAssetUsage;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\ListsAssets;
use KeyAgency\AssetUsage\Support\ImageDensity;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Unused;
use KeyAgency\AssetUsage\Usage\UsageIndex;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The overview and the Compression page as a PDF, with the same filters and
 * order as the list. The page first has the missing thumbnails made a few at
 * a time and then asks for the PDF, so no request has to decode hundreds of
 * originals and every one stays short, also on the sync queue.
 */
class ExportController extends CpController
{
    use AuthorizesAssetUsage, ListsAssets;

    /** The most rows one PDF lists. dompdf gets slow and memory-hungry on longer tables. */
    public const MAX_ROWS = 1000;

    /** Thumbnails per request. Each one decodes an original, which can take a moment on a large file. */
    public const THUMBNAIL_BATCH = 5;

    /** What each page sorts on when the request names nothing, the same as the page starts with. */
    private const DEFAULT_SORTS = [
        'usage' => ['path', 'asc'],
        'compression' => ['savings', 'desc'],
    ];

    /**
     * The images in the PDF that have no thumbnail yet, in the order the PDF
     * lists them, so the page can have them made before it asks for the PDF.
     */
    public function thumbnails(Request $request)
    {
        [, $unused, $index] = $this->prepare($request);

        if (! Requirements::available()) {
            return ['ids' => []];
        }

        $thumbnails = new Thumbnails;
        $ids = array_slice($this->listedIds($request, $unused->containers(), $index), 0, self::MAX_ROWS);
        $assets = $this->assetValues($ids, fn ($asset) => $asset);

        return [
            'ids' => array_values(array_filter($ids, fn (string $id) => isset($assets[$id])
                && Thumbnails::applies($assets[$id])
                && ! $thumbnails->has($assets[$id]))),
        ];
    }

    /** One that can't be made is skipped: the PDF shows the extension in its place. */
    public function makeThumbnails(Request $request)
    {
        $this->authorizeView();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::THUMBNAIL_BATCH],
            'ids.*' => ['required', 'string'],
        ]);

        $this->raiseTimeLimit();

        $thumbnails = new Thumbnails;
        $made = 0;

        foreach ($validated['ids'] as $id) {
            $asset = $this->findEnabledAsset($id);

            if (! $asset || ! $this->userCanView($asset->container())) {
                continue;
            }

            if ($thumbnails->make($asset)) {
                $made++;
            }

            // Read here while the file is at hand, so the PDF itself doesn't have to go to the disk for it.
            ImageDensity::for($asset);
        }

        return ['made' => $made];
    }

    public function download(Request $request)
    {
        [$view, $unused, $index] = $this->prepare($request);

        $this->raiseLimits();

        $report = $this->report($request, $view, $unused, $index, $this->timezone($request));

        return response(app(Pdf::class)->render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $report->filename),
        ]);
    }

    /**
     * The same permissions as the page the PDF is made from, and on the
     * overview a usable index. A PDF of "unused" assets built from data that
     * can't be believed would be handed to someone who goes and deletes them.
     *
     * Each page's own filters are applied and the other's dropped, whatever
     * the request carries, so the PDF lists what that page lists.
     *
     * @return array{0: string, 1: Unused, 2: UsageIndex}
     */
    private function prepare(Request $request): array
    {
        $this->authorizeView();

        $view = $request->input('view') === 'compression' ? 'compression' : 'usage';

        if ($view === 'compression') {
            $this->authorizeCompress();

            abort_unless(Settings::compressionEnabled(), 404);

            $request->merge([
                'usage' => 'all',
                'site' => null,
                'compression' => in_array($request->input('compression'), ['compressible', 'compressed'], true) ? $request->input('compression') : 'all',
            ]);
        } else {
            $request->merge(['compression' => null]);
        }

        if (! in_array($request->input('sort'), self::SORTS, true)) {
            [$sort, $order] = self::DEFAULT_SORTS[$view];

            $request->merge(['sort' => $sort, 'order' => $order]);
        }

        $unused = Unused::make(new IndexStore);

        abort_if($view === 'usage' && ! $unused->hasUsageData(), 409, __('asset-usage::messages.export.unusable_index'));

        return [$view, $unused, $unused->index()];
    }

    private function report(Request $request, string $view, Unused $unused, UsageIndex $index, string $timezone): Report
    {
        $ids = $this->listedIds($request, $unused->containers(), $index);
        $assets = $this->assetValues($ids, fn ($asset) => $asset);
        $ids = array_values(array_filter($ids, fn (string $id) => isset($assets[$id])));

        $thumbnails = new Thumbnails;
        $showsSavings = Settings::compressionEnabled() && Requirements::available();
        $now = Carbon::now($timezone)->locale(app()->getLocale());

        $rows = array_map(
            fn (string $id) => $this->row($assets[$id], $index, $unused->hasUsageData(), $thumbnails, $showsSavings, $timezone),
            array_slice($ids, 0, self::MAX_ROWS)
        );

        return new Report(
            title: __('asset-usage::messages.nav_title'),
            subtitle: __($view === 'compression' ? 'asset-usage::messages.nav.compression' : 'asset-usage::messages.nav.usage'),
            createdAt: $now->isoFormat('LLL'),
            filters: $this->activeFilters($request, $view),
            sort: $this->sortText($request),
            total: count($ids),
            totalSize: Str::fileSizeForHumans(array_sum(array_map(fn ($asset) => (int) $asset->size(), $assets)), 1),
            rows: $rows,
            showsContainer: $unused->containers()->all()->filter(fn ($container) => $this->userCanView($container))->count() > 1,
            showsSavings: $showsSavings,
            emptyText: $this->emptyText($request, $view),
            overviewUrl: $this->overviewUrl($request, $view),
            // The time without colons, which Windows doesn't allow in a file name.
            filename: 'asset-usage-'.($view === 'compression' ? 'compression' : 'overview').'-'.$now->format('Y-m-d-His').'.pdf',
        );
    }

    private function row($asset, UsageIndex $index, bool $usageKnown, Thumbnails $thumbnails, bool $showsSavings, string $timezone): array
    {
        $count = $index->countFor($asset->id());
        [$saving, $marked] = $showsSavings ? $this->savingText($this->compression($asset)) : [null, false];

        return [
            'path' => $asset->path(),
            'extension' => $asset->extension(),
            'container' => $asset->container()->title(),
            'edit_url' => $asset->editUrl(),
            'thumbnail' => Thumbnails::applies($asset) ? $thumbnails->dataUri($asset) : null,
            'size' => Str::fileSizeForHumans($asset->size(), 0),
            'dimensions' => $asset->isImage() && $asset->width() ? $asset->width().' × '.$asset->height() : null,
            'dpi' => $asset->isImage() ? ImageDensity::for($asset) : null,
            'saving' => $saving,
            'saving_marked' => $marked,
            'last_modified' => $asset->lastModified()->copy()->setTimezone($timezone)->locale(app()->getLocale())->isoFormat('ll'),
            // Without a usable index a count of 0 means "not checked", not "unused", the same as on the page.
            'usage' => match (true) {
                ! $usageKnown => __('asset-usage::messages.index.not_ready'),
                $count === 0 => __('asset-usage::messages.filters.unused'),
                default => trans_choice('asset-usage::messages.used_count', $count, ['count' => $count]),
            },
            'unused' => $usageKnown && $count === 0,
        ];
    }

    /**
     * What the Saving column says, worded like the badges on the page, and
     * whether it is a saving worth setting apart.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function savingText(?array $compression): array
    {
        if (! $compression) {
            return [null, false];
        }

        $percent = '−'.(int) round((float) $compression['savings']).'%';
        $compressed = __('asset-usage::messages.compress.compressed_badge');

        return match (true) {
            $compression['compressible'] => [$percent, true],
            $compression['status'] === CompressionResult::COMPRESSED => [$compression['savings'] ? "{$compressed} ({$percent})" : $compressed, true],
            $compression['status'] === CompressionResult::OK && round((float) $compression['savings']) >= 1 => [$percent, false],
            $compression['status'] === CompressionResult::OK => [__('asset-usage::messages.compress.no_saving'), false],
            $compression['status'] === CompressionResult::TOO_LARGE => [__('asset-usage::messages.compress.too_large'), false],
            $compression['status'] === CompressionResult::UNSUPPORTED => [__('asset-usage::messages.compress.unsupported'), false],
            $compression['status'] === CompressionResult::ERROR => [__('asset-usage::messages.compress.failed'), false],
            default => [__('asset-usage::messages.compress.not_analyzed'), false],
        };
    }

    /** @return array<int, array{label: string, value: string}> */
    private function activeFilters(Request $request, string $view): array
    {
        $filters = [];
        $usage = $request->input('usage');
        $compression = $request->input('compression');
        $search = trim((string) $request->input('search'));

        if ($view === 'usage' && in_array($usage, ['used', 'unused'], true)) {
            $filters[] = ['label' => __('asset-usage::messages.filters.usage'), 'value' => __("asset-usage::messages.filters.{$usage}")];
        }

        if ($view === 'compression' && in_array($compression, ['compressible', 'compressed'], true)) {
            $filters[] = ['label' => __('asset-usage::messages.compress.label'), 'value' => __("asset-usage::messages.compress.filters.{$compression}")];
        }

        if ($handle = $request->input('container')) {
            // Only named when the user may view it; the list is empty for any other container anyway.
            $container = Containers::enabled()->first(fn ($container) => $container->handle() === $handle && $this->userCanView($container));

            $filters[] = ['label' => __('asset-usage::messages.filters.container'), 'value' => $container?->title() ?? $handle];
        }

        if ($handle = $request->input('site')) {
            $filters[] = ['label' => __('asset-usage::messages.export.site'), 'value' => Site::get($handle)?->name() ?? $handle];
        }

        if ($search !== '') {
            $filters[] = ['label' => __('asset-usage::messages.export.search'), 'value' => $search];
        }

        return $filters;
    }

    private function sortText(Request $request): string
    {
        $sort = $request->input('sort');
        $column = __('asset-usage::messages.columns.'.($sort === 'path' ? 'name' : $sort));

        return __('asset-usage::messages.export.'.($request->input('order') === 'desc' ? 'descending' : 'ascending'), ['column' => $column]);
    }

    private function emptyText(Request $request, string $view): string
    {
        if ($view === 'usage') {
            return __('asset-usage::messages.no_results');
        }

        return __($request->input('compression') === 'compressed'
            ? 'asset-usage::messages.compress.none_compressed'
            : 'asset-usage::messages.compress.none_compressible');
    }

    /**
     * The page with these filters, the same URL the page itself shows: only
     * what differs from how the page starts out.
     */
    private function overviewUrl(Request $request, string $view): string
    {
        [$sort, $order] = self::DEFAULT_SORTS[$view];

        $query = array_filter([
            'search' => trim((string) $request->input('search')),
            'usage' => $view === 'usage' && in_array($request->input('usage'), ['used', 'unused'], true) ? $request->input('usage') : null,
            'compression' => $view === 'compression' && $request->input('compression') !== 'all' ? $request->input('compression') : null,
            'container' => $request->input('container'),
            'site' => $request->input('site'),
            'sort' => $request->input('sort') !== $sort ? $request->input('sort') : null,
            'order' => $request->input('order', 'asc') !== $order ? $request->input('order', 'asc') : null,
        ]);

        $url = cp_route($view === 'compression' ? 'asset-usage.compression' : 'asset-usage.index');

        return $query ? $url.'?'.http_build_query($query) : $url;
    }

    /** The browser's timezone, like the dates on the page, or the app's when it sent none Carbon knows. */
    private function timezone(Request $request): string
    {
        $timezone = $request->input('timezone');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : config('app.timezone');
    }

    /** Both limits Statamic gives Glide, for dompdf, which lays out the whole PDF in memory. */
    private function raiseLimits(): void
    {
        @ini_set('memory_limit', (string) config('statamic.system.php_memory_limit'));

        $this->raiseTimeLimit();
    }

    /**
     * Only the time for the thumbnails. Statamic's memory limit for Glide is
     * none at all by default, and without a limit the check in Thumbnails
     * would let GD decode an image of any size into the server's memory.
     */
    private function raiseTimeLimit(): void
    {
        @set_time_limit((int) config('statamic.system.php_max_execution_time'));
    }
}
