<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\BackupFailed;
use KeyAgency\AssetUsage\Compression\CompressionService;
use KeyAgency\AssetUsage\Compression\FileChanged;
use KeyAgency\AssetUsage\Compression\NothingToCompress;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Log\Source;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Asset;
use Statamic\Support\Str;

/**
 * "Compress all" from the command line: the same images, replaced the same
 * way, with the original kept. The bar goes to stderr and is left out with
 * --json, so the result is the only output a script reads.
 */
class CompressCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:asset-usage:compress
        {--container= : Only compress images in one asset container}
        {--analyze : First analyse the images that have no current result, so they are included}
        {--dry-run : List the images that would be compressed without replacing anything}
        {--json : Output the result as JSON instead of a table}
        {--force : Compress without asking for confirmation}';

    protected $description = 'Replace every image that can get smaller with its compressed version, keeping the original';

    public function handle(): int
    {
        if ($error = $this->blocker()) {
            $this->components->error($error);

            return self::FAILURE;
        }

        if ($this->option('analyze') && ! $this->analyze()) {
            return self::FAILURE;
        }

        $analyzer = Analyzer::make();
        $ids = $this->images();
        $compressible = $analyzer->compressible($ids);
        $records = $analyzer->store()->records();

        if ($this->option('json') && $this->option('dry-run')) {
            $this->line(json_encode($compressible));

            return self::SUCCESS;
        }

        if (! $this->option('json')) {
            $this->describe($compressible, $records);
            $this->mentionUnanalyzed($ids, $records, $analyzer->compressor()->fingerprint());
        }

        if ($compressible === [] || $this->option('dry-run')) {
            return $this->option('json') ? $this->report([], [], 0) : self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm($this->confirmation(count($compressible)), false)) {
            $this->components->warn('Nothing was compressed.');

            return self::SUCCESS;
        }

        return $this->compress($compressible);
    }

    /** Why nothing can be compressed right now, or null when it can. */
    private function blocker(): ?string
    {
        if (! Settings::compressionEnabled()) {
            return 'Compression is disabled in config/statamic/asset-usage.php.';
        }

        if (! Requirements::available()) {
            return 'The image driver Glide is configured with ('.Requirements::check()['driver'].') is not available: '.Requirements::check()['error'];
        }

        if (($container = $this->option('container')) && ! Containers::isEnabled($container)) {
            return "The container [{$container}] is not enabled for this addon.";
        }

        if ($this->option('json') && ! $this->option('dry-run') && ! $this->option('force')) {
            return 'With --json there is no way to confirm. Pass --force to compress, or --dry-run to only list the images.';
        }

        // The same as in the CP: the list is about to change, and the analysis could overwrite what compressing records.
        if (Analyzer::make()->store()->isAnalyzing()) {
            return 'An analysis is running. Run this again once it has finished.';
        }

        return null;
    }

    /**
     * Only images without a current result are analysed, so a recent analysis
     * makes this quick, and only in the container being compressed.
     */
    private function analyze(): bool
    {
        $options = array_filter(['--container' => $this->option('container')]);

        $status = $this->option('json')
            ? $this->callSilently('statamic:asset-usage:analyze', $options)
            : $this->call('statamic:asset-usage:analyze', $options);

        $this->option('json') || $this->newLine();

        return $status === self::SUCCESS;
    }

    /**
     * Every image in the enabled containers, or in the one asked for.
     *
     * @return string[]
     */
    private function images(): array
    {
        $container = $this->option('container');

        return AnalyzeAllCompression::ids()
            ->filter(fn (string $id) => ! $container || Str::before($id, '::') === $container)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $ids
     */
    private function describe(array $ids, array $records): void
    {
        if ($ids === []) {
            $this->components->info('No images can get smaller.');

            return;
        }

        $this->table(
            ['Container', 'Path', 'Before', 'After', 'Saving'],
            array_map(fn (string $id) => [
                Str::before($id, '::'),
                Str::after($id, '::'),
                Str::fileSizeForHumans($records[$id]['before_bytes'], 1),
                Str::fileSizeForHumans($records[$id]['after_bytes'], 1),
                round($records[$id]['savings']).'%',
            ], $ids),
        );

        $count = count($ids);
        $bytes = array_sum(array_map(fn (string $id) => $records[$id]['before_bytes'] - $records[$id]['after_bytes'], $ids));

        $this->components->info(sprintf('%d %s can get %s smaller in total.', $count, Str::plural('image', $count), Str::fileSizeForHumans($bytes, 1)));

        $iccLost = count(array_filter($ids, fn (string $id) => $records[$id]['icc_lost'] ?? false));

        if ($iccLost) {
            $this->components->warn($iccLost === 1
                ? '1 of these images has an embedded colour profile that the compressed version does not keep, so its colours can shift slightly.'
                : "{$iccLost} of these images have an embedded colour profile that the compressed version does not keep, so their colours can shift slightly.");
        }
    }

    /**
     * Images that were never analysed, or with other settings, can't be told
     * apart from ones with nothing to gain, so say how many were left out.
     *
     * @param  string[]  $ids
     */
    private function mentionUnanalyzed(array $ids, array $records, string $fingerprint): void
    {
        $never = count(array_filter($ids, fn (string $id) => ! isset($records[$id])));
        $other = count(array_filter($ids, fn (string $id) => isset($records[$id]) && ($records[$id]['settings'] ?? null) !== $fingerprint));

        if ($never) {
            $this->components->warn($never === 1
                ? '1 image has not been analysed yet and is left out. Pass --analyze to include it.'
                : "{$never} images have not been analysed yet and are left out. Pass --analyze to include them.");
        }

        if ($other) {
            $this->components->warn(($other === 1
                ? '1 image was analysed with other settings and is left out. Pass --analyze to analyse it again.'
                : "{$other} images were analysed with other settings and are left out. Pass --analyze to analyse them again.")
                .' If the Control Panel shows them as current, it sees other settings than the command line, usually because only one of the two finds pngquant. Set pngquant_binary to its full path.');
        }
    }

    private function confirmation(int $count): string
    {
        $days = Settings::keepOriginalsDays();

        $this->components->info($days === null
            ? 'The originals are kept, so each image can be restored separately.'
            : "The originals are kept for {$days} days, so each image can be restored separately in that time.");

        return $count === 1
            ? 'Replace this image with its compressed version?'
            : "Replace these {$count} images with their compressed versions?";
    }

    /**
     * An image that can't be compressed is reported and skipped. A failed
     * backup ends the run, because every next image would fail the same way.
     *
     * @param  string[]  $ids
     */
    private function compress(array $ids): int
    {
        $service = CompressionService::make();
        $compressed = [];
        $skipped = [];
        $saved = 0;
        $halted = false;

        $bar = $this->option('json') ? null : $this->output->createProgressBar(count($ids));
        $bar?->start();

        foreach ($ids as $id) {
            try {
                $result = $this->compressOne($service, $id);
            } catch (BackupFailed $e) {
                report($e);
                $skipped[] = ['id' => $id, 'reason' => 'The original could not be kept, so the image was not compressed. Check the free disk space and try again.'];
                $halted = true;

                break;
            }

            if (is_string($result)) {
                $skipped[] = ['id' => $id, 'reason' => $result];
            } else {
                $compressed[] = $id;
                $saved += $result['before_bytes'] - $result['after_bytes'];
            }

            $bar?->advance();
        }

        $bar?->finish();

        return $this->summarise($compressed, $skipped, $saved, $halted);
    }

    /**
     * What the run did, as JSON or as text. The skipped images are listed
     * after the bar, which a warning in between would break up.
     *
     * @param  string[]  $compressed
     * @param  array<int, array{id: string, reason: string}>  $skipped
     */
    private function summarise(array $compressed, array $skipped, int $saved, bool $halted): int
    {
        if ($this->option('json')) {
            $this->report($compressed, $skipped, $saved);

            return $halted ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine(2);

        foreach ($skipped as $skip) {
            $this->components->warn(Str::after($skip['id'], '::').': '.$skip['reason']);
        }

        $count = count($compressed);

        $this->components->info(sprintf('%d %s compressed, %s smaller in total.', $count, Str::plural('image', $count), Str::fileSizeForHumans($saved, 1)));

        if ($halted) {
            $this->components->error('Stopped, because every next image would fail the same way.');
        }

        return $halted ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Against the current version, the same as a batch on the Compression page:
     * there was no preview to keep to, and the size on the disk still catches a
     * file Statamic's meta is behind.
     *
     * @return array|string the record of the file before it was replaced, or why it was skipped
     *
     * @throws BackupFailed
     */
    private function compressOne(CompressionService $service, string $id): array|string
    {
        if (! ($asset = Asset::find($id)) || ! Analyzer::applies($asset)) {
            return 'That asset no longer exists.';
        }

        try {
            return $service->compress($asset, Analyzer::version($asset), source: Source::CLI);
        } catch (FileChanged) {
            return 'The file on the disk differs from what Statamic has on record, so it was left alone.';
        } catch (NothingToCompress) {
            return 'Compressing would not make this file any smaller.';
        }
    }

    /**
     * @param  string[]  $compressed
     */
    private function report(array $compressed, array $skipped, int $saved): int
    {
        $this->line(json_encode(['compressed' => $compressed, 'skipped' => $skipped, 'saved_bytes' => $saved]));

        return self::SUCCESS;
    }
}
