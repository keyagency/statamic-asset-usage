<?php

namespace KeyAgency\AssetUsage\Query\Scopes\Filters;

use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Reference;
use Statamic\Query\Scopes\Filter;

/**
 * A Used / Unused filter in the asset browser.
 *
 * The usage index isn't part of the asset query, so the filter resolves the
 * matching paths first and constrains the query to them. Statamic switches the
 * browser to its flat search endpoint as soon as a filter is active, which is
 * where filters get applied, and the folder view has none.
 */
class AssetUsage extends Filter
{
    public static function title()
    {
        return __('asset-usage::messages.filters.usage');
    }

    public function fieldItems()
    {
        return [
            'usage' => [
                'type' => 'radio',
                'options' => [
                    'used' => __('asset-usage::messages.filters.used'),
                    'unused' => __('asset-usage::messages.filters.unused'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        $container = $this->context['container'] ?? null;

        if (! $container || ! Containers::includes($container)) {
            return;
        }

        [$used, $unused] = $this->pathsByUsage($container);

        [$wanted, $others] = ($values['usage'] ?? 'unused') === 'used'
            ? [$used, $unused]
            : [$unused, $used];

        /*
         * The eloquent driver binds one parameter per path and databases cap how
         * many a query may have, so the shorter list goes into the query. Both
         * lists come from the container's own asset query, so leaving out the
         * others is the same as keeping the wanted ones.
         */
        if (count($others) < count($wanted)) {
            $query->whereNotIn('path', $others);

            return;
        }

        /*
         * Statamic reads whereIn() with an empty array as no constraint, which
         * would turn "nothing matches" into "everything matches".
         */
        $query->whereIn('path', $wanted ?: ['__asset-usage-no-matches__']);
    }

    public function badge($values)
    {
        return __('asset-usage::messages.filters.usage').': '.match ($values['usage'] ?? null) {
            'used' => __('asset-usage::messages.filters.used'),
            default => __('asset-usage::messages.filters.unused'),
        };
    }

    public function visibleTo($key)
    {
        return $key === 'assets'
            && Containers::includes($this->context['container'] ?? '')
            && ! (new IndexStore)->isStale();
    }

    /**
     * The container's paths, split into used and unused.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function pathsByUsage(string $container): array
    {
        $store = new IndexStore;

        if ($store->isStale()) {
            return [[], []];
        }

        $index = $store->indexOrEmpty();
        $used = [];
        $unused = [];

        foreach (Containers::make()->assetIds() as $id) {
            $reference = Reference::parse($id);

            if ($reference?->container !== $container) {
                continue;
            }

            if ($index->isUsed($id)) {
                $used[] = $reference->path;
            } else {
                $unused[] = $reference->path;
            }
        }

        return [$used, $unused];
    }
}
