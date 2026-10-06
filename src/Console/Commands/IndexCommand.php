<?php

namespace KeyAgency\AssetUsage\Console\Commands;

use Illuminate\Console\Command;
use KeyAgency\AssetUsage\Console\Commands\Concerns\BuildsIndexWithProgress;
use KeyAgency\AssetUsage\Jobs\BuildIndex;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use Statamic\Console\RunsInPlease;

class IndexCommand extends Command
{
    use BuildsIndexWithProgress, RunsInPlease;

    protected $signature = 'statamic:asset-usage:index
        {--queue : Dispatch the rebuild to the queue instead of running it now}';

    protected $description = 'Rebuild the asset usage index by scanning all content for asset references';

    public function handle(): int
    {
        $containers = Containers::enabled();

        if ($containers->isEmpty()) {
            $this->components->error('No asset containers are enabled for this addon.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            // Same reason as the CP: the CP should say a rebuild is running before a worker gets to it.
            (new IndexStore)->markBuilding();

            BuildIndex::dispatch();

            $this->components->info('Rebuild dispatched to the queue.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Scanning content for references to assets in: %s',
            $containers->map->handle()->implode(', ')
        ));

        [$index, $scanned] = $this->buildIndexWithProgress();

        $used = count($index->usedAssetIds());
        $total = count(Containers::make()->assetIds());

        $this->newLine();
        $this->components->twoColumnDetail('Items scanned', (string) $scanned);
        $this->components->twoColumnDetail('Assets', (string) $total);
        $this->components->twoColumnDetail('Used', (string) $used);
        $this->components->twoColumnDetail('Unused', (string) ($total - $used));

        $this->newLine();
        $this->components->info('Usage index rebuilt.');

        return self::SUCCESS;
    }
}
