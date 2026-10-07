<?php

namespace KeyAgency\AssetUsage\Log;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;

/**
 * What happened to assets: every compression the addon made and every
 * deletion, wherever it was done. Kept next to the usage index in storage. A
 * restore doesn't remove a compression, it marks it, so the log keeps the
 * whole history while the totals only count what is still compressed.
 */
class AssetLog
{
    public const COMPRESSED = 'compressed';

    public const DELETED = 'deleted';

    private const BLINK_KEY = 'asset-usage-asset-log';

    /**
     * JSON Lines: one entry per line, so recording one is an append rather than
     * a rewrite of the whole log, which a bulk delete would make quadratic.
     */
    public function path(): string
    {
        return storage_path('statamic/asset-usage/asset-log.jsonl');
    }

    /** @return array<int, array> oldest first */
    public function entries(?string $type = null): array
    {
        $entries = Blink::once(self::BLINK_KEY, function () {
            if (! File::exists($this->path())) {
                return [];
            }

            $entries = [];

            foreach (explode("\n", (string) File::get($this->path())) as $line) {
                // A line cut short by a crash is skipped rather than losing the rest.
                if ($line !== '' && is_array($entry = json_decode($line, true))) {
                    $entries[] = $entry;
                }
            }

            return $entries;
        });

        return $type ? array_values(array_filter($entries, fn (array $entry) => $entry['type'] === $type)) : $entries;
    }

    /**
     * The entries a user may see: those of containers they can view. Null is
     * the console, which sees everything.
     *
     * @return array<int, array>
     */
    public function visibleTo($user, ?string $type = null): array
    {
        $entries = $this->entries($type);

        if (! $user || $user->isSuper()) {
            return $entries;
        }

        $viewable = [];

        return array_values(array_filter($entries, function (array $entry) use ($user, &$viewable) {
            $handle = $entry['container'];

            return $viewable[$handle] ??= ($container = AssetContainer::findByHandle($handle)) !== null && $user->can('view', $container);
        }));
    }

    /**
     * @param  array  $record  the analysis record of the preview that was written
     * @param  string  $source  where it was compressed, see Source
     */
    public function recordCompression(Asset $asset, array $record, $user = null, string $source = Source::TOOLS): void
    {
        $this->append([
            'type' => self::COMPRESSED,
            'asset_id' => $asset->id(),
            'container' => $asset->container()->handle(),
            'path' => $asset->path(),
            'source' => $source,
            'before_bytes' => $record['before_bytes'],
            'after_bytes' => $record['after_bytes'],
            'before_width' => $record['before_width'] ?? null,
            'before_height' => $record['before_height'] ?? null,
            'after_width' => $record['after_width'] ?? null,
            'after_height' => $record['after_height'] ?? null,
            'before_dpi' => $record['before_dpi'] ?? null,
            'after_dpi' => $record['after_dpi'] ?? null,
            'at' => now()->toIso8601String(),
            'by' => self::user($user),
            'restored_at' => null,
            'restored_by' => null,
        ]);
    }

    /**
     * @param  array  $details  what the asset was before it went: bytes, width, height, usage_count
     * @param  string  $source  where it was deleted, see Source
     */
    public function recordDeletion(string $id, string $container, string $path, array $details, string $source, $user = null): void
    {
        $this->append([
            'type' => self::DELETED,
            'asset_id' => $id,
            'container' => $container,
            'path' => $path,
            'bytes' => $details['bytes'] ?? null,
            'width' => $details['width'] ?? null,
            'height' => $details['height'] ?? null,
            // Null when there was no usable index to ask.
            'usage_count' => $details['usage_count'] ?? null,
            'source' => $source,
            'at' => now()->toIso8601String(),
            'by' => self::user($user),
        ]);
    }

    /**
     * Marks every compression of this asset that is still in place: the kept
     * original dates from before the first one, so restoring undoes them all.
     */
    public function markRestored(Asset $asset, $user = null): void
    {
        $this->mutate(function (array $entries) use ($asset, $user) {
            foreach ($entries as $i => $entry) {
                if ($entry['type'] === self::COMPRESSED && $entry['asset_id'] === $asset->id() && $entry['restored_at'] === null) {
                    $entries[$i]['restored_at'] = now()->toIso8601String();
                    $entries[$i]['restored_by'] = self::user($user);
                }
            }

            return $entries;
        });
    }

    /**
     * What compressing saved, counting only compressions whose original was
     * not put back.
     */
    public function compressionTotals(?array $entries = null): array
    {
        $compressed = array_filter($entries ?? $this->entries(), fn (array $entry) => $entry['type'] === self::COMPRESSED);
        $active = array_filter($compressed, fn (array $entry) => $entry['restored_at'] === null);

        return [
            'count' => count($active),
            'before_bytes' => array_sum(array_column($active, 'before_bytes')),
            'after_bytes' => array_sum(array_column($active, 'after_bytes')),
            'resized' => count(array_filter($active, fn (array $entry) => $entry['before_width'] !== null
                && ($entry['after_width'] < $entry['before_width'] || $entry['after_height'] < $entry['before_height']))),
            'restored' => count($compressed) - count($active),
        ];
    }

    public function deletionTotals(?array $entries = null): array
    {
        $deleted = array_filter($entries ?? $this->entries(), fn (array $entry) => $entry['type'] === self::DELETED);

        return [
            'count' => count($deleted),
            'bytes' => array_sum(array_column($deleted, 'bytes')),
        ];
    }

    private static function user($user): ?array
    {
        if (! $user) {
            return null;
        }

        // Statamic's name() falls back to the email address, which isn't stored here.
        $name = $user->name();

        return ['id' => $user->id(), 'name' => $name && $name !== $user->email() ? $name : null];
    }

    private function append(array $entry): void
    {
        $line = self::encode(['id' => (string) Str::uuid()] + $entry);

        Cache::lock('asset-usage-asset-log', 30)->block(15, function () use ($line) {
            File::ensureDirectoryExists(dirname($this->path()));

            // After a line cut short by a crash, start on a new one so this entry isn't glued onto it.
            $prefix = $this->endsMidLine() ? "\n" : '';

            File::append($this->path(), $prefix.$line."\n");

            Blink::forget(self::BLINK_KEY);
        });
    }

    private function endsMidLine(): bool
    {
        if (! is_file($this->path()) || filesize($this->path()) === 0) {
            return false;
        }

        $handle = fopen($this->path(), 'rb');
        fseek($handle, -1, SEEK_END);
        $last = fread($handle, 1);
        fclose($handle);

        return $last !== "\n";
    }

    /** Rewrites the whole log, which only a restore needs. */
    private function mutate(callable $callback): void
    {
        Cache::lock('asset-usage-asset-log', 30)->block(15, function () use ($callback) {
            Blink::forget(self::BLINK_KEY);

            $lines = array_map(fn (array $entry) => self::encode($entry), $callback($this->entries()));

            File::ensureDirectoryExists(dirname($this->path()));

            // Via a temp file and rename, so a reader never sees half a log.
            $temp = $this->path().'.'.bin2hex(random_bytes(4));
            File::put($temp, $lines ? implode("\n", $lines)."\n" : '');
            File::move($temp, $this->path());

            Blink::forget(self::BLINK_KEY);
        });
    }

    /** A broken character (a filename, an error message) is replaced rather than failing the write. */
    private static function encode(array $entry): string
    {
        return json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
