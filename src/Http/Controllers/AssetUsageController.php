<?php

namespace KeyAgency\AssetUsage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Compression\CompressionResult;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Compression\Status;
use KeyAgency\AssetUsage\Http\Controllers\Concerns\AuthorizesAssetUsage;
use KeyAgency\AssetUsage\Jobs\BuildIndex;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\DeletionSource;
use KeyAgency\AssetUsage\Support\ImageDensity;
use KeyAgency\AssetUsage\Support\NavIcon;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Reference;
use KeyAgency\AssetUsage\Usage\Unused;
use KeyAgency\AssetUsage\Usage\UsageIndex;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Str;

class AssetUsageController extends CpController
{
    use AuthorizesAssetUsage;

    private const PER_PAGE = 25;

    /** The columns the overview can be sorted by. The first one is the default. */
    private const SORTS = ['path', 'size', 'resolution', 'dpi', 'savings', 'last_modified', 'usage'];

    /** Built on first use; a request only needs it for the compression column. */
    private ?Analyzer $analyzer = null;

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
     * without the parts that are only about cleaning up unused assets.
     */
    public function compressionPage()
    {
        $this->authorizeView();

        abort_unless(Settings::compressionEnabled(), 404);

        return $this->page('compression');
    }

    private function page(string $view)
    {
        return Inertia::render('asset-usage::AssetUsage', [
            'view' => $view,
            'usageUrl' => cp_route('asset-usage.index'),
            'compressionPageUrl' => cp_route('asset-usage.compression'),
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
            'logUrl' => cp_route('asset-usage.log'),
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

        $ids = $this->filter($request, $unused->containers(), $index);

        if (in_array($compression = $request->input('compression'), ['all', 'compressible', 'compressed'], true)) {
            $ids = $this->byCompression($ids, $compression);
        }

        $ids = $this->sort(
            $ids,
            $request->input('sort'),
            $request->input('order') === 'desc',
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
                'deletions' => (new AssetLog)->deletionTotals((new AssetLog)->visibleTo(User::current())),
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

            DeletionSource::during(DeletionSource::TOOLS, fn () => $asset->delete());
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

        $values = match ($sort) {
            'usage' => array_combine($ids, array_map(fn (string $id) => $index->countFor($id), $ids)),
            'size' => $this->assetValues($ids, fn ($asset) => $asset->size()),
            'last_modified' => $this->assetValues($ids, fn ($asset) => $asset->lastModified()->timestamp),
            'resolution' => $this->assetValues($ids, fn ($asset) => $asset->isImage() && $asset->width() ? $asset->width() * $asset->height() : null),
            'dpi' => $this->assetValues($ids, fn ($asset) => $asset->isImage() ? ImageDensity::for($asset) : null),
            'savings' => $this->assetValues($ids, fn ($asset) => $this->savings($asset, $this->compressionRecord($asset))),
        };

        // Assets without a value, such as the resolution of a PDF, go last in either direction.
        usort($ids, function (string $a, string $b) use ($values, $descending) {
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
     * @return array<string, int|null>
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

        return [
            'analyzed' => $record !== null,
            'status' => $record['status'] ?? null,
            'savings' => $savings,
            'compressible' => ($record['status'] ?? null) === CompressionResult::OK && $savings !== null && $savings >= Settings::compressionThreshold(),
            'before' => isset($record['before_bytes']) ? Str::fileSizeForHumans($record['before_bytes'], 1) : null,
            'after' => isset($record['after_bytes']) ? Str::fileSizeForHumans($record['after_bytes'], 1) : null,
            'width' => $record['before_width'] ?? null,
            'height' => $record['before_height'] ?? null,
            'reason' => $record['reason'] ?? null,
            'has_backup' => $backups->has($asset),
        ];
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

        $fingerprint = $this->analyzer()->compressor()->fingerprint();
        $records = $this->analyzer()->store()->records();
        $threshold = Settings::compressionThreshold();

        $compressed = array_flip(array_column(array_filter(
            (new AssetLog)->entries(AssetLog::COMPRESSED),
            fn (array $entry) => $entry['restored_at'] === null
        ), 'asset_id'));

        return array_values(array_filter($ids, function (string $id) use ($records, $fingerprint, $threshold, $compressed, $mode) {
            $record = $records[$id] ?? null;

            $compressible = $record
                && ($record['settings'] ?? null) === $fingerprint
                && ($record['status'] ?? null) === CompressionResult::OK
                && ($record['savings'] ?? 0) >= $threshold;

            return match ($mode) {
                'compressible' => $compressible,
                'compressed' => isset($compressed[$id]),
                default => $compressible || isset($compressed[$id]),
            };
        }));
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

    private function compressionRecord($asset): ?array
    {
        return Analyzer::applies($asset) && Requirements::available() ? $this->analyzer()->fresh($asset) : null;
    }

    private function analyzer(): Analyzer
    {
        return $this->analyzer ??= Analyzer::make();
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

    private function userCanDelete($asset): bool
    {
        return User::current()?->can('delete', $asset) ?? false;
    }
}
