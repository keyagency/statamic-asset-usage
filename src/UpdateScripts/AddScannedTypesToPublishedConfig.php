<?php

namespace KeyAgency\AssetUsage\UpdateScripts;

use Illuminate\Support\Facades\File;
use Statamic\UpdateScripts\UpdateScript;

/**
 * Adds the scanned types this release introduced to a published config.
 *
 * Laravel merges `scanned_types` as a whole, so a config published before these
 * types existed leaves them switched off, silently, with the only symptom
 * being assets reported as unused. Nothing else in the file is touched: a type
 * that is already listed keeps whatever value it has, so a site that switched
 * one off stays that way.
 */
class AddScannedTypesToPublishedConfig extends UpdateScript
{
    /**
     * The types added in this release. Deliberately a fixed list rather than
     * `Settings::SCANNABLE_TYPES`: a type that existed before could have been
     * left out on purpose, and these could not.
     */
    private const ADDED_TYPES = [
        'collection_cascades',
        'taxonomy_cascades',
        'addon_settings',
        'blueprints',
    ];

    public function shouldUpdate($newVersion, $oldVersion): bool
    {
        return $this->isUpdatingTo('1.1.0');
    }

    public function update(): void
    {
        if (! File::exists($path = config_path('statamic/asset-usage.php'))) {
            return;
        }

        $config = File::get($path);

        if (! $missing = $this->missingFrom($config)) {
            return;
        }

        if (! $patched = $this->insert($config, $missing)) {
            $this->console()->warn(sprintf(
                'Asset Usage could not find the `scanned_types` array in %s. Add these keys yourself, or nothing of theirs is scanned: %s.',
                $path,
                implode(', ', $missing)
            ));

            return;
        }

        File::put($path, $patched);

        $this->console()->info(sprintf(
            'Asset Usage added %s to `scanned_types` in your published config. Set any of them to false to leave that source out, then refresh the usage data with `php please asset-usage:index`.',
            implode(', ', $missing)
        ));
    }

    /**
     * @return string[]
     */
    private function missingFrom(string $config): array
    {
        return array_values(array_filter(
            self::ADDED_TYPES,
            fn (string $type) => preg_match("/'{$type}'\s*=>/", $config) !== 1
        ));
    }

    /**
     * Inserted just before the array closes, so the existing entries and
     * everything around them stay exactly as they were.
     *
     * @param  string[]  $missing
     * @return string|null null when the array can't be located
     */
    private function insert(string $config, array $missing): ?string
    {
        $lines = explode("\n", $config);

        $start = $this->lineMatching($lines, "/'scanned_types'\s*=>\s*\[\s*$/", 0);

        if ($start === null) {
            return null;
        }

        $end = $this->lineMatching($lines, '/^\s*\],\s*$/', $start + 1);

        if ($end === null) {
            return null;
        }

        // Match the indentation of the entries already in there.
        $indent = str_repeat(' ', strlen($lines[$end]) - strlen(ltrim($lines[$end])) + 4);

        array_splice($lines, $end, 0, array_map(
            fn (string $type) => "{$indent}'{$type}' => true,",
            $missing
        ));

        return implode("\n", $lines);
    }

    /**
     * @param  string[]  $lines
     */
    private function lineMatching(array $lines, string $pattern, int $from): ?int
    {
        for ($i = $from; $i < count($lines); $i++) {
            if (preg_match($pattern, $lines[$i]) === 1) {
                return $i;
            }
        }

        return null;
    }
}
