<?php

namespace KeyAgency\AssetUsage\Compression;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use Statamic\Facades\User;
use Statamic\Support\Str;

/**
 * Where the analysis stands, for the notices on the Tools page that say when
 * the numbers were made and what keeps them current.
 */
class Status
{
    public function __construct(
        private readonly AnalysisStore $store,
        private readonly string $fingerprint,
    ) {}

    /**
     * The driver can't be built, so there is nothing to count. Same shape as
     * toArray(), so the CP needs no special case beyond `available`.
     */
    public static function unavailable(): array
    {
        return [
            'enabled' => Settings::compressionEnabled(),
            'available' => false,
            'requirements' => self::requirements(),
            'analyzing' => false,
            'analyzed_at' => null,
            'never_analyzed' => false,
            'settings_changed' => false,
            'images' => 0,
            'unanalyzed' => 0,
            'compressible' => 0,
            'savable_bytes' => 0,
            'threshold' => Settings::compressionThreshold(),
            'sync_queue' => config('queue.default') === 'sync',
            'log' => self::compressionTotals(),
        ];
    }

    /** The exception message stays out: the CP never shows it, the CLI does. */
    private static function requirements(): array
    {
        return Arr::except(Requirements::check(), 'error');
    }

    /** What compressing saved, over the containers the current user can see. */
    private static function compressionTotals(): array
    {
        $log = new AssetLog;

        return $log->compressionTotals($log->visibleTo(User::current()));
    }

    /** The toArray() of a fresh Status, or unavailable() when the driver is missing. */
    public static function current(?Analyzer $analyzer = null): array
    {
        return Requirements::available() ? self::make($analyzer)->toArray() : self::unavailable();
    }

    public static function make(?Analyzer $analyzer = null): self
    {
        $analyzer ??= Analyzer::make();

        return new self($analyzer->store(), $analyzer->compressor()->fingerprint());
    }

    /**
     * Counted against the current settings but not the file version: telling
     * a replaced file apart would mean hydrating every asset, and a replace
     * queues its own analysis anyway.
     */
    public function toArray(): array
    {
        $meta = $this->store->meta();
        $records = array_filter($this->store->records(), fn (array $record) => ($record['settings'] ?? null) === $this->fingerprint);
        $ids = self::visibleIds();
        $threshold = Settings::compressionThreshold();

        $compressible = array_filter(
            array_intersect_key($records, array_flip($ids->all())),
            fn (array $record) => ($record['status'] ?? null) === CompressionResult::OK && ($record['savings'] ?? 0) >= $threshold
        );

        return [
            'enabled' => Settings::compressionEnabled(),
            'available' => true,
            'requirements' => self::requirements(),
            'analyzing' => $this->store->isAnalyzing(),
            'analyzed_at' => $meta['analyzed_at'],
            'never_analyzed' => $meta['analyzed_at'] === null && $records === [],
            'settings_changed' => $this->store->settingsChanged($this->fingerprint),
            'images' => $ids->count(),
            'unanalyzed' => $ids->reject(fn (string $id) => isset($records[$id]))->count(),
            'compressible' => count($compressible),
            'savable_bytes' => array_sum(array_map(fn (array $record) => $record['before_bytes'] - $record['after_bytes'], $compressible)),
            'threshold' => $threshold,
            'sync_queue' => config('queue.default') === 'sync',
            'log' => self::compressionTotals(),
        ];
    }

    /**
     * The images in the containers the current user can view, the same ones
     * the overview lists. Without a user (the console) that is all of them.
     */
    private static function visibleIds(): Collection
    {
        $ids = AnalyzeAllCompression::ids();
        $user = User::current();

        if (! $user || $user->isSuper()) {
            return $ids;
        }

        $viewable = Containers::enabled()
            ->filter(fn ($container) => $user->can('view', $container))
            ->mapWithKeys(fn ($container) => [$container->handle() => true]);

        return $ids->filter(fn (string $id) => isset($viewable[Str::before($id, '::')]))->values();
    }
}
