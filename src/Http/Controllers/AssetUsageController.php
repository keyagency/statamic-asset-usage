<?php

namespace KeyAgency\AssetUsage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\AuthorizesAssetUsage;
use KeyAgency\AssetUsage\Jobs\BuildIndex;
use KeyAgency\AssetUsage\Support\NavIcon;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Reference;
use KeyAgency\AssetUsage\Usage\Unused;
use KeyAgency\AssetUsage\Usage\UsageIndex;
use Statamic\Facades\Asset;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Str;

class AssetUsageController extends CpController
{
    use AuthorizesAssetUsage;

    private const PER_PAGE = 25;

    /** The orders the overview can be sorted in. The first one is the default. */
    private const SORTS = ['name_asc', 'name_desc', 'used', 'unused'];

    /**
     * How many assets one "delete all unused" request will remove. A cleanup can
     * span thousands of files, and a request that runs into the time limit
     * halfway is worse than one that finishes and leaves a remainder — the
     * overview reports what is left, so the button can simply be used again.
     */
    private const MAX_BULK_DELETE = 500;

    public function index()
    {
        $this->authorizeView();

        return Inertia::render('asset-usage::AssetUsage', [
            'icon' => NavIcon::svg(),
            'multisite' => Site::hasMultiple(),
            'canDelete' => $this->canDelete(),
            'assetsUrl' => cp_route('asset-usage.assets'),
            'rebuildUrl' => cp_route('asset-usage.rebuild'),
            'statusUrl' => cp_route('asset-usage.status'),
            'destroyUrl' => cp_route('asset-usage.destroy'),
            'destroyUnusedUrl' => cp_route('asset-usage.destroy-unused'),
            'containers' => Containers::enabled()
                ->map(fn ($container) => ['handle' => $container->handle(), 'title' => $container->title()])
                ->all(),
            'sites' => Site::all()
                ->map(fn ($site) => ['handle' => $site->handle(), 'title' => $site->name()])
                ->values()
                ->all(),
            'minimumAgeInDays' => Settings::minimumAgeInDays(),
        ]);
    }

