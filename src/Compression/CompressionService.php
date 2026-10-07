<?php

namespace KeyAgency\AssetUsage\Compression;

use Illuminate\Support\Facades\File;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\Source;
use Statamic\Contracts\Assets\Asset;

/**
 * The before and after page and what its buttons do: make a preview, replace
 * the file with exactly that preview, and put the original back.
 */
class CompressionService
{
    /** Previews nobody acted on are cleared after this long. */
    private const PREVIEW_LIFETIME_SECONDS = 86400;

    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly Backups $backups,
        private readonly AssetLog $log = new AssetLog,
    ) {}

    public static function make(): self
    {
        return new self(Analyzer::make(), new Backups, new AssetLog);
    }

    public function analyzer(): Analyzer
    {
        return $this->analyzer;
    }

    public function backups(): Backups
    {
        return $this->backups;
    }

    public function previewDirectory(): string
    {
        return storage_path('statamic/asset-usage/previews');
    }

    /**
     * Keyed by the file version and the settings, so a preview never outlives
     * the file or the settings it was made from.
     */
    public function previewPath(Asset $asset): string
    {
        $key = md5($asset->id().'|'.Analyzer::version($asset).'|'.$this->analyzer->compressor()->fingerprint());

        return $this->previewDirectory().'/'.$key.'.'.$asset->extension();
    }

    /**
     * The analysis record for the file as it is now, with a preview on disk
     * when compressing worked. Made fresh unless both already exist.
     */
    public function preview(Asset $asset): array
    {
        $path = $this->previewPath($asset);
        $record = $this->analyzer->fresh($asset);

        if ($record && ($record['status'] !== CompressionResult::OK || File::exists($path))) {
            return $record;
        }

        $this->clearOldPreviews();

        $result = $this->analyzer->compress($asset);
        $record = $this->analyzer->record($asset, $result);

        $this->analyzer->store()->store([$asset->id() => $record]);

        if ($result->ok()) {
            File::ensureDirectoryExists($this->previewDirectory());
            File::put($path, $result->bytes);
        }

        return $record;
    }

    /**
     * Replaces the file with the preview that was shown. Refuses when the file
     * changed since, or when the preview would not make it smaller.
     *
     * @param  string  $source  where it was compressed, for the log, see Source
     * @return array the record of the file before it was replaced
     *
     * @throws FileChanged
     * @throws NothingToCompress
     */
    public function compress(Asset $asset, string $version, $user = null, string $source = Source::TOOLS): array
    {
        if ($version !== Analyzer::version($asset)) {
            throw new FileChanged;
        }

        $record = $this->preview($asset);
        $path = $this->previewPath($asset);

        if ($record['status'] !== CompressionResult::OK || ($record['savings'] ?? 0) <= 0 || ! File::exists($path)) {
            throw new NothingToCompress;
        }

        /*
         * Statamic's meta can still describe a file that was replaced outside
         * it (SFTP, a deploy), so the version alone can't be trusted here: the
         * preview would be written over a file it was never made from.
         */
        if ((int) $asset->disk()->size($asset->path()) !== $record['before_bytes']) {
            throw new FileChanged;
        }

        $this->backups->store($asset);

        $asset->reupload(new PathReplacementFile($path));

        File::delete($path);

        $this->backups->markCompressed($asset, Analyzer::version($asset));
        $this->analyzer->analyze([$asset], force: true);
        $this->log->recordCompression($asset, $record, $user, $source);

        return $record;
    }

    public function restore(Asset $asset, $user = null): void
    {
        $this->backups->restore($asset);
        $this->log->markRestored($asset, $user);

        $this->analyzer->analyze([$asset], force: true);
    }

    private function clearOldPreviews(): void
    {
        if (! File::isDirectory($this->previewDirectory())) {
            return;
        }

        foreach (File::files($this->previewDirectory()) as $file) {
            if ($file->getMTime() < time() - self::PREVIEW_LIFETIME_SECONDS) {
                File::delete($file->getPathname());
            }
        }
    }
}
