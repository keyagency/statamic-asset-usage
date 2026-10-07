<?php

namespace KeyAgency\AssetUsage\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Compressor;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Usage\Containers;
use Statamic\Facades\Blink;

/**
 * Starts a full analysis: splits every compressible image in the enabled
 * containers into batches, so no single job has to decode thousands of
 * images within one timeout.
 */
class AnalyzeAllCompression implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public const BATCH_SIZE = 25;

    public $tries = 1;

    public function __construct(public bool $force = false) {}

    public function handle(): void
    {
        if (! Requirements::available()) {
            return;
        }

        $batches = self::batches();

        (new AnalysisStore)->markAnalyzing($batches, Compressor::make()->fingerprint());

        foreach ($batches as $id => $ids) {
            AnalyzeCompression::dispatch($ids, $id, $this->force);
        }
    }

    /**
     * Every compressible image, in batches keyed by an id of their own. Listed
     * fresh rather than through ids(): a queue worker runs one analysis after
     * another, and what Blink remembers lives as long as the worker does.
     *
     * @param  string|null  $handle  only the images in this container
     * @return array<string, string[]>
     */
    public static function batches(?string $handle = null): array
    {
        return self::list($handle)
            ->chunk(self::BATCH_SIZE)
            ->mapWithKeys(fn ($chunk) => [(string) Str::uuid() => $chunk->values()->all()])
            ->all();
    }

    /** Once per request: the status line and the overview both ask. */
    public static function ids(): Collection
    {
        return Blink::once('asset-usage-compressible-ids', fn () => self::list());
    }

    /**
     * Ids built from the plucked paths, which avoids hydrating every asset.
     */
    private static function list(?string $handle = null): Collection
    {
        return Containers::enabled()
            ->filter(fn ($container) => ! $handle || $container->handle() === $handle)
            ->flatMap(fn ($container) => $container->queryAssets()->pluck('path')
                ->filter(fn ($path) => $path && Compressor::supports(pathinfo($path, PATHINFO_EXTENSION)))
                ->map(fn ($path) => "{$container->handle()}::{$path}")
            )->values();
    }
}
