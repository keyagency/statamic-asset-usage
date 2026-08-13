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
 * where filters get applied — the folder view has none.
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

        $paths = $this->paths($container, ($values['usage'] ?? 'unused') === 'used');

        /*
         * Statamic reads whereIn() with an empty array as no constraint, which
         * would turn "nothing matches" into "everything matches".
         */
        $query->whereIn('path', $paths ?: ['__asset-usage-no-matches__']);
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
     * @return string[]
     */
    private function paths(string $container, bool $used): array
    {
        $store = new IndexStore;

        if ($store->isStale()) {
            return [];
        }

        $index = $store->indexOrEmpty();

        return collect(Containers::make()->assetIds())
            ->filter(fn (string $id) => Reference::parse($id)?->container === $container)
            ->filter(fn (string $id) => $index->isUsed($id) === $used)
            ->map(fn (string $id) => Reference::parse($id)->path)
            ->values()
            ->all();
    }
}
