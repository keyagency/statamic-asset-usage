<?php

namespace KeyAgency\AssetUsage\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Requirements;
use Statamic\Facades\Asset;
use Throwable;

/**
 * Test-compresses a batch of assets. Dispatched for a single upload, or as one
 * batch of a full run, which it then counts off when done, also when it fails,
 * so a run can't stay "analysing" forever because of one bad batch.
 */
class AnalyzeCompression implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 1;

    public $timeout = 900;

    /**
     * @param  string[]  $ids
     * @param  string|null  $batch  the batch of a full run this is, which it strikes off when done
     */
    public function __construct(
        public array $ids,
        public ?string $batch = null,
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        if (! Requirements::available()) {
            $this->failed();

            return;
        }

        $assets = collect($this->ids)->map(fn (string $id) => Asset::find($id))->filter();

        Analyzer::make()->analyze($assets, $this->force, $this->batch);
    }

    public function failed(?Throwable $exception = null): void
    {
        if ($this->batch !== null) {
            (new AnalysisStore)->store([], $this->batch);
        }
    }
}
