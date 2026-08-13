<?php

namespace KeyAgency\AssetUsage\Usage;

use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Contracts\Assets\Asset;

/**
 * Decides which assets count as unused, and whether one may be deleted.
 *
 * The safety rails live here rather than in the controller or the command, so
 * the CP and the CLI can't drift apart on what they're willing to remove.
 */
final class Unused
{
    public function __construct(
        private readonly Containers $containers,
        private readonly UsageIndex $index,
    ) {}

    public static function make(?IndexStore $store = null): self
    {
        $store ??= new IndexStore;

        return new self(Containers::make(), $store->indexOrEmpty());
    }

    /**
     * The ids of every asset in the enabled containers that nothing references.
     *
     * @return string[]
     */
    public function ids(?string $container = null): array
    {
        return array_values(array_filter(
            $this->containers->assetIds(),
            function (string $id) use ($container) {
                $reference = Reference::parse($id);

                if ($container && $reference?->container !== $container) {
                    return false;
                }

                return ! $this->index->isUsed($id) && ! $this->isIgnored($reference?->path ?? '');
            }
        ));
    }

    /**
     * Filename patterns from config, matched against both the full path and the
     * basename so `*.pdf` works as well as `downloads/*`.
     */
    public function isIgnored(string $path): bool
    {
        foreach (Settings::ignoredPatterns() as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }

        return false;
    }

    public function isTooNew(Asset $asset): bool
    {
        if (! $days = Settings::minimumAgeInDays()) {
            return false;
        }

        return $asset->lastModified()->greaterThan(now()->subDays($days));
    }

    /**
     * Why this asset may not be deleted, as a translated message, or null when
     * deleting it is fine.
     */
    public function blocker(Asset $asset): ?string
    {
        if ($count = $this->index->countFor($asset->id())) {
            return __('asset-usage::messages.errors.asset_is_used', ['count' => $count]);
        }

        if ($this->isIgnored($asset->path())) {
            return __('asset-usage::messages.errors.asset_ignored');
        }

        if ($this->isTooNew($asset)) {
            return __('asset-usage::messages.errors.asset_too_new', ['days' => Settings::minimumAgeInDays()]);
        }

        return null;
    }

    public function containers(): Containers
    {
        return $this->containers;
    }

    public function index(): UsageIndex
    {
        return $this->index;
    }
}
