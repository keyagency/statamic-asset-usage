<?php

namespace KeyAgency\AssetUsage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Compression\Status;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\AuthorizesAssetUsage;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\ListsAssets;
use KeyAgency\AssetUsage\Jobs\BuildIndex;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\Source;
use KeyAgency\AssetUsage\Support\ImageDensity;
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
    use AuthorizesAssetUsage, ListsAssets;

    private const PER_PAGE = 25;

    /**
     * How many assets one "delete all unused" request will remove. A cleanup can
     * span thousands of files, and a request that runs into the time limit
     * halfway is worse than one that finishes and leaves a remainder. The
     * overview reports what is left, so the button can simply be used again.
     */
    private const MAX_BULK_DELETE = 500;

    public function index()
    {
        $this->authorizeView();

        return $this->page('usage');
    }

    /**
     * The same overview, narrowed down to the images that can get smaller and
     * without the parts that are only about cleaning up unused assets. Only
     * for users who may compress: for anyone else it has nothing to do, and
     * the Saving column on the overview already shows the numbers.
     */
    public function compressionPage()
    {
        $this->authorizeView();
        $this->authorizeCompress();

        abort_unless(Settings::compressionEnabled(), 404);

        return $this->page('compression');
    }

    private function page(string $view)
    {
        return Inertia::render('asset-usage::AssetUsage', [
            'view' => $view,
            'usageUrl' => cp_route('asset-usage.index'),
            // Null without the compress permission, which leaves out the tab and the notice that lead there.
            'compressionPageUrl' => $this->canCompress() ? cp_route('asset-usage.compression') : null,
            'icon' => NavIcon::svg(),
            'multisite' => Site::hasMultiple(),
            'canDelete' => $this->canDelete(),
            'assetsUrl' => cp_route('asset-usage.assets'),
            'rebuildUrl' => cp_route('asset-usage.rebuild'),
            'statusUrl' => cp_route('asset-usage.status'),
            'destroyUrl' => cp_route('asset-usage.destroy'),
            'destroyUnusedUrl' => cp_route('asset-usage.destroy-unused'),
            'containers' => Containers::enabled()
                ->filter(fn ($container) => $this->userCanView($container))
                ->values()
                ->map(fn ($container) => ['handle' => $container->handle(), 'title' => $container->title()])
                ->all(),
            'sites' => Site::all()
                ->map(fn ($site) => ['handle' => $site->handle(), 'title' => $site->name()])
                ->values()
                ->all(),
            'compressionEnabled' => Settings::compressionEnabled(),
            'canCompress' => Settings::compressionEnabled() && $this->canCompress(),
            'analyzeUrl' => cp_route('asset-usage.compress.analyze'),
            'compressionStatusUrl' => cp_route('asset-usage.compress.status'),
            'compressUrl' => cp_route('asset-usage.compress.show'),
            'compressAllUrl' => cp_route('asset-usage.compress.all'),
            'compressBatchUrl' => cp_route('asset-usage.compress.batch'),
            'keepOriginalsDays' => Settings::keepOriginalsDays(),
            // Null without the log permission, which leaves out the tab and the buttons that lead there.
            'logUrl' => $this->canViewLog() ? cp_route('asset-usage.log') : null,
            'exportUrl' => cp_route('asset-usage.export.download'),
            'exportThumbnailsUrl' => cp_route('asset-usage.export.thumbnails'),
            'exportMakeThumbnailsUrl' => cp_route('asset-usage.export.make-thumbnails'),
        ]);
    }

    /**
     * The images "Compress all" goes through, which the page then sends in
     * small batches (see CompressionController::batch()).
     */
    public function compressible(Request $request)
    {
        $this->authorizeCompress();

        abort_unless(Settings::compressionEnabled(), 404);

        $unused = Unused::make(new IndexStore);

        return ['ids' => $this->compressibleIds($request, $unused, $unused->index())];
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

        $ids = $this->listedIds($request, $unused->containers(), $index);

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
                'deletions' => (new AssetLog)->deletionTotals((new AssetLog)->visibleTo(User::current())),
                'compress_all' => $this->compressAllSummary($request, $unused, $index),
                'compression' => Settings::compressionEnabled()
                    ? (Requirements::available() ? Status::make($this->analyzer())->toArray() : Status::unavailable())
                    : null,
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
        /*
         * Without usage data every asset looks unreferenced, which would put a
         * count on the "delete all unused" button covering the whole library.
         */
        if (! $unused->hasUsageData()) {
            return [];
        }

        $ids = $this->filter($request, $unused->containers(), $index, usage: 'unused');

        return array_values(array_filter(
            $ids,
            fn (string $id) => ! $unused->isIgnored(Reference::parse($id)?->path ?? '')
        ));
    }

    /**
     * What "Compress all" covers: every image that can get smaller within the
     * container and search filters. Like "delete all unused", it overrules the
     * filter on what the list shows.
     *
     * @return string[]
     */
    private function compressibleIds(Request $request, Unused $unused, UsageIndex $index): array
    {
        return $this->byCompression($this->filter($request, $unused->containers(), $index, usage: 'all'), 'compressible');
    }

    /**
     * For the button and the warning on the Compression page. Only there, and
     * only for users who may compress.
     */
    private function compressAllSummary(Request $request, Unused $unused, UsageIndex $index): ?array
    {
        if (! $request->has('compression') || ! $this->canCompress() || ! Settings::compressionEnabled() || ! Requirements::available()) {
            return null;
        }

        $records = $this->analyzer()->store()->records();
        $ids = $this->compressibleIds($request, $unused, $index);

        return [
            'count' => count($ids),
            'savable_bytes' => array_sum(array_map(fn (string $id) => $records[$id]['before_bytes'] - $records[$id]['after_bytes'], $ids)),
            'icc_lost' => count(array_filter($ids, fn (string $id) => $records[$id]['icc_lost'] ?? false)),
        ];
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

            Source::during(Source::TOOLS, fn () => $asset->delete());
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
            'dimensions' => $asset->isImage() && $asset->width() ? $asset->width().' × '.$asset->height() : null,
            'dpi' => $asset->isImage() ? ImageDensity::for($asset) : null,
            'last_modified' => $asset->lastModified()->diffForHumans(),
            'count' => count($usages),
            'usages' => array_map(fn ($usage) => $usage->toArray() + [
                'type_label' => __('asset-usage::messages.item_type.'.$usage->type),
            ], $usages),
            'blocker' => $unused->blocker($asset),
            'compression' => $this->compression($asset),
        ];
    }

    private function indexState(IndexStore $store): array
    {
        $meta = $store->meta();
        $builtAt = $meta['built_at'] ? Carbon::parse($meta['built_at']) : null;

        return [
            'exists' => $store->exists(),
            'stale' => $store->isStale(),
            'aged' => $store->isAged(),
            'building' => $store->isBuilding(),
            /** The reminder reads differently depending on whether anything is keeping the index current. */
            'auto_update' => Settings::autoUpdates(),
            /** Formatted in the browser, so it shows in the viewer's timezone rather than app.timezone. */
            'built_at' => $meta['built_at'],
            'built_at_relative' => $builtAt?->diffForHumans(),
            'items_scanned' => $meta['items_scanned'],
        ];
    }

    private function userCanDelete($asset): bool
    {
        return User::current()?->can('delete', $asset) ?? false;
    }
}
