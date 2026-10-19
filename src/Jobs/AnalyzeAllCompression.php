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
use KeyAgency\AssetUsage\Support\Run;
use KeyAgency\AssetUsage\Support\Runs;
use KeyAgency\AssetUsage\Usage\Containers;
use Statamic\Facades\Blink;
use Throwable;

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

    /** @param  string|null  $run  the run started when this was dispatched */
    public function __construct(public bool $force = false, public ?string $run = null) {}

    /**
     * Flagged as analysing before anything is queued, so the CP says so right
     * away instead of only once a worker picks it up.
     */
    public static function start(bool $force = false): void
    {
        $run = (new AnalysisStore)->markPreparing(Compressor::make()->fingerprint());

        Runs::dispatch(new self($force, $run));
    }

    public function handle(): void
    {
        // Stopped, or replaced by a newer run, before a worker got to it.
        if ($this->run !== null && Run::analysis()->isOver($this->run, (new AnalysisStore)->cancel(...))) {
            return;
        }

        if (! Requirements::available()) {
            $this->failed();

            return;
        }

        Runs::raiseTimeLimit();

        $batches = self::batches();

        $run = (new AnalysisStore)->markAnalyzing($batches, Compressor::make()->fingerprint(), $this->run);

        foreach ($batches as $id => $ids) {
            AnalyzeCompression::dispatch($ids, $id, $this->force, $run);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        if ($this->run === null || ! Run::analysis()->isCurrent($this->run)) {
            return;
        }

        Run::analysis()->fail($this->run, $exception?->getMessage());
        (new AnalysisStore)->cancel();
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
