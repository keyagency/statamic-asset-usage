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
        /**
         * Why the index can't be believed, as an `errors.*` lang key, or null
         * when it can. An empty index and one that was never built look
         * identical from in here, and reading the second as "nothing is used"
         * would call every asset on the site deletable.
         */
        private readonly ?string $unusableReason = null,
    ) {}

    /**
     * Whether there is usage data to reason about at all. Callers that count
     * unused assets for themselves have to ask, or they end up counting an
     * absence of data as an absence of usage.
     */
    public function hasUsageData(): bool
    {
        return $this->unusableReason === null;
    }

    public static function make(?IndexStore $store = null): self
    {
        $store ??= new IndexStore;

        // Never built and built for other settings are both unusable, for different reasons.
        $reason = match (true) {
            ! $store->exists() => 'no_usage_data',
            $store->isStale() => 'stale_index',
            default => null,
        };

        return new self(Containers::make(), $store->indexOrEmpty(), $reason);
    }

    /**
     * The ids of every asset in the enabled containers that nothing references.
     * Empty until there is usage data to base that on.
     *
     * @return string[]
     */
    public function ids(?string $container = null): array
    {
        if (! $this->hasUsageData()) {
            return [];
        }

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
     *
     * @param  string|null  $locale  the locale to render in, or null for the
     *                               app's. The commands pass 'en' so their
     *                               output stays in one language.
     */
    public function blocker(Asset $asset, ?string $locale = null): ?string
    {
        if ($this->unusableReason) {
            return __("asset-usage::messages.errors.{$this->unusableReason}", [], $locale);
        }

        if ($count = $this->index->countFor($asset->id())) {
            return __('asset-usage::messages.errors.asset_is_used', ['count' => $count], $locale);
        }

        if ($this->isIgnored($asset->path())) {
            return __('asset-usage::messages.errors.asset_ignored', [], $locale);
        }

        if ($this->isTooNew($asset)) {
            return __('asset-usage::messages.errors.asset_too_new', ['days' => Settings::minimumAgeInDays()], $locale);
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
