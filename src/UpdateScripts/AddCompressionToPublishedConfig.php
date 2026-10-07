<?php

namespace KeyAgency\AssetUsage\UpdateScripts;

use Illuminate\Support\Facades\File;
use KeyAgency\AssetUsage\UpdateScripts\Concerns\InsertsIntoConfig;
use Statamic\UpdateScripts\UpdateScript;

/**
 * Adds the `compression` settings this release introduced to a published
 * config. `Settings` falls back to the shipped values for every sub-key, so
 * nothing breaks without it; it is here so the settings show up in the file.
 */
class AddCompressionToPublishedConfig extends UpdateScript
{
    use InsertsIntoConfig;

    private const KEY = 'compression';

    /**
     * Written out in full, comment block and all, so a published config reads
     * the same as a freshly published one.
     */
    private const BLOCK = <<<'PHP'
    /*
    |--------------------------------------------------------------------------
    | Image Compression
    |--------------------------------------------------------------------------
    |
    | The Tools page can shrink images that are much heavier than they need to
    | be. Each image is test-compressed in the background (after an upload, or
    | with the "Analyse compression" button and `php please
    | asset-usage:analyze`), and an image that would get at least
    | `threshold_percent` smaller gets a Compress button. Compressing always
    | shows a before and after first, and keeps the original for
    | `keep_originals_days` so it can be put back.
    |
    | - threshold_percent: the smallest saving, in whole percents, that gets a
    |   Compress button. 1 offers every image that gets any smaller at all.
    |   An image that would only get bigger is never offered.
    | - max_dimension: longest side in pixels. Larger images are scaled down,
    |   smaller ones are never enlarged. This is what lowers the memory Glide
    |   needs, which depends on the number of pixels only.
    | - dpi: the resolution written into the file. Browsers ignore it.
    | - png_quality: the min-max range passed to pngquant. Without pngquant on
    |   the server, PNGs are only resized and saved losslessly.
    | - pngquant_binary: path to pngquant, or null to look for it in the PATH
    |   and the usual install directories.
    | - keep_originals_days: null keeps originals forever.
    |
    | Changing any of these makes the analysed results out of date, so analyse
    | again afterwards.
    |
    */

    'compression' => [
        'enabled' => true,
        'threshold_percent' => 1,
        'max_dimension' => 3840,
        'dpi' => 72,
        'jpg_quality' => 82,
        'webp_quality' => 80,
        'png_quality' => '70-90',
        'pngquant_binary' => null,
        'keep_originals_days' => 30,
    ],

PHP;

    public function shouldUpdate($newVersion, $oldVersion): bool
    {
        return $this->isUpdatingTo('1.3.0');
    }

    public function update(): void
    {
        if (! File::exists($path = config_path('statamic/asset-usage.php'))) {
            return;
        }

        $config = File::get($path);

        if (preg_match('/\''.self::KEY.'\'\s*=>/', $config) === 1) {
            return;
        }

        if (! $patched = $this->append($config)) {
            $this->console()->warn(sprintf(
                'Asset Usage could not find where %s returns its settings, so `%s` was not added. The addon falls back to its own defaults, so nothing is broken; copy the key over from the addon if you want to change it.',
                $path,
                self::KEY
            ));

            return;
        }

        File::put($path, $patched);

        $this->console()->info(sprintf(
            'Asset Usage added `%s` to your published config. It controls which images the Tools page offers to compress and how.',
            self::KEY
        ));
    }

    /**
     * Appended as the last setting, rather than next to the one it relates to.
     * Where a published config keeps its keys is the site's business, and the
     * comment block says what this one is for wherever it lands.
     *
     * @return string|null null when the array can't be located
     */
    private function append(string $config): ?string
    {
        $lines = explode("\n", $config);

        // The array `return [` opens, closed on a line of its own.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/^\];\s*$/', $lines[$i]) === 1) {
                return $this->insertBefore($lines, $i, explode("\n", self::BLOCK));
            }
        }

        return null;
    }
}
