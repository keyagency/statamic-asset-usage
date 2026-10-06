<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Compression\Compressor;
use KeyAgency\AssetUsage\Compression\ImageManagers;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Reference;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Asset;
use Statamic\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reports what the addon sees per container, next to what the asset browser
 * sees. The two read the same listing through different caches, so when the
 * Tools page is missing assets this is what tells you where they drop out.
 */
class DoctorCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:asset-usage:doctor
        {--folders : Also list the per-folder file counts}';

    protected $description = 'Diagnose what the addon sees per asset container';

    public function handle(): int
    {
        $containers = Containers::enabled();

        if ($containers->isEmpty()) {
            $this->components->error('No asset containers are enabled for this addon.');

            return self::FAILURE;
        }

        $store = new IndexStore;
        $index = $store->indexOrEmpty();
        $unimported = false;

        $this->components->twoColumnDetail('Usage data', $store->exists()
            ? ($store->isStale() ? '<comment>out of date</comment>' : 'up to date')
            : '<comment>not built yet</comment>');
        $this->components->twoColumnDetail('Stache watcher', config('statamic.stache.watcher') ? 'on' : 'off (listings are cached)');
        $this->components->twoColumnDetail('Scanned types', implode(', ', Settings::scannedTypes()));
        $this->reportUnconfiguredTypes();
        $this->reportCompression();
        $this->newLine();

        foreach ($containers as $container) {
            $paths = $container->queryAssets()->pluck('path')->filter()->values();
            $nested = $paths->filter(fn (string $path) => str_contains($path, '/'));

            /*
             * Statamic's own file listing, which this addon deliberately does not
             * use: the eloquent driver reports only the container root there.
             */
            $listed = $container->files()->count();

            $this->components->info("Container: {$container->handle()}");
            $this->components->twoColumnDetail('Disk', (string) $container->diskHandle());
            $this->components->twoColumnDetail('Container class', $container::class);
            $this->components->twoColumnDetail('Listing class', $container->contents()::class);

            $onDisk = $this->rawListing($container);

            $this->components->twoColumnDetail(
                'Asset query (what this addon indexes)',
                sprintf('%d files (%s nested)', $paths->count(), $this->highlight($nested->count()))
            );
            $this->components->twoColumnDetail('Container listing files()', $this->compare($listed, $paths->count()));
            $this->components->twoColumnDetail('Folders in the listing', (string) $container->assetFolders()->count());

            if ($onDisk !== null && ($orphaned = $onDisk->diff($paths)->count())) {
                $this->components->twoColumnDetail('<comment>On disk but not known as an asset</comment>', (string) $orphaned);
                $unimported = true;
            }

            $missing = $paths
                ->reject(fn (string $path) => (bool) Asset::find("{$container->handle()}::{$path}"))
                ->count();

            $this->components->twoColumnDetail('listed but not resolvable as an asset', $this->highlight($missing, healthy: 0));

            $indexed = collect($index->usedAssetIds())
                ->filter(fn (string $id) => Reference::parse($id)?->container === $container->handle())
                ->count();

            $this->components->twoColumnDetail('recorded as used in the usage data', (string) $indexed);

            if ($this->option('folders')) {
                $this->newLine();
                $this->perFolder($paths);
            }

            $this->newLine();
        }

        $this->components->info('Rows on the Tools page come from the asset query. A lower "Container listing" number is expected on the eloquent driver and harmless.');

        if ($unimported) {
            $this->components->warn('Some files on disk are not known as assets, so nothing (not this addon, not the asset browser) can report on them. On the eloquent driver, `php please eloquent:import-assets` brings them in.');
        }

        return self::SUCCESS;
    }

    /**
     * The driver is the one Glide renders with, read from Glide itself, so a
     * custom driver shows up as what it really is.
     */
    private function reportCompression(): void
    {
        if (! Settings::compressionEnabled()) {
            $this->components->twoColumnDetail('Compression', 'off');

            return;
        }

        try {
            $manager = ImageManagers::glide();
            $driver = ImageManagers::driver($manager);

            $this->components->twoColumnDetail('Image driver (from Glide)', $driver ? $driver::class : $manager::class);

            if (ImageManagers::countsAgainstMemoryLimit($manager)) {
                $this->components->twoColumnDetail('memory_limit (limits GD)', (string) ini_get('memory_limit'));
            }
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Image driver (from Glide)', '<error>'.$e->getMessage().'</error>');
        }

        if ($unsupported = Requirements::check()['unsupported_formats']) {
            $this->components->twoColumnDetail('<comment>Formats the driver cannot handle</comment>', implode(', ', $unsupported));
        }

        $pngquant = Compressor::findPngquant();

        $this->components->twoColumnDetail('pngquant', $pngquant
            ? trim($pngquant.' '.$this->pngquantVersion($pngquant))
            : '<comment>not found, PNGs are only resized and saved losslessly</comment>');

        $analyzedAt = (new AnalysisStore)->meta()['analyzed_at'];

        $this->components->twoColumnDetail('Compression analysed', $analyzedAt ?? '<comment>never</comment>');
        $this->components->twoColumnDetail('Kept originals', Str::fileSizeForHumans((new Backups)->totalBytes(), 1));
    }

    private function pngquantVersion(string $binary): string
    {
        try {
            $process = new Process([$binary, '--version']);
            $process->run();

            return $process->isSuccessful() ? '('.trim($process->getOutput()).')' : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A published config replaces `scanned_types` as a whole, so a config
     * written before a type existed leaves that type switched off without ever
     * saying so.
     */
    private function reportUnconfiguredTypes(): void
    {
        $configured = config('statamic.asset-usage.scanned_types');

        if (! is_array($configured)) {
            return;
        }

        $missing = array_diff(Settings::SCANNABLE_TYPES, array_keys($configured));

        if ($missing === []) {
            return;
        }

        $this->components->warn(sprintf(
            'These types are not in your config, so nothing of theirs is scanned: %s. Add them to `scanned_types` in config/statamic/asset-usage.php, then refresh the usage data.',
            implode(', ', $missing)
        ));
    }

    /**
     * The paths the filesystem itself reports, bypassing Statamic entirely. When
     * the nested files are missing here too, the cause is the disk or its adapter
     * rather than anything above it.
     *
     * @return Collection<int, string>|null null when the disk can't be listed
     */
    private function rawListing($container): ?Collection
    {
        try {
            $disk = $container->disk()->filesystem();

            $this->components->twoColumnDetail('Disk adapter', $disk->getAdapter()::class);

            $listing = collect($disk->getDriver()->listContents('/', true)->toArray())
                /** Statamic's own metadata, files and containing folders alike. */
                ->reject(fn ($item) => in_array('.meta', explode('/', $item->path()), true));

            $files = $listing->filter(fn ($item) => $item->isFile())->map->path();
            $nested = $files->filter(fn (string $path) => str_contains($path, '/'));

            $this->components->twoColumnDetail(
                'Raw filesystem listing',
                sprintf(
                    '%d files (%s nested), %d dirs',
                    $files->count(),
                    $this->highlight($nested->count()),
                    $listing->count() - $files->count(),
                )
            );

            return $files->values();
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Raw filesystem listing', '<comment>'.$e->getMessage().'</comment>');

            return null;
        }
    }

    private function perFolder($paths): void
    {
        $counts = $paths
            ->groupBy(fn (string $path) => str_contains($path, '/') ? dirname($path) : '(root)')
            ->map->count()
            ->sortKeys();

        $this->table(['Folder', 'Files'], $counts->map(fn ($count, $folder) => [$folder, $count])->values()->all());
    }

    /** Zero where a number is expected is the interesting case, so make it stand out. */
    private function highlight(int $count, int $healthy = 1): string
    {
        return $count < $healthy ? "<comment>{$count}</comment>" : (string) $count;
    }

    private function compare(int $listed, int $indexed): string
    {
        return $listed === $indexed
            ? (string) $listed
            : "<comment>{$listed}</comment> (the query finds {$indexed})";
    }
}
