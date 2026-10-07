<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Compression\Status;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Asset;
use Statamic\Support\Str;
use Throwable;

class AnalyzeCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:asset-usage:analyze
        {--container= : Only analyse images in one asset container}
        {--force : Analyse images again even when their result is still current}
        {--queue : Dispatch the analysis to the queue instead of running it now}';

    protected $description = 'Test-compress every image to see which ones can get smaller';

    public function handle(): int
    {
        if (! Settings::compressionEnabled()) {
            $this->components->error('Compression is disabled in config/statamic/asset-usage.php.');

            return self::FAILURE;
        }

        if (! Requirements::available()) {
            $this->components->error('The image driver Glide is configured with ('.Requirements::check()['driver'].') is not available: '.Requirements::check()['error']);

            return self::FAILURE;
        }

        $container = $this->option('container');

        if ($container && ! Containers::isEnabled($container)) {
            $this->components->error("The container [{$container}] is not enabled for this addon.");

            return self::FAILURE;
        }

        if ($container && $this->option('queue')) {
            $this->components->error('--queue analyses every container. Leave out --container to use it.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            AnalyzeAllCompression::dispatch((bool) $this->option('force'));

            $this->components->info('Analysis dispatched to the queue.');

            return self::SUCCESS;
        }

        $analyzer = Analyzer::make();
        $batches = AnalyzeAllCompression::batches($container);
        $total = array_sum(array_map('count', $batches));

        /*
         * One container is only part of the images, so the analysis as a whole
         * keeps its date, its settings and the results of the other containers.
         */
        if (! $container) {
            $analyzer->store()->markAnalyzing($batches, $analyzer->compressor()->fingerprint());
        }

        $this->components->info(sprintf('Analysing %d %s.', $total, Str::plural('image', $total)));

        // Shown from the start and moved on per image, not per batch: one batch can be a whole small site.
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $done = 0;
        $failed = 0;

        foreach ($batches as $batch => $ids) {
            try {
                $assets = collect($ids)->map(fn (string $id) => Asset::find($id))->filter();

                $analyzer->analyze($assets, (bool) $this->option('force'), $container ? null : $batch, fn () => $bar->advance());
            } catch (Throwable $e) {
                // Struck off anyway, so one bad batch can't leave the run "analysing".
                $container || $analyzer->store()->store([], $batch);
                report($e);
                $failed++;
            }

            // A missing asset or a failed batch still counts, so the bar ends full.
            $bar->setProgress($done += count($ids));
        }

        $bar->finish();
        $this->newLine(2);

        $status = Status::make($analyzer)->toArray();

        $this->components->twoColumnDetail('Images', (string) $status['images']);
        $this->components->twoColumnDetail("At least {$status['threshold']}% smaller", (string) $status['compressible']);
        $this->components->twoColumnDetail('Total saving', Str::fileSizeForHumans($status['savable_bytes'], 1));

        if ($failed) {
            $this->components->warn("{$failed} batches failed and were skipped; the errors are in the log.");
        }

        return self::SUCCESS;
    }
}
