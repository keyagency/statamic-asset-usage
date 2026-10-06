<?php

namespace KeyAgency\AssetUsage\Usage;

use KeyAgency\AssetUsage\Listeners\InjectUsageField;
use Statamic\Assets\QueryBuilder as StacheAssetQuery;
use Statamic\Facades\Asset;
use Statamic\Facades\Stache;
use Statamic\Stache\Indexes\Value;

/**
 * What sorting the "Used" column in the asset browser rests on. The Stache
 * sorts on an index it builds from each asset's queryable value, which the
 * field answers with its usage count, and it caches that index. So it is
 * dropped whenever the usage changes, and rebuilt on the next sort.
 */
final class SortIndex
{
    /**
     * The eloquent driver would order by a database column that doesn't
     * exist, so the column only sorts on the Stache.
     */
    public static function supported(): bool
    {
        return Asset::query() instanceof StacheAssetQuery;
    }

    public static function clear(): void
    {
        if (! self::supported()) {
            return;
        }

        $resolved = app('stache.indexes');

        foreach (Containers::enabled() as $container) {
            $store = Stache::store('assets')->store($container->handle());
            $key = $store->key().'.'.InjectUsageField::HANDLE;

            ($resolved->has($key) ? $resolved->get($key) : new Value($store, InjectUsageField::HANDLE))->clear();
        }
    }
}
