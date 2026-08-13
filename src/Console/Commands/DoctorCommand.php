<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Reference;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Asset;

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
            $this->components->error(__('asset-usage::messages.no_containers'));

            return self::FAILURE;
        }

        $store = new IndexStore;
        $index = $store->indexOrEmpty();
        $unimported = false;

        $this->components->twoColumnDetail('Usage data', $store->exists()
            ? ($store->isStale() ? '<comment>out of date</comment>' : 'up to date')
            : '<comment>not built yet</comment>');
        $this->components->twoColumnDetail('Stache watcher', config('statamic.stache.watcher') ? 'on' : 'off (listings are cached)');
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
                'Asset query — what this addon indexes',
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
            $this->components->warn('Some files on disk are not known as assets, so nothing — not this addon, not the asset browser — can report on them. On the eloquent driver, `php please eloquent:import-assets` brings them in.');
        }

        return self::SUCCESS;
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
        } catch (\Throwable $e) {
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
