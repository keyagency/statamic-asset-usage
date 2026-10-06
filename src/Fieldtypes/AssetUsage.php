<?php

namespace KeyAgency\AssetUsage\Fieldtypes;

use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Compression\CompressionResult;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\ServiceProvider;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Usage\IndexStore;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Fields\Fieldtype;
use Statamic\Support\Str;

/**
 * The "Used in" panel in the asset editor, and the usage column in the asset
 * browser. Both read the asset from `$this->field->parent()`: the editor sets it
 * via `AssetContainer::blueprint($asset)`, the listing via
 * `FolderAsset::values()`.
 *
 * The field is injected with `visibility: computed`, which is what keeps it out
 * of the saved data, because `Fields::values()` drops computed fields unless
 * `withComputedValues()` was called, and the asset update path never does. Don't
 * change that visibility without re-checking `AssetsController::update()`.
 */
class AssetUsage extends Fieldtype
{
    protected $selectable = false;

    protected $localizable = false;

    protected $validatable = false;

    protected $defaultable = false;

    protected $icon = 'assets';

    /**
     * Everything the editor panel needs. Sent as field meta, so it is resolved
     * once per asset rather than per render.
     */
    public function preload()
    {
        $store = new IndexStore;
        $asset = $this->asset();

        if (! $asset) {
            return $this->emptyState($store);
        }

        $index = $store->indexOrEmpty();
        $usages = $store->isStale() ? [] : $index->for($asset->id());

        return [
            'indexed' => ! $store->isStale(),
            'building' => $store->isBuilding(),
            'count' => count($usages),
            'usages' => array_map(fn ($usage) => $usage->toArray(), $usages),
            'siteTitles' => $this->siteTitles(),
            'multisite' => Site::hasMultiple(),
            'toolsUrl' => cp_route('asset-usage.index'),
            'compression' => $this->compression($asset),
        ];
    }

    /**
     * What the editor shows under the usage, for every image this addon can
     * compress: the saving with a way to the before and after page, a way
     * back to the original of one that was compressed, or why there is
     * nothing to gain. Also before the analysis has run, so an image that
     * was never checked doesn't look like one with nothing to gain. Only for
     * users who may replace the file.
     */
    private function compression(Asset $asset): ?array
    {
        $user = User::current();

        if (! Settings::compressionEnabled()
            || ! Analyzer::applies($asset)
            || ! $user
            || ! ($user->isSuper() || $user->hasPermission(ServiceProvider::PERMISSION_COMPRESS))
            || ! $user->can('reupload', $asset)
            || ! Requirements::available()) {
            return null;
        }

        $record = Analyzer::make()->fresh($asset);
        $backups = new Backups;
        // Each check reads the kept original's details from disk, so once, and the rest only when there is one.
        $hasBackup = $backups->has($asset);
        $savings = $record['savings'] ?? null;
        $threshold = Settings::compressionThreshold();
        $compressible = ($record['status'] ?? null) === CompressionResult::OK
            && $savings !== null
            && $savings >= $threshold;

        return [
            'state' => $this->compressionState($record, $compressible, $hasBackup),
            'compressible' => $compressible,
            'savings' => $savings !== null ? (int) round($savings) : null,
            'threshold' => $threshold,
            'before' => isset($record['before_bytes']) ? Str::fileSizeForHumans($record['before_bytes'], 1) : null,
            'after' => isset($record['after_bytes']) ? Str::fileSizeForHumans($record['after_bytes'], 1) : null,
            'width' => $record['before_width'] ?? null,
            'height' => $record['before_height'] ?? null,
            'reason' => $record['reason'] ?? null,
            'has_backup' => $hasBackup,
            'compressed' => $hasBackup ? $backups->summary($asset) : null,
            'expires_at' => $hasBackup ? $backups->expiresAt($asset)?->toIso8601String() : null,
            'url' => cp_route('asset-usage.compress.show', ['asset' => $asset->id()]),
        ];
    }

    /**
     * Which message the editor shows. A saving that rounds to 0% reads as no
     * saving, the same as in the Saving column of the overview.
     */
    private function compressionState(?array $record, bool $compressible, bool $hasBackup): string
    {
        if ($compressible) {
            return 'compressible';
        }

        if ($hasBackup) {
            return 'restorable';
        }

        $savings = (int) round($record['savings'] ?? 0);

        return match ($record['status'] ?? null) {
            null => 'not_analyzed',
            CompressionResult::OK => match (true) {
                $savings >= 1 => 'below_threshold',
                $savings <= -1 => 'larger',
                default => 'no_saving',
            },
            default => $record['status'],
        };
    }

    /**
     * The column value. Deliberately just "is it used, and how often": the
     * column renders a single icon, because a list of sites or titles made rows
     * far too wide. The details live in the editor panel and the Tools page.
     */
    public function preProcessIndex($data)
    {
        $store = new IndexStore;
        $asset = $this->asset();

        if (! $asset || $store->isStale()) {
            return ['indexed' => false, 'count' => 0];
        }

        return [
            'indexed' => true,
            'count' => $store->indexOrEmpty()->countFor($asset->id()),
        ];
    }

    /**
     * Belt and braces: the computed visibility already keeps this out of the
     * saved values, so if this ever runs we still want nothing stored.
     */
    public function process($data)
    {
        return null;
    }

    /**
     * On the front end the field augments to the number of usages, which is the
     * only part that makes sense outside the CP.
     */
    /**
     * What the Stache sorts the "Used" column on in the asset browser: the
     * number of places the asset is used.
     */
    public function toQueryableValue($value)
    {
        $asset = $this->asset();

        return $asset ? (new IndexStore)->indexOrEmpty()->countFor($asset->id()) : 0;
    }

    public function augment($value)
    {
        $asset = $this->asset();

        return $asset ? (new IndexStore)->indexOrEmpty()->countFor($asset->id()) : 0;
    }

    private function asset(): ?Asset
    {
        $parent = $this->field?->parent();

        return $parent instanceof Asset ? $parent : null;
    }

    private function emptyState(IndexStore $store): array
    {
        return [
            'indexed' => ! $store->isStale(),
            'building' => $store->isBuilding(),
            'count' => 0,
            'usages' => [],
            'siteTitles' => $this->siteTitles(),
            'multisite' => Site::hasMultiple(),
            'toolsUrl' => cp_route('asset-usage.index'),
            'compression' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function siteTitles(): array
    {
        return Site::all()->mapWithKeys(fn ($site) => [$site->handle() => $site->name()])->all();
    }
}
