<?php

namespace KeyAgency\AssetUsage\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Compression\CompressionResult;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Support\ImageDensity;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\Reference;
use KeyAgency\AssetUsage\Usage\UsageIndex;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;
use Statamic\Support\Str;

/**
 * Which assets the overview lists and in what order, shared by the list and
 * its PDF so the two can't drift apart on what the filters mean.
 */
trait ListsAssets
{
    /** The columns the overview can be sorted by. The first one is the default. */
    private const SORTS = ['path', 'size', 'resolution', 'dpi', 'savings', 'last_modified', 'usage'];

    /** Built on first use; a request only needs it for the compression column. */
    private ?Analyzer $analyzer = null;

    /**
     * The ids the overview shows for the request's filters, sorted.
     *
     * @return string[]
     */
    private function listedIds(Request $request, Containers $containers, UsageIndex $index): array
    {
        $ids = $this->filter($request, $containers, $index);

        if (in_array($compression = $request->input('compression'), ['all', 'compressible', 'compressed'], true)) {
            $ids = $this->byCompression($ids, $compression);
        }

        return $this->sort(
            $ids,
            $request->input('sort'),
            $request->input('order') === 'desc',
            $index
        );
    }

    /**
     * The asset ids matching the current filters, in no particular order.
     *
     * @return string[]
     */
    private function filter(Request $request, Containers $containers, UsageIndex $index, ?string $usage = null): array
    {
        $container = $request->input('container');
        $usage ??= $request->input('usage', 'all');
        $site = $request->input('site');
        $search = Str::lower(trim((string) $request->input('search')));

        $viewable = $containers->all()
            ->filter(fn ($container) => $this->userCanView($container))
            ->map->handle()
            ->all();

        $ids = array_filter($containers->assetIds(), function (string $id) use ($container, $usage, $site, $search, $index, $viewable) {
            $reference = Reference::parse($id);

            if (! $reference || ! in_array($reference->container, $viewable, true)) {
                return false;
            }

            if ($container && $reference->container !== $container) {
                return false;
            }

            if ($search !== '' && ! str_contains(Str::lower($reference->path), $search)) {
                return false;
            }

            $used = $index->isUsed($id);

            if ($usage === 'used' && ! $used) {
                return false;
            }

            if ($usage === 'unused' && $used) {
                return false;
            }

            /*
             * Filtering by site only narrows down used assets: an unused asset
             * belongs to no site at all, so it can't match one.
             */
            if ($site) {
                return in_array($site, $index->sitesFor($id), true);
            }

            return true;
        });

        return array_values($ids);
    }

    /**
     * Order a filtered set of ids. Path order is applied first, so that it is
     * also the tie-breaker within equal values, because usort is stable.
     *
     * @param  string[]  $ids
     * @return string[]
     */
    private function sort(array $ids, ?string $sort, bool $descending, UsageIndex $index): array
    {
        if (! in_array($sort, self::SORTS, true)) {
            $sort = self::SORTS[0];
        }

        // Parsed once up front: a comparator runs n log n times.
        $paths = array_combine($ids, array_map(fn (string $id) => $this->pathFor($id), $ids));

        usort($ids, fn (string $a, string $b) => strnatcasecmp($paths[$a], $paths[$b]));

        if ($sort === 'path') {
            return $descending ? array_reverse($ids) : $ids;
        }

        // Lower groups go first in either direction; only the saving uses them.
        $groups = [];

        $values = match ($sort) {
            'usage' => array_combine($ids, array_map(fn (string $id) => $index->countFor($id), $ids)),
            'size' => $this->assetValues($ids, fn ($asset) => $asset->size()),
            'last_modified' => $this->assetValues($ids, fn ($asset) => $asset->lastModified()->timestamp),
            'resolution' => $this->assetValues($ids, fn ($asset) => $asset->isImage() && $asset->width() ? $asset->width() * $asset->height() : null),
            'dpi' => $this->assetValues($ids, fn ($asset) => $asset->isImage() ? ImageDensity::for($asset) : null),
            'savings' => $this->savingsValues($ids, $groups),
        };

        // Assets without a value, such as the resolution of a PDF, go last in either direction.
        usort($ids, function (string $a, string $b) use ($values, $groups, $descending) {
            if ($group = ($groups[$a] ?? 0) <=> ($groups[$b] ?? 0)) {
                return $group;
            }

            $first = $values[$a] ?? null;
            $second = $values[$b] ?? null;

            if ($first === null || $second === null) {
                return ($first === null) <=> ($second === null);
            }

            return $descending ? $second <=> $first : $first <=> $second;
        });

        return $ids;
    }

    /**
     * Unlike usage and path these have to hydrate the assets. The query can't
     * sort or pluck by date or size on both drivers: the Stache orders
     * `last_modified` wrongly, and the eloquent driver's pluck skips the mapping
     * to its `meta` column.
     *
     * Whole containers are fetched rather than narrowed with `whereIn`, which on
     * the eloquent driver means one bound parameter per asset and runs into the
     * database's limit on a large container.
     *
     * @param  string[]  $ids
     * @return array<string, mixed>
     */
    private function assetValues(array $ids, callable $value): array
    {
        $wanted = array_flip($ids);
        $values = [];

        $handles = array_unique(array_map(fn (string $id) => Reference::parse($id)?->container, $ids));

        foreach (array_filter($handles) as $handle) {
            if (! $container = AssetContainer::findByHandle($handle)) {
                continue;
            }

            foreach ($container->queryAssets()->get() as $asset) {
                if (isset($wanted[$asset->id()])) {
                    $values[$asset->id()] = $value($asset);
                }
            }
        }

        return $values;
    }

