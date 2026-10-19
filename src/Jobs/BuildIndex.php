<?php

namespace KeyAgency\AssetUsage\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KeyAgency\AssetUsage\Support\Run;
use KeyAgency\AssetUsage\Support\Runs;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use Throwable;

/**
 * Rebuilds the whole index off the request. Without a queue worker it runs
 * after the response instead (see Runs::dispatch()).
 */
class BuildIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 1;

    public $timeout = 900;

    /** @param  string|null  $run  the run started when this was dispatched */
    public function __construct(public ?string $run = null) {}

    /** Starts a run and hands it to a worker. */
    public static function start(): void
    {
        Runs::dispatch(new self(IndexBuilder::startRun(new IndexStore)));
    }

    public function handle(): void
    {
        Runs::raiseTimeLimit();

        (new IndexBuilder(new IndexStore))->build(run: $this->run);
    }

    /** Also called after a worker timed out, so the CP can say why. */
    public function failed(?Throwable $exception = null): void
    {
        if ($this->run === null || ! Run::index()->isCurrent($this->run)) {
            return;
        }

        Run::index()->fail($this->run, $exception?->getMessage());
        (new IndexStore)->cancelBuilding();
    }
}
