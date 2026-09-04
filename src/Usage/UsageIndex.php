<?php

namespace KeyAgency\AssetUsage\Usage;

use KeyAgency\AssetUsage\Support\Defaults;

/**
 * The in-memory usage index: which assets are used where, plus a reverse map
 * from item to assets. The reverse map is what makes an incremental update
 * possible. Without it, dropping one entry's contributions would mean walking
 * every asset's usage list.
 */
final class UsageIndex
{
    /** @var array<string, Usage[]> asset id => usages */
    private array $usages = [];

    /** @var array<string, string[]> item key => asset ids */
    private array $items = [];

    public function add(string $assetId, Usage $usage): void
    {
        $this->usages[$assetId] ??= [];

        if (count($this->usages[$assetId]) < Defaults::$maxUsagesPerAsset) {
            $this->usages[$assetId][] = $usage;
        }

        $itemKey = $usage->itemKey();
        $this->items[$itemKey] ??= [];

        if (! in_array($assetId, $this->items[$itemKey], true)) {
            $this->items[$itemKey][] = $assetId;
        }
    }

    /**
     * Record the usages one item contributes, replacing whatever it contributed
     * before.
     *
     * @param  array<string, string[]>  $found  asset id => dotted field paths
     */
    public function put(Item $item, array $found): void
    {
        $this->forget($item->itemKey());

        foreach ($found as $assetId => $fields) {
            foreach ($fields as $field) {
                $this->add($assetId, $item->usage($field));
            }
        }
    }

    /**
     * Drop everything one item contributed.
     */
    public function forget(string $itemKey): void
    {
        foreach ($this->items[$itemKey] ?? [] as $assetId) {
            $remaining = array_values(array_filter(
                $this->usages[$assetId] ?? [],
                fn (Usage $usage) => $usage->itemKey() !== $itemKey,
            ));

            if ($remaining === []) {
                unset($this->usages[$assetId]);
            } else {
                $this->usages[$assetId] = $remaining;
            }
        }

        unset($this->items[$itemKey]);
    }

    /**
     * Drop everything the items under one key prefix contributed, for when a
     * single deletion takes a group of items with it.
     */
    public function forgetPrefix(string $prefix): void
    {
        foreach (array_keys($this->items) as $itemKey) {
            if (str_starts_with($itemKey, $prefix)) {
                $this->forget($itemKey);
            }
        }
    }

    /**
     * Drop an asset's usages, for when the asset itself is gone.
     */
    public function forgetAsset(string $assetId): void
    {
        unset($this->usages[$assetId]);

        foreach ($this->items as $itemKey => $assetIds) {
            if (($position = array_search($assetId, $assetIds, true)) !== false) {
                unset($this->items[$itemKey][$position]);

                $this->items[$itemKey] = array_values($this->items[$itemKey]);

                if ($this->items[$itemKey] === []) {
                    unset($this->items[$itemKey]);
                }
            }
        }
    }

    /**
     * @return Usage[]
     */
    public function for(string $assetId): array
    {
        return $this->usages[$assetId] ?? [];
    }

    public function countFor(string $assetId): int
    {
        return count($this->usages[$assetId] ?? []);
    }

    public function isUsed(string $assetId): bool
    {
        return isset($this->usages[$assetId]);
    }

    /**
     * The sites an asset is used in. Items without a site (assets, users, form
     * submissions) contribute a null, which the CP renders as "all sites".
     *
     * @return array<int, string|null>
     */
    public function sitesFor(string $assetId): array
    {
        $sites = [];

        foreach ($this->for($assetId) as $usage) {
            if (! in_array($usage->site, $sites, true)) {
                $sites[] = $usage->site;
            }
        }

        return $sites;
    }

    /**
     * @return string[]
     */
    public function usedAssetIds(): array
    {
        return array_keys($this->usages);
    }

    public function itemCount(): int
    {
        return count($this->items);
    }

    public function toArray(): array
    {
        return [
            'usages' => array_map(
                fn (array $usages) => array_map(fn (Usage $usage) => $usage->toArray(), $usages),
                $this->usages,
            ),
            'items' => $this->items,
        ];
    }

    public static function fromArray(array $payload): self
    {
        $index = new self;

        $index->usages = array_map(
            fn (array $usages) => array_map(fn (array $usage) => Usage::fromArray($usage), $usages),
            $payload['usages'] ?? [],
        );

        $index->items = $payload['items'] ?? [];

        return $index;
    }
}
