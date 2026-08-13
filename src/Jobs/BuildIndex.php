<?php

namespace KeyAgency\AssetUsage\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;

/**
 * Rebuilds the whole index off the request. Under the `sync` connection this
 * runs inline, which is the graceful fallback for sites without a worker.
 */
class BuildIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 1;

    public $timeout = 900;

    public function handle(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }
}
