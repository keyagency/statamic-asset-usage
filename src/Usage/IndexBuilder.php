<?php

namespace KeyAgency\AssetUsage\Usage;

use Closure;

/**
 * Builds the whole usage index from scratch: one pass over every scannable
 * item, extracting asset references as it goes.
 */
final class IndexBuilder
{
    public function __construct(private readonly IndexStore $store) {}

    /**
     * @param  Closure|null  $onItem  called with each scanned Item, for progress reporting
     */
    public function build(?Closure $onItem = null): UsageIndex
    {
        $this->store->markBuilding();

        $containers = Containers::make();
        $extractor = new ReferenceExtractor($containers);
        $items = new Items($containers);

        $index = new UsageIndex;
        $scanned = 0;

        foreach ($items->all() as $item) {
            foreach ($extractor->extract($item->data) as $assetId => $fields) {
                foreach ($fields as $field) {
                    $index->add($assetId, $item->usage($field));
                }
            }

            $scanned++;

            if ($onItem) {
                $onItem($item);
            }
        }

        $this->store->write($index, ['items_scanned' => $scanned]);

        return $index;
    }
}
