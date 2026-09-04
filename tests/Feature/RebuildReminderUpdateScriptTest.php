<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\File;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\UpdateScripts\AddRebuildReminderToPublishedConfig;
use KeyAgency\AssetUsage\UpdateScripts\AddScannedTypesToPublishedConfig;
use PHPUnit\Framework\Attributes\Test;

class RebuildReminderUpdateScriptTest extends TestCase
{
    private function path(): string
    {
        return config_path('statamic/asset-usage.php');
    }

    private function publish(string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), $contents);
    }

    private function config(): array
    {
        return require $this->path();
    }

    private function runScript(): void
    {
        (new AddRebuildReminderToPublishedConfig('keyagency/statamic-asset-usage'))->update();
    }

    /** The shipped config with the key this release introduced taken back out. */
    private function shippedWithout(): string
    {
        $shipped = File::get(__DIR__.'/../../config/asset-usage.php');

        $stripped = preg_replace("/\n    \/\*\n    \|-+\n    \| Rebuild Reminder\n.*?\n    \],\n/s", '', $shipped);

        $this->assertStringNotContainsString('rebuild_reminder_days', $stripped);

        return $stripped;
    }

    #[Test]
    public function it_adds_the_key_a_published_config_is_missing()
    {
        $this->publish($this->shippedWithout());

        $this->runScript();

        $this->assertSame(
            ['auto_update' => 30, 'manual' => 7],
            $this->config()['rebuild_reminder_days']
        );
    }

    /**
     * The rest of the file is not the script's business, comments included.
     */
    #[Test]
    public function it_leaves_everything_else_in_the_file_alone()
    {
        $this->publish($this->shippedWithout());

        $this->runScript();

        $config = $this->config();
        $written = File::get($this->path());

        $this->assertSame('*', $config['containers']);
        $this->assertTrue($config['scan_urls']);
        $this->assertSame([], $config['ignore']);
        $this->assertStringContainsString('Scanned Content Types', $written);
        $this->assertStringContainsString('Minimum Age', $written);

        // One blank line between settings, the same as everywhere else in the file.
        $this->assertStringNotContainsString("\n\n\n", $written);
    }

    #[Test]
    public function it_leaves_a_config_that_already_has_the_key_untouched()
    {
        $this->publish("<?php\n\nreturn [\n\n    'rebuild_reminder_days' => [\n        'auto_update' => 90,\n        'manual' => 2,\n    ],\n\n];\n");
        $before = File::get($this->path());

        $this->runScript();

        $this->assertSame($before, File::get($this->path()));
        $this->assertSame(['auto_update' => 90, 'manual' => 2], $this->config()['rebuild_reminder_days']);
    }

    #[Test]
    public function it_does_nothing_when_the_config_was_never_published()
    {
        $this->runScript();

        $this->assertFalse(File::exists($this->path()));
    }

    /**
     * A config written on one line is not something to guess at.
     */
    #[Test]
    public function it_leaves_a_config_it_cannot_read_alone()
    {
        $this->publish("<?php\n\nreturn ['containers' => '*'];\n");
        $before = File::get($this->path());

        $this->runScript();

        $this->assertSame($before, File::get($this->path()));
    }

    /**
     * A site coming from 1.0.x runs both scripts in the same pass, so neither
     * may undo or trip over the other's edit.
     */
    #[Test]
    public function it_works_alongside_the_scanned_types_script()
    {
        $old = preg_replace(
            "/^ +'(collection_cascades|taxonomy_cascades|addon_settings|blueprints)' => true,\n/m",
            '',
            $this->shippedWithout()
        );

        $this->publish($old);

        (new AddScannedTypesToPublishedConfig('keyagency/statamic-asset-usage'))->update();
        $this->runScript();

        $config = $this->config();

        $this->assertSame(['auto_update' => 30, 'manual' => 7], $config['rebuild_reminder_days']);
        $this->assertTrue($config['scanned_types']['blueprints']);
        $this->assertTrue($config['scanned_types']['entries']);
    }
}
