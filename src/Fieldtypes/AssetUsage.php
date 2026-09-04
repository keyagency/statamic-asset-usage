<?php

namespace KeyAgency\AssetUsage\Fieldtypes;

use KeyAgency\AssetUsage\Usage\IndexStore;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Site;
use Statamic\Fields\Fieldtype;

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
        ];
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
