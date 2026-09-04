<?php

namespace KeyAgency\AssetUsage\UpdateScripts;

use Illuminate\Support\Facades\File;
use Statamic\UpdateScripts\UpdateScript;

/**
 * Adds the `rebuild_reminder_days` setting this release introduced to a
 * published config.
 *
 * `Settings::rebuildReminderDays()` already falls back to the shipped numbers,
 * so unlike the `scanned_types` keys nothing misbehaves without this. It is
 * here so a published config keeps showing everything there is to configure:
 * a setting you can't see in the file is a setting you don't know you have.
 */
class AddRebuildReminderToPublishedConfig extends UpdateScript
{
    private const KEY = 'rebuild_reminder_days';

    /**
     * Written out in full, comment block and all, so a published config reads
     * the same as a freshly published one.
     */
    private const BLOCK = <<<'PHP'
    /*
    |--------------------------------------------------------------------------
    | Rebuild Reminder
    |--------------------------------------------------------------------------
    |
    | How many days the usage data may go without a full rebuild before the
    | Tools page suggests one. Two numbers, because what age means depends on
    | `auto_update` above:
    |
    | - auto_update: the index is patched on every save, so it is not drifting.
    |   The reminder is only about the few things that fire no event, such as
    |   files put on the disk outside Statamic.
    | - manual: nothing is updating the index in between, so age is exactly how
    |   far behind your content it has fallen.
    |
    | Either one set to 0 turns the reminder off.
    |
    */

    'rebuild_reminder_days' => [
        'auto_update' => 30,
        'manual' => 7,
    ],

PHP;

    public function shouldUpdate($newVersion, $oldVersion): bool
    {
        return $this->isUpdatingTo('1.1.1');
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
            'Asset Usage added `%s` to your published config. It controls when the Tools page suggests rebuilding the usage data; set either number to 0 to turn that off.',
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
                array_splice($lines, $i, 0, explode("\n", self::BLOCK));

                return implode("\n", $lines);
            }
        }

        return null;
    }
}
