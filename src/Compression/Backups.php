<?php

namespace KeyAgency\AssetUsage\Compression;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Contracts\Assets\Asset;
use Statamic\Support\Str;
use Throwable;

/**
 * The originals of compressed images, kept in storage so they can be put back.
 * Outside the container on purpose: inside it they would show up as assets.
 *
 * Kept by path, so a backup only counts while the file at that path is still
 * the one compressing produced. Once it has been replaced, or deleted and
 * uploaded again, the backup belongs to another file and is ignored.
 */
class Backups
{
    /**
     * Its own suffix, so it can't collide with an asset that happens to be
     * called something.json.
     */
    private const META_SUFFIX = '.backup.json';

    public function directory(): string
    {
        return storage_path('statamic/asset-usage/originals');
    }

    public function path(Asset $asset): string
    {
        return $this->directory().'/'.$asset->container()->handle().'/'.$asset->path();
    }

    public function has(Asset $asset): bool
    {
        if (! File::exists($this->path($asset)) || ! File::exists($this->metaPath($asset))) {
            return false;
        }

        return ($this->meta($asset)['version'] ?? null) === Analyzer::version($asset);
    }

    public function compressedAt(Asset $asset): ?Carbon
    {
        if (! $this->has($asset)) {
            return null;
        }

        $compressedAt = $this->meta($asset)['compressed_at'] ?? null;

        return $compressedAt ? Carbon::parse($compressedAt) : null;
    }

    /**
     * How much smaller the file is than its kept original, in percent. Against
     * the first original, so an image compressed twice shows the whole saving.
     */
    public function savings(Asset $asset): ?float
    {
        if (! $original = $this->originalBytes($asset)) {
            return null;
        }

        return round(($original - $asset->size()) / $original * 100, 1);
    }

    public function originalBytes(Asset $asset): ?int
    {
        return $this->has($asset) ? File::size($this->path($asset)) : null;
    }

    /** What compressing saved against the kept original, ready to show. */
    public function summary(Asset $asset): ?array
    {
        if (($savings = $this->savings($asset)) === null) {
            return null;
        }

        return [
            'before' => Str::fileSizeForHumans($this->originalBytes($asset), 1),
            'after' => Str::fileSizeForHumans($asset->size(), 1),
            'savings' => (int) round($savings),
        ];
    }

    /** Null when originals are kept forever. */
    public function expiresAt(Asset $asset): ?Carbon
    {
        $days = Settings::keepOriginalsDays();
        $compressedAt = $this->compressedAt($asset);

        return $days === null || ! $compressedAt ? null : $compressedAt->copy()->addDays($days);
    }

    /**
     * Compressing an image a second time keeps the first original, which is
     * the one worth having back. The clock restarts, though.
     *
     * @throws BackupFailed when the original couldn't be copied completely, so
     *                      the file is never replaced without a way back
     */
    public function store(Asset $asset): void
    {
        $meta = $this->has($asset) ? $this->meta($asset) : [];

        if (! $meta) {
            $this->copyOriginal($asset);
        }

        File::put($this->metaPath($asset), json_encode(['compressed_at' => now()->toIso8601String()] + $meta));
    }

    /**
     * Copied to a temp file, checked against the size on the disk and only
     * then moved into place, so a copy that dies halfway (a full disk) never
     * passes for the original.
     */
    private function copyOriginal(Asset $asset): void
    {
        $path = $this->path($asset);
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        try {
            File::ensureDirectoryExists(dirname($path));

            $source = $asset->disk()->filesystem()->readStream($asset->path());
            $target = fopen($temp, 'wb');

            try {
                $copied = is_resource($source) && is_resource($target) ? stream_copy_to_stream($source, $target) : false;
            } finally {
                is_resource($source) && fclose($source);
                is_resource($target) && fclose($target);
            }

            if ($copied === false || $copied !== (int) $asset->disk()->size($asset->path())) {
                throw new BackupFailed("The original of {$asset->id()} could not be copied completely.");
            }

            File::move($temp, $path);
        } catch (Throwable $e) {
            File::delete($temp);

            throw $e instanceof BackupFailed ? $e : new BackupFailed($e->getMessage(), previous: $e);
        }
    }

