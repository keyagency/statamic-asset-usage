<?php

namespace KeyAgency\AssetUsage\Compression;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blink;

/**
 * The compression analysis per asset, next to the usage index in storage and
 * for the same reasons: driver-agnostic, survives a cache clear, and stays out
 * of git-integration commits.
 *
 * Each record carries the file version and the compressor fingerprint it was
 * made with, so a replaced file or a settings change reads as "not analysed"
 * instead of showing a saving that no longer applies.
 */
class AnalysisStore
{
    private const BLINK_KEY = 'asset-usage-compression';

    public const STATE_READY = 'ready';

    public const STATE_ANALYZING = 'analyzing';

    /** A run that hasn't finished in this long is assumed to have died. */
    private const ANALYZING_TIMEOUT_HOURS = 6;

    public function directory(): string
    {
        return storage_path('statamic/asset-usage');
    }

    public function path(): string
    {
        return $this->directory().'/compression.json';
    }

    public function exists(): bool
    {
        return File::exists($this->path());
    }

    public function payload(): array
    {
        return Blink::once(self::BLINK_KEY, function () {
            if (! $this->exists()) {
                return [];
            }

            $decoded = json_decode((string) File::get($this->path()), true);

            return is_array($decoded) ? $decoded : [];
        });
    }

    public function meta(): array
    {
        $payload = $this->payload();

        return [
            'state' => $payload['state'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'analyzed_at' => $payload['analyzed_at'] ?? null,
            'settings' => $payload['settings'] ?? null,
            'pending' => count($payload['batches'] ?? []),
        ];
    }

    /** @return array<string, array> */
    public function records(): array
    {
        return $this->payload()['records'] ?? [];
    }

    public function record(string $id): ?array
    {
        return $this->records()[$id] ?? null;
    }

    /**
     * The record for this asset, but only when it still describes the file as
     * it is now and was made with the settings in force.
     */
    public function fresh(string $id, string $version, string $fingerprint): ?array
    {
        $record = $this->record($id);

        if (! $record || ($record['version'] ?? null) !== $version || ($record['settings'] ?? null) !== $fingerprint) {
            return null;
        }

        return $record;
    }

    public function isAnalyzing(): bool
    {
        $meta = $this->meta();

        return $meta['state'] === self::STATE_ANALYZING
            && $meta['started_at'] !== null
            && Carbon::parse($meta['started_at'])->gt(now()->subHours(self::ANALYZING_TIMEOUT_HOURS));
    }

    /**
     * The last full analysis ran with other settings than the ones in force.
     */
    public function settingsChanged(string $fingerprint): bool
    {
        $settings = $this->meta()['settings'];

        return $settings !== null && $settings !== $fingerprint;
    }

    /**
     * Flags a run before its batches are known, so the CP says it is running
     * the moment the button is pressed rather than once a worker gets to it.
     */
    public function markPreparing(string $fingerprint): void
    {
        $this->locked(function () use ($fingerprint) {
            $this->put(array_merge($this->payload(), [
                'state' => self::STATE_ANALYZING,
                'started_at' => now()->toIso8601String(),
                'settings' => $fingerprint,
                'batches' => [],
            ]));
        });
    }

    /**
     * Starts a run of these batches. Each one strikes itself off when done
     * (see store()), which is idempotent: a batch finishing twice, as a retry
     * after a timeout can, still counts once. The run ends when none are left.
     *
     * A run covers every image there is, so the records of assets it leaves
     * out (deleted, or in a container that was switched off) are dropped here.
     *
     * @param  array<string, string[]>  $batches  asset ids per batch id
     */
    public function markAnalyzing(array $batches, string $fingerprint): void
    {
        $this->locked(function () use ($batches, $fingerprint) {
            $payload = $this->payload();
            $payload['records'] = array_intersect_key($payload['records'] ?? [], array_flip(array_merge([], ...array_values($batches))));

            $this->put(array_merge($payload, [
                'state' => $batches ? self::STATE_ANALYZING : self::STATE_READY,
                'started_at' => now()->toIso8601String(),
                'settings' => $fingerprint,
                'batches' => array_keys($batches),
            ] + ($batches ? [] : ['analyzed_at' => now()->toIso8601String()])));
        });
    }

    /** Follows a rename or a move, so the analysis stays with its file. */
    public function move(string $from, string $to): void
    {
        if (! isset($this->records()[$from])) {
            return;
        }

        $this->locked(function () use ($from, $to) {
            $payload = $this->payload();

            if (! isset($payload['records'][$from])) {
                return;
            }

            $payload['records'][$to] = $payload['records'][$from];
            unset($payload['records'][$from]);

            $this->put($payload);
        });
    }

    /**
     * Store records, and when they belong to a batch of a full run, strike
     * that batch off, so the run is marked done once the last one lands.
     *
     * @param  array<string, array>  $records
     */
    public function store(array $records, ?string $batch = null): void
    {
        $this->locked(function () use ($records, $batch) {
            $payload = $this->payload();
            $payload['records'] = array_merge($payload['records'] ?? [], $records);

            $batches = $payload['batches'] ?? [];

            if ($batch !== null && ($payload['state'] ?? null) === self::STATE_ANALYZING && in_array($batch, $batches, true)) {
                $payload['batches'] = array_values(array_diff($batches, [$batch]));

                if ($payload['batches'] === []) {
                    $payload['state'] = self::STATE_READY;
                    $payload['analyzed_at'] = now()->toIso8601String();
                }
            }

            $this->put($payload);
        });
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

        Blink::forget(self::BLINK_KEY);
    }

    private function locked(callable $callback): void
    {
        Cache::lock('asset-usage-compression', 30)->block(15, function () use ($callback) {
            // Read fresh under the lock, not whatever this request saw earlier.
            Blink::forget(self::BLINK_KEY);

            $callback();
        });
    }
}
