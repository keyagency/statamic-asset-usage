<?php

namespace KeyAgency\AssetUsage\Usage;

use KeyAgency\AssetUsage\Jobs\BuildIndex;

/**
 * Patches the stored index for a single item, so saving one entry doesn't mean
 * rescanning the site. Everything an item contributed before is dropped and
 * replaced by what it contributes now.
 *
 * On a fresh install there is no index to patch, so the first save builds the
 * whole thing instead. Recording only the saved item would make every other
 * asset look unused, and so deletable. Removals are still no-ops without an
 * index, because a build started then would find nothing they'd change.
 */
final class ItemUsageUpdater
{
    private ?ReferenceExtractor $extractor = null;

    public function __construct(private readonly IndexStore $store) {}

    public function update(Item ...$items): void
    {
        if ($items === []) {
            return;
        }

        if (! $this->store->exists()) {
            $this->store->markBuilding();

            BuildIndex::dispatch();

            return;
        }

        $extracted = [];

        foreach ($items as $item) {
            $extracted[] = [$item, $this->extractor()->extract($item->data)];
        }

        $this->store->mutate(function (UsageIndex $index) use ($extracted) {
            foreach ($extracted as [$item, $found]) {
                $index->put($item, $found);
            }
        });
    }

    public function forget(string ...$itemKeys): void
    {
        if (! $this->store->exists() || $itemKeys === []) {
            return;
        }

        $this->store->mutate(function (UsageIndex $index) use ($itemKeys) {
            foreach ($itemKeys as $itemKey) {
                $index->forget($itemKey);
            }
        });
    }

    /**
     * Drop whole groups of items at once, for a deletion that takes more than
     * the item it names with it.
     */
    public function forgetPrefixes(string ...$prefixes): void
    {
        if (! $this->store->exists() || $prefixes === []) {
            return;
        }

        $this->store->mutate(function (UsageIndex $index) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $index->forgetPrefix($prefix);
            }
        });
    }

    /**
     * Drop the usages of assets that are gone, so a deleted asset stops showing
     * up as used somewhere.
     */
    public function forgetAssets(string ...$assetIds): void
    {
        if (! $this->store->exists() || $assetIds === []) {
            return;
        }

        $this->store->mutate(function (UsageIndex $index) use ($assetIds) {
            foreach ($assetIds as $assetId) {
                $index->forgetAsset($assetId);
            }
        });
    }

    /**
     * Built lazily and reused: constructing it lists every file in every
     * container, which we'd rather not do for a save that touches no assets.
     */
    private function extractor(): ReferenceExtractor
    {
        return $this->extractor ??= new ReferenceExtractor(Containers::make());
    }
}
