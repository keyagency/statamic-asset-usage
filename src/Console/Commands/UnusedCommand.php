<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Unused;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Asset;
use Statamic\Support\Str;

class UnusedCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:asset-usage:unused
        {--container= : Only look at one asset container}
        {--older-than= : Only list assets last modified more than this many days ago}
        {--ignore=* : Filename patterns to leave out, e.g. "*.pdf" (on top of the config)}
        {--fresh : Rebuild the usage index first}
        {--json : Output the paths as JSON instead of a table}
        {--delete : Delete the listed assets}
        {--force : Delete without asking for confirmation}';

    protected $description = 'List the assets nothing in your content refers to, and optionally delete them';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->components->task('Rebuilding the usage index', fn () => (new IndexBuilder(new IndexStore))->build());
        }

        $store = new IndexStore;

        if ($store->isStale()) {
            $this->components->error(
                $store->exists()
                    ? __('asset-usage::messages.errors.stale_index')
                    : 'No usage index yet. Run `php please asset-usage:index` first, or pass --fresh.'
            );

            return self::FAILURE;
        }

        if (($container = $this->option('container')) && ! Containers::includes($container)) {
            $this->components->error("The container [{$container}] is not enabled for this addon.");

            return self::FAILURE;
        }

        $unused = Unused::make($store);
        $assets = $this->assets($unused, $container);

        if ($assets === []) {
            $this->components->info('Every asset is used somewhere.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode(array_map(fn ($asset) => $asset->id(), $assets)));

            return self::SUCCESS;
        }

        $this->table(
            ['Container', 'Path', 'Size', 'Last modified'],
            array_map(fn ($asset) => [
                $asset->container()->handle(),
                $asset->path(),
                Str::fileSizeForHumans($asset->size(), 0),
                $asset->lastModified()->diffForHumans(),
            ], $assets),
        );

        $this->components->info(sprintf('%d unused %s.', count($assets), Str::plural('asset', count($assets))));
        $this->components->warn(__('asset-usage::messages.not_scanned'));

        if (! $this->option('delete')) {
            return self::SUCCESS;
        }

        return $this->delete($assets, $unused);
    }

    /**
     * The unused assets, hydrated and filtered by the command's own options.
     *
     * @return \Statamic\Contracts\Assets\Asset[]
     */
    private function assets(Unused $unused, ?string $container): array
    {
        $olderThan = $this->option('older-than') !== null ? (int) $this->option('older-than') : null;
        $patterns = (array) $this->option('ignore');

        return collect($unused->ids($container))
            ->map(fn (string $id) => Asset::find($id))
            ->filter()
            /*
             * The config's minimum age is a floor the command can raise but not
             * lower, so a --older-than of 0 can't override it.
             */
            ->reject(fn ($asset) => $unused->isTooNew($asset))
            ->reject(fn ($asset) => $olderThan !== null && $asset->lastModified()->greaterThan(now()->subDays($olderThan)))
            ->reject(fn ($asset) => $this->matchesAny($asset->path(), $patterns))
            ->sortBy(fn ($asset) => $asset->id())
            ->values()
            ->all();
    }

    /**
     * @param  \Statamic\Contracts\Assets\Asset[]  $assets
     */
    private function delete(array $assets, Unused $unused): int
    {
        if (! $this->option('force') && ! $this->confirm(sprintf('Permanently delete these %d files?', count($assets)), false)) {
            $this->components->warn(__('asset-usage::messages.delete.none'));

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($assets as $asset) {
            /*
             * Re-checked right before deleting: the index could have been
             * patched by a content save while the operator was reading the list.
             */
            if ($blocker = $unused->blocker($asset)) {
                $this->components->warn("{$asset->id()}: {$blocker}");

                continue;
            }

            $asset->delete();
            $deleted++;
        }

        $this->newLine();
        $this->components->info(sprintf('%d %s deleted.', $deleted, Str::plural('asset', $deleted)));

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }

        return false;
    }
}