    /**
     * Remembers which version of the file compressing produced, so the
     * analysis can tell its own output apart.
     */
    public function markCompressed(Asset $asset, string $version): void
    {
        File::put($this->metaPath($asset), json_encode(array_merge($this->meta($asset), [
            'version' => $version,
        ])));
    }

    /**
     * Whether the file as it is now is what compressing produced. Read from
     * the meta alone, which prune() leaves behind, so an image doesn't get
     * offered again once its original is gone.
     *
     * Whatever the settings: encoding its own output again loses quality
     * every time, and a change that has nothing to do with the image, such as
     * the server finding pngquant, would otherwise offer every image again.
     */
    public function producedCurrentFile(Asset $asset, string $version): bool
    {
        return ($this->meta($asset)['version'] ?? null) === $version;
    }

    public function restore(Asset $asset): void
    {
        $asset->reupload(new PathReplacementFile($this->path($asset)));

        $this->delete($asset);
    }

    public function delete(Asset $asset): void
    {
        File::delete([$this->path($asset), $this->metaPath($asset)]);
    }

    /** Follows a rename or a move, so the original stays with its file. */
    public function move(string $container, string $from, string $to): void
    {
        $base = $this->directory().'/'.$container.'/';

        if (! File::exists($base.$from)) {
            return;
        }

        File::ensureDirectoryExists(dirname($base.$to));
        File::move($base.$from, $base.$to);

        if (File::exists($base.$from.self::META_SUFFIX)) {
            File::move($base.$from.self::META_SUFFIX, $base.$to.self::META_SUFFIX);
        }
    }

    /**
     * Deletes originals kept longer than `keep_originals_days`. Their meta
     * stays, reduced to what tells the analysis the file is compressing's own
     * output (see producedCurrentFile()).
     *
     * Files nothing can restore go too, whatever the setting: an original
     * whose meta is missing or unreadable, and a temp file a failed copy left
     * behind. Only after a day, because a copy being made right now has no
     * meta yet either. Folders left empty are removed.
     *
     * @return int the number of files deleted
     */
    public function prune(): int
    {
        if (! File::isDirectory($this->directory())) {
            return 0;
        }

        $days = Settings::keepOriginalsDays();
        $deleted = 0;

        foreach (File::allFiles($this->directory(), true) as $file) {
            $path = $file->getPathname();

            if (str_ends_with($path, self::META_SUFFIX)) {
                continue;
            }

            $meta = $this->readMeta($path.self::META_SUFFIX);
            $compressedAt = isset($meta['compressed_at']) ? Carbon::parse($meta['compressed_at']) : null;

            $expired = $compressedAt
                ? $days !== null && $compressedAt->lt(now()->subDays($days))
                : $file->getMTime() < now()->subDay()->getTimestamp();

            if (! $expired) {
                continue;
            }

            File::delete($path);
            $deleted++;

            if ($compressedAt) {
                File::put($path.self::META_SUFFIX, json_encode(Arr::only($meta, ['version', 'settings'])));
            } else {
                File::delete($path.self::META_SUFFIX);
            }
        }

        $this->removeEmptyDirectories($this->directory());

        return $deleted;
    }

    private function removeEmptyDirectories(string $directory): void
    {
        foreach (File::directories($directory) as $child) {
            $this->removeEmptyDirectories($child);

            if (File::isEmptyDirectory($child)) {
                File::deleteDirectory($child);
            }
        }
    }

    public function totalBytes(): int
    {
        if (! File::isDirectory($this->directory())) {
            return 0;
        }

        return collect(File::allFiles($this->directory(), true))->sum(fn ($file) => $file->getSize());
    }

    private function metaPath(Asset $asset): string
    {
        return $this->path($asset).self::META_SUFFIX;
    }

    private function meta(Asset $asset): array
    {
        return $this->readMeta($this->metaPath($asset));
    }

    private function readMeta(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $meta = json_decode((string) File::get($path), true);

        return is_array($meta) ? $meta : [];
    }
}
