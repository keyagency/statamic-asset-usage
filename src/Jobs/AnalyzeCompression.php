<?php

namespace KeyAgency\AssetUsage\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Support\Run;
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
     * @param  string|null  $run  that full run, which this batch reports its progress to
     */
    public function __construct(
        public array $ids,
        public ?string $batch = null,
        public bool $force = false,
        public ?string $run = null,
    ) {}

    public function handle(): void
    {
        $runs = Run::analysis();

        // Stopped before this batch's turn came.
        if ($this->run !== null && $runs->isOver($this->run, (new AnalysisStore)->cancel(...))) {
            return;
        }

        if (! Requirements::available()) {
            $this->failed();

            return;
        }

        $assets = collect($this->ids)->map(fn (string $id) => Asset::find($id))->filter();

        Analyzer::make()->analyze($assets, $this->force, $this->batch, $this->run === null ? null : $this->reporter($runs, $assets->count()));

        // Asked to stop halfway: what it found is saved, so the run can end now.
        if ($this->run !== null) {
            $runs->isOver($this->run, (new AnalysisStore)->cancel(...));
        }
    }

    /**
     * Adds to the run's count about once a second rather than per image:
     * several workers share it, and each addition takes the lock.
     */
    private function reporter(Run $runs, int $left): callable
    {
        $pending = 0;
        $last = microtime(true);

        return function () use ($runs, &$pending, &$last, &$left) {
            $pending++;
            $left--;

            if ($left > 0 && microtime(true) - $last < 1) {
                return true;
            }

            $last = microtime(true);
            $current = $runs->advance($this->run, $pending);
            $pending = 0;

            return $current;
        };
    }

    public function failed(?Throwable $exception = null): void
    {
        if ($this->batch !== null) {
            (new AnalysisStore)->store([], $this->batch);
        }
    }
}
