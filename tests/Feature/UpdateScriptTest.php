<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\File;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\UpdateScripts\AddScannedTypesToPublishedConfig;
use PHPUnit\Framework\Attributes\Test;

class UpdateScriptTest extends TestCase
{
    private function path(): string
    {
        return config_path('statamic/asset-usage.php');
    }

    /**
     * A published config as it looked before this release: the seven types that
     * existed then, and nothing else.
     *
     * @param  string[]  $types
     */
    private function publishConfig(array $types): void
    {
        $lines = array_map(fn (string $type) => "        '{$type}' => true,", $types);

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), "<?php\n\nreturn [\n\n    'containers' => '*',\n\n    'scanned_types' => [\n".implode("\n", $lines)."\n    ],\n\n    'scan_urls' => true,\n\n];\n");
    }

    private function scannedTypes(): array
    {
        return require $this->path();
    }

    private function runScript(): void
    {
        (new AddScannedTypesToPublishedConfig('keyagency/statamic-asset-usage'))->update();
    }

    #[Test]
    public function it_adds_the_types_a_published_config_is_missing()
    {
        $this->publishConfig(['entries', 'globals', 'terms', 'navs', 'users', 'assets', 'form_submissions']);

        $this->runScript();

        $this->assertSame([
            'entries' => true,
            'globals' => true,
            'terms' => true,
            'navs' => true,
            'users' => true,
            'assets' => true,
            'form_submissions' => true,
            'collection_cascades' => true,
            'taxonomy_cascades' => true,
            'addon_settings' => true,
            'blueprints' => true,
        ], $this->scannedTypes()['scanned_types']);
    }

    /**
     * Only what is absent gets written. A type the site deliberately switched
     * off stays off, and one that is already there is not duplicated.
     */
    #[Test]
    public function it_only_adds_the_missing_types_and_leaves_the_rest_alone()
    {
        $this->publishConfig(['entries', 'blueprints']);

        $this->runScript();

        $types = $this->scannedTypes()['scanned_types'];

        $this->assertSame(['entries', 'blueprints', 'collection_cascades', 'taxonomy_cascades', 'addon_settings'], array_keys($types));
        $this->assertSame(1, substr_count(File::get($this->path()), "'blueprints'"));
    }

    #[Test]
    public function it_leaves_a_config_that_already_has_every_type_untouched()
    {
        $this->publishConfig(['entries', 'collection_cascades', 'taxonomy_cascades', 'addon_settings', 'blueprints']);
        $before = File::get($this->path());

        $this->runScript();

        $this->assertSame($before, File::get($this->path()));
    }

    #[Test]
    public function it_does_nothing_when_the_config_was_never_published()
    {
        $this->runScript();

        $this->assertFalse(File::exists($this->path()));
    }

    /**
     * A config that has been rewritten by hand is not something to guess at.
     */
    #[Test]
    public function it_leaves_a_config_it_cannot_read_alone()
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), "<?php\n\nreturn ['scanned_types' => ['entries' => true]];\n");
        $before = File::get($this->path());

        $this->runScript();

        $this->assertSame($before, File::get($this->path()));
    }

    /**
     * The shipped config, with its comment blocks and the four keys stripped
     * back out. What a site that published before this release actually has.
     */
    #[Test]
    public function it_patches_the_real_published_config_file()
    {
        $shipped = File::get(__DIR__.'/../../config/asset-usage.php');

        $old = preg_replace("/^ +'(collection_cascades|taxonomy_cascades|addon_settings|blueprints)' => true,\n/m", '', $shipped);

        $this->assertStringNotContainsString("'blueprints' => true", $old);

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), $old);

        $this->runScript();

        $config = $this->scannedTypes();

        $this->assertSame(Settings::SCANNABLE_TYPES, array_keys($config['scanned_types']));
        $this->assertSame(array_fill_keys(Settings::SCANNABLE_TYPES, true), $config['scanned_types']);

        // Everything else in the file survived untouched.
        $this->assertSame($config['containers'], '*');
        $this->assertStringContainsString('Scanned Content Types', File::get($this->path()));
    }
}