    /**
     * The saving per image, with the images that can get smaller put in a
     * group of their own. A compressed image shows what compressing saved,
     * which can be more than any image still has to gain, so sorting on the
     * number alone would mix the two.
     *
     * @param  string[]  $ids
     * @param  array<string, int>  $groups  filled with 0 for an image that can get smaller, 1 for the rest
     * @return array<string, float|null>
     */
    private function savingsValues(array $ids, array &$groups): array
    {
        $values = [];

        foreach ($this->assetValues($ids, fn ($asset) => $asset) as $id => $asset) {
            $record = $this->compressionRecord($asset);
            $values[$id] = $this->savings($asset, $record);
            $groups[$id] = $this->isCompressible($record, $values[$id]) ? 0 : 1;
        }

        return $values;
    }

    private function pathFor(string $id): string
    {
        return Reference::parse($id)?->path ?? $id;
    }

    /**
     * The compression page's list: images that can get smaller, images this
     * addon compressed, or both.
     *
     * "Can get smaller" is read from the stored analysis, checked against the
     * current settings but not the file version, which would mean hydrating
     * every asset. A file replaced since shows up until its new analysis
     * lands, and its row then says it isn't compressible. "Compressed" comes
     * from the log, so it still holds once the kept original has been pruned.
     *
     * @param  string[]  $ids
     * @return string[]
     */
    private function byCompression(array $ids, string $mode): array
    {
        if (! Settings::compressionEnabled() || ! Requirements::available()) {
            return [];
        }

        $compressible = array_flip($this->analyzer()->compressible($ids));

        $compressed = array_flip(array_column(array_filter(
            (new AssetLog)->entries(AssetLog::COMPRESSED),
            fn (array $entry) => $entry['restored_at'] === null
        ), 'asset_id'));

        return array_values(array_filter($ids, fn (string $id) => match ($mode) {
            'compressible' => isset($compressible[$id]),
            'compressed' => isset($compressed[$id]),
            default => isset($compressible[$id]) || isset($compressed[$id]),
        }));
    }

    /**
     * What the compression column shows: nothing for files that can't be
     * compressed, "not analysed" when there is no current result.
     */
    private function compression($asset): ?array
    {
        if (! Settings::compressionEnabled() || ! Analyzer::applies($asset) || ! Requirements::available()) {
            return null;
        }

        $record = $this->compressionRecord($asset);
        $backups = new Backups;
        $savings = $this->savings($asset, $record);
        $compressible = $this->isCompressible($record, $savings);

        return [
            'analyzed' => $record !== null,
            'status' => $record['status'] ?? null,
            'savings' => $savings,
            'compressible' => $compressible,
            // For the warning before compressing a selection, the same numbers "Compress all" shows.
            'savable_bytes' => $compressible ? $record['before_bytes'] - $record['after_bytes'] : null,
            'icc_lost' => (bool) ($record['icc_lost'] ?? false),
            'before' => isset($record['before_bytes']) ? Str::fileSizeForHumans($record['before_bytes'], 1) : null,
            'after' => isset($record['after_bytes']) ? Str::fileSizeForHumans($record['after_bytes'], 1) : null,
            'width' => $record['before_width'] ?? null,
            'height' => $record['before_height'] ?? null,
            'reason' => $record['reason'] ?? null,
            'has_backup' => $backups->has($asset),
        ];
    }

    /**
     * What the Saving column shows and sorts on. A file this addon compressed
     * has no saving left to offer, so it shows what compressing it saved.
     */
    private function savings($asset, ?array $record): ?float
    {
        if (($record['status'] ?? null) === CompressionResult::COMPRESSED) {
            return (new Backups)->savings($asset);
        }

        return $record['savings'] ?? null;
    }

    /** What gets the button in the Saving column: a current result that saves at least the threshold. */
    private function isCompressible(?array $record, ?float $savings): bool
    {
        return ($record['status'] ?? null) === CompressionResult::OK && $savings !== null && $savings >= Settings::compressionThreshold();
    }

    private function compressionRecord($asset): ?array
    {
        return Analyzer::applies($asset) && Requirements::available() ? $this->analyzer()->fresh($asset) : null;
    }

    private function analyzer(): Analyzer
    {
        return $this->analyzer ??= Analyzer::make();
    }

    /**
     * Only assets in the containers this addon is configured for can be touched
     * from here, whatever id the request supplies.
     */
    private function findEnabledAsset(string $id)
    {
        $reference = Reference::parse($id);

        if (! $reference || ! Containers::includes($reference->container)) {
            return null;
        }

        return Asset::find($id);
    }

    /**
     * The addon's permissions are not a licence to bypass Statamic's own asset
     * permissions, which are per container. The overview only covers containers
     * whose assets the user may see in the asset browser too.
     */
    private function userCanView($container): bool
    {
        return User::current()?->can('view', $container) ?? false;
    }
}
