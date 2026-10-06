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

    /**
     * How many days the usage data may go without a full rebuild before the CP
     * suggests one, or 0 when the reminder is off.
     *
     * Read per sub-key with its own default, so a config published before this
     * setting existed, or one that names only the other half, still gets the
     * shipped number instead of a silent 0. Laravel merges the key as a whole,
     * the same trap `scanned_types` falls into.
     */
    public static function rebuildReminderDays(): int
    {
        $configured = config('statamic.asset-usage.rebuild_reminder_days');

        [$key, $default] = self::autoUpdates() ? ['auto_update', 30] : ['manual', 7];

        return max(0, (int) (is_array($configured) ? ($configured[$key] ?? $default) : $default));
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

    public static function compressionEnabled(): bool
    {
        return (bool) self::compression('enabled', true);
    }

    public static function compressionThreshold(): int
    {
        // At least 1, so an image that would not get smaller is never offered.
        return max(1, min(100, (int) self::compression('threshold_percent', 1)));
    }

    public static function compressionMaxDimension(): int
    {
        return max(1, (int) self::compression('max_dimension', 3840));
    }

    public static function compressionDpi(): int
    {
        return max(1, (int) self::compression('dpi', 72));
    }

    public static function jpgQuality(): int
    {
        return max(1, min(100, (int) self::compression('jpg_quality', 82)));
    }

    public static function webpQuality(): int
    {
        return max(1, min(100, (int) self::compression('webp_quality', 80)));
    }

    public static function pngQuality(): string
    {
        return (string) self::compression('png_quality', '70-90');
    }

    public static function pngquantBinary(): ?string
    {
        $binary = self::compression('pngquant_binary');

        return is_string($binary) && $binary !== '' ? $binary : null;
    }

    /** Null when originals are kept forever. */
    public static function keepOriginalsDays(): ?int
    {
        $days = self::compression('keep_originals_days', 30);

        return $days === null ? null : max(0, (int) $days);
    }

    /**
     * Read per sub-key with its own default, for the same reason as
     * `rebuildReminderDays()`: Laravel merges the key as a whole.
     */
    private static function compression(string $key, mixed $default = null): mixed
    {
        $configured = config('statamic.asset-usage.compression');

        return is_array($configured) && array_key_exists($key, $configured) ? $configured[$key] : $default;
    }
}