    /**
     * A page of assets with their usage. Filtering runs against the index and
     * the container file listings, so only the assets on the current page are
     * hydrated.
     */
    public function assets(Request $request)
    {
        $this->authorizeView();

        $store = new IndexStore;
        $unused = Unused::make($store);
        $index = $unused->index();

        $ids = $this->sort(
            $this->filter($request, $unused->containers(), $index),
            $request->input('sort'),
            $index
        );

        $page = max(1, (int) $request->input('page', 1));
        $perPage = self::PER_PAGE;
        $total = count($ids);

        $rows = collect(array_slice($ids, ($page - 1) * $perPage, $perPage))
            ->map(fn (string $id) => $this->row($id, $index, $unused))
            ->filter()
            ->values()
            ->all();

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? ($page - 1) * $perPage + 1 : null,
                'to' => $total > 0 ? min($page * $perPage, $total) : null,
                'index' => $this->indexState($store),
                /*
                 * What "delete all unused" would cover. Counted past the usage
                 * filter, so the button doesn't read zero while looking at used
                 * assets. Assets held back by their age are only known once
                 * hydrated, so they can still turn up as skipped on delete.
                 */
                'unused_total' => count($this->unusedIds($request, $unused, $index)),
            ],
        ];
    }

    /**
     * Flagged as building before the job is dispatched, so a queued rebuild is
     * visible to the CP right away instead of only once a worker picks it up.
     * Under the sync connection the job has already finished by the time we
     * report back, which is what decides the wording.
     */
    public function rebuild()
    {
        $this->authorizeView();

        $store = new IndexStore;

        $store->markBuilding();

        BuildIndex::dispatch();

        $state = $this->indexState($store);

        return [
            'success' => true,
            'message' => $state['building']
                ? __('asset-usage::messages.index.refresh_started')
                : __('asset-usage::messages.index.refreshed'),
            'index' => $state,
        ];
    }

    public function status()
    {
        $this->authorizeView();

        return ['index' => $this->indexState(new IndexStore)];
    }

    /**
     * Delete assets, one or many. Nothing is taken on trust: the index has to
     * be current, and every asset is re-checked against the same rails the
     * command uses before its file is removed.
     */
    public function destroy(Request $request)
    {
        $this->authorizeDelete();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $store = new IndexStore;

        if ($store->isStale()) {
            return response()->json([
                'message' => __('asset-usage::messages.errors.stale_index'),
            ], 409);
        }

        return $this->deleteAssets($validated['ids'], Unused::make($store));
    }

    /**
     * Delete every unused asset the current filters cover, not only the ones on
     * the page being looked at. The usage filter is overruled: this deletes what
     * is unused, whatever the overview happens to be showing.
     */
    public function destroyUnused(Request $request)
    {
        $this->authorizeDelete();

        $store = new IndexStore;

        if ($store->isStale()) {
            return response()->json([
                'message' => __('asset-usage::messages.errors.stale_index'),
            ], 409);
        }

        $unused = Unused::make($store);

        $ids = array_slice(
            $this->unusedIds($request, $unused, $unused->index()),
            0,
            self::MAX_BULK_DELETE
        );

        return $this->deleteAssets($ids, $unused);
    }

    /**
     * The ids "delete all unused" would act on: everything the filters cover
     * that nothing references and that isn't protected by an `ignore` pattern.
     *
     * @return string[]
     */
    private function unusedIds(Request $request, Unused $unused, UsageIndex $index): array
    {
        $ids = $this->filter($request, $unused->containers(), $index, usage: 'unused');

        return array_values(array_filter(
            $ids,
            fn (string $id) => ! $unused->isIgnored(Reference::parse($id)?->path ?? '')
        ));
    }

    /**
     * @param  string[]  $ids
     */
    private function deleteAssets(array $ids, Unused $unused): array
    {
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            if (! $asset = $this->findEnabledAsset($id)) {
                $errors[$id] = __('asset-usage::messages.errors.asset_missing');

                continue;
            }

            if (! $this->userCanDelete($asset)) {
                $errors[$id] = __('statamic::error.unauthorized');

                continue;
            }

            if ($blocker = $unused->blocker($asset)) {
                $errors[$id] = $blocker;

                continue;
            }

            $asset->delete();
            $deleted++;
        }

        return [
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => $deleted > 0
                ? trans_choice('asset-usage::messages.delete.success', $deleted, ['count' => $deleted])
                : __('asset-usage::messages.delete.none'),
        ];
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

        $ids = array_filter($containers->assetIds(), function (string $id) use ($container, $usage, $site, $search, $index) {
            $reference = Reference::parse($id);

            if (! $reference) {
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
     * also the tie-breaker within an equal usage count — usort is stable.
     *
     * @param  string[]  $ids
     * @return string[]
     */
    private function sort(array $ids, ?string $sort, UsageIndex $index): array
    {
        if (! in_array($sort, self::SORTS, true)) {
            $sort = self::SORTS[0];
        }

        usort($ids, fn (string $a, string $b) => strnatcasecmp($this->pathFor($a), $this->pathFor($b)));

        return match ($sort) {
            'name_desc' => array_reverse($ids),
            'used' => $this->sortByCount($ids, $index, descending: true),
            'unused' => $this->sortByCount($ids, $index, descending: false),
            default => $ids,
        };
    }

    /**
     * @param  string[]  $ids
     * @return string[]
     */
    private function sortByCount(array $ids, UsageIndex $index, bool $descending): array
    {
        usort($ids, fn (string $a, string $b) => $descending
            ? $index->countFor($b) <=> $index->countFor($a)
            : $index->countFor($a) <=> $index->countFor($b));

        return $ids;
    }

    private function pathFor(string $id): string
    {
        return Reference::parse($id)?->path ?? $id;
    }

    private function row(string $id, UsageIndex $index, Unused $unused): ?array
    {
        if (! $asset = Asset::find($id)) {
            return null;
        }

        $usages = $index->for($id);

        return [
            'id' => $id,
            'path' => $asset->path(),
            'basename' => $asset->basename(),
            'container' => $asset->container()->handle(),
            'container_title' => $asset->container()->title(),
            'edit_url' => $asset->editUrl(),
            'thumbnail' => $asset->isImage() ? $asset->thumbnailUrl('small') : null,
            'is_image' => $asset->isImage(),
            'extension' => $asset->extension(),
            'size' => Str::fileSizeForHumans($asset->size(), 0),
            'last_modified' => $asset->lastModified()->diffForHumans(),
            'count' => count($usages),
            'usages' => array_map(fn ($usage) => $usage->toArray() + [
                'type_label' => __('asset-usage::messages.item_type.'.$usage->type),
            ], $usages),
            'blocker' => $unused->blocker($asset),
        ];
    }

    private function indexState(IndexStore $store): array
    {
        $meta = $store->meta();
        $builtAt = $meta['built_at'] ? Carbon::parse($meta['built_at'])->setTimezone(config('app.timezone')) : null;

        return [
            'exists' => $store->exists(),
            'stale' => $store->isStale(),
            'building' => $store->isBuilding(),
            'built_at' => $meta['built_at'],
            /*
             * Spelled out and localised — isoFormat() gives translated month
             * names, which strtotime-style formats do not.
             */
            'built_at_formatted' => $builtAt?->isoFormat('D MMMM YYYY [—] HH:mm'),
            'built_at_relative' => $builtAt?->diffForHumans(),
            'items_scanned' => $meta['items_scanned'],
        ];
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
     * The addon's permission is not a licence to bypass Statamic's own asset
     * permissions, which are per container.
     */
    private function userCanDelete($asset): bool
    {
        return User::current()?->can('delete', $asset) ?? false;
    }
}
