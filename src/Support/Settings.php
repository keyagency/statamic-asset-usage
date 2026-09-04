<?php

namespace KeyAgency\AssetUsage\Support;

/**
 * Typed access to the addon's config. Everything reads its settings through
 * here so a rename or a default change happens in one place.
 */
final class Settings
{
    /** Every content type `scanned_types` can switch on or off. */
    public const SCANNABLE_TYPES = ['entries', 'globals', 'terms', 'navs', 'users', 'assets', 'form_submissions', 'collection_cascades', 'taxonomy_cascades', 'addon_settings', 'blueprints'];

    /**
     * The configured container handles, or null when all containers apply.
     *
     * @return string[]|null
     */
    public static function containers(): ?array
    {
        $containers = config('statamic.asset-usage.containers', '*');

        if ($containers === '*' || $containers === null) {
            return null;
        }

        return array_values(array_map('strval', (array) $containers));
    }

    /**
     * Whether a content type is scanned.
     *
     * A `scanned_types` array in the config is read as the complete set, so
     * listing only the types you want is enough, and anything left out is off.
     * Laravel merges that key as a whole, which means a config with just
     * `['entries' => true]` really does mean "entries only". Everything is
     * scanned when the key is missing altogether.
     */
    public static function scans(string $type): bool
    {
        $types = config('statamic.asset-usage.scanned_types');

        if (! is_array($types)) {
            return true;
        }

        return (bool) ($types[$type] ?? false);
    }

    /**
     * The types that are actually scanned, in a fixed order so the value can be
     * compared against what an index was built with.
     *
     * @return string[]
     */
    public static function scannedTypes(): array
    {
        return array_values(array_filter(
            self::SCANNABLE_TYPES,
            fn (string $type) => self::scans($type)
        ));
    }

    public static function scansUrls(): bool
    {
        return (bool) config('statamic.asset-usage.scan_urls', true);
    }

    public static function includesWorkingCopies(): bool
    {
        return (bool) config('statamic.asset-usage.include_working_copies', true);
    }

    public static function autoUpdates(): bool
    {
        return (bool) config('statamic.asset-usage.auto_update', true);
    }

    public static function showsEditorPanel(): bool
    {
        return (bool) config('statamic.asset-usage.editor_panel', true);
    }

    public static function showsListingColumn(): bool
    {
        return (bool) config('statamic.asset-usage.listing_column', true);
    }

    /**
     * Filename patterns that are never reported as unused.
     *
     * @return string[]
     */
    public static function ignoredPatterns(): array
    {
        return array_values(array_filter(array_map(
            'strval',
            (array) config('statamic.asset-usage.ignore', [])
        )));
    }

    public static function minimumAgeInDays(): int
    {
        return max(0, (int) config('statamic.asset-usage.minimum_age_in_days', 0));
    }
}
