<?php

namespace KeyAgency\AssetUsage\Usage;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Facades\Blink;

/**
 * Reads and writes the usage index. It lives in storage rather than in the
 * site's content, so it is driver-agnostic, survives a cache clear, and stays
 * out of git-integration commits.
 */
class IndexStore
{
    public const VERSION = 1;

    private const BLINK_KEY = 'asset-usage-index';

    private const INDEX_BLINK_KEY = 'asset-usage-index-object';

    public const STATE_READY = 'ready';

    public const STATE_BUILDING = 'building';

    public function directory(): string
    {
        return storage_path('statamic/asset-usage');
    }

    public function path(): string
    {
        return $this->directory().'/index.json';
    }

    public function exists(): bool
    {
        return File::exists($this->path());
    }

    /**
     * The stored payload, decoded once per request.
     */
    public function payload(): ?array
    {
        return Blink::once(self::BLINK_KEY, function () {
            if (! $this->exists()) {
                return null;
            }

            $decoded = json_decode((string) File::get($this->path()), true);

            return is_array($decoded) ? $decoded : null;
        });
    }

    /**
     * Built once per request as well. The asset browser asks once per row, and
     * turning a large payload back into objects each time adds up to seconds.
     * Callers only read it; anything that changes the index works on its own
     * copy and writes it back, which clears this one.
     */
    public function index(): ?UsageIndex
    {
        return Blink::once(self::INDEX_BLINK_KEY, fn () => ($payload = $this->payload()) === null
            ? null
            : UsageIndex::fromArray($payload));
    }

    /**
     * The index, or an empty one when nothing has been built yet. Callers that
     * need to tell "no usages" apart from "not indexed" should check
     * `exists()` or `isStale()` first.
     */
    public function indexOrEmpty(): UsageIndex
    {
        return $this->index() ?? new UsageIndex;
    }

    public function meta(): array
    {
        $payload = $this->payload() ?? [];

        return [
            'version' => $payload['version'] ?? null,
            'state' => $payload['state'] ?? null,
            'built_at' => $payload['built_at'] ?? null,
            'containers' => $payload['containers'] ?? [],
            'scan_urls' => $payload['scan_urls'] ?? null,
            'scanned_types' => $payload['scanned_types'] ?? [],
            'include_working_copies' => $payload['include_working_copies'] ?? null,
            'items_scanned' => $payload['items_scanned'] ?? 0,
        ];
    }

    public function isBuilding(): bool
    {
        return $this->meta()['state'] === self::STATE_BUILDING;
    }

    /**
     * An index that was built for other settings than the ones in force now, or
     * in an older format, can't be trusted. Neither can a missing one.
     */
    public function isStale(): bool
    {
        if (! $this->exists()) {
            return true;
        }

        $meta = $this->meta();

        if ($meta['version'] !== self::VERSION || $meta['state'] !== self::STATE_READY) {
            return true;
        }

        $stored = $meta['containers'];
        $current = Containers::enabled()->map->handle()->all();

        sort($stored);
        sort($current);

        if ($stored !== $current) {
            return true;
        }

        return $meta['scan_urls'] !== Settings::scansUrls()
            || $meta['scanned_types'] !== Settings::scannedTypes()
            || $meta['include_working_copies'] !== Settings::includesWorkingCopies();
    }

    /**
     * Whether the usage data has gone long enough without a full rebuild to be
     * worth mentioning. Not the same thing as stale: an aged index is still
     * built for the settings in force, so its numbers are as good as the last
     * pass left them. A stale one is excluded because it already says more than
     * this would, and two notices about the same button read as two problems.
     */
    public function isAged(): bool
    {
        if ($this->isStale() || ! $days = Settings::rebuildReminderDays()) {
            return false;
        }

        $builtAt = $this->meta()['built_at'];

        return $builtAt !== null && Carbon::parse($builtAt)->lt(now()->subDays($days));
    }

    public function write(UsageIndex $index, array $meta = []): void
    {
        $this->locked(function () use ($index, $meta) {
            $this->put(array_merge([
                'version' => self::VERSION,
                'state' => self::STATE_READY,
                'built_at' => now()->toIso8601String(),
                'items_scanned' => $index->itemCount(),
            ], $this->settingsMeta(), $meta, $index->toArray()));
        });
    }

    /**
     * Apply a change to the stored index under the lock, so two content saves
     * landing at the same time can't overwrite each other's work.
     */
    public function mutate(callable $callback): void
    {
        $this->locked(function () use ($callback) {
            $this->forgetCached();

            if (! $this->exists()) {
                return;
            }

            $payload = $this->payload();
            $index = UsageIndex::fromArray($payload ?? []);

            $callback($index);

            $this->put(array_merge($payload ?? [], [
                'items_scanned' => $index->itemCount(),
            ], $index->toArray()));
        });
    }

    /**
     * Flag a build as running so the CP can say so instead of reporting an
     * empty index as "nothing is used".
     */
    public function markBuilding(): void
    {
        $this->locked(function () {
            $payload = $this->payload() ?? array_merge($this->settingsMeta(), [
                'version' => self::VERSION,
                'usages' => [],
                'items' => [],
            ]);

            $this->put(array_merge($payload, ['state' => self::STATE_BUILDING]));
        });
    }

    /**
     * The settings an index depends on. Stored alongside it so a config change
     * shows up as an out-of-date index instead of quietly wrong results.
     */
    private function settingsMeta(): array
    {
        return [
            'containers' => Containers::enabled()->map->handle()->all(),
            'scan_urls' => Settings::scansUrls(),
            'scanned_types' => Settings::scannedTypes(),
            'include_working_copies' => Settings::includesWorkingCopies(),
        ];
    }

    public function delete(): void
    {
        File::delete($this->path());

        $this->forgetCached();
    }

    /**
     * Write via a temp file and rename, so a reader never sees half a document.
     */
    private function put(array $payload): void
    {
        File::ensureDirectoryExists($this->directory());

        $temp = $this->path().'.'.bin2hex(random_bytes(4));

        // A broken character (a filename, an error message) is replaced; never write an empty file over the store.
        File::put($temp, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        File::move($temp, $this->path());

        $this->forgetCached();

        // The asset browser sorts the "Used" column on a cached copy of these counts.
        SortIndex::clear();
    }

    private function locked(callable $callback): void
    {
        Cache::lock('asset-usage-index', 30)->block(15, $callback);
    }

    private function forgetCached(): void
    {
        Blink::forget(self::BLINK_KEY);
        Blink::forget(self::INDEX_BLINK_KEY);
    }
}
