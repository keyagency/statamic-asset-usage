<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;

class DoctorCommandTest extends TestCase
{
    #[Test]
    public function it_reports_what_each_container_holds()
    {
        $this->makeContainer('assets', ['root.jpg', 'logos/one.jpg', 'logos/two.jpg', 'icons/deep/three.svg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'logos/one.jpg']);
        (new IndexBuilder(new IndexStore))->build();

        $this->artisan('statamic:asset-usage:doctor')
            ->expectsOutputToContain('Container: assets')
            ->expectsOutputToContain('Asset query (what this addon indexes)')
            ->assertSuccessful();
    }

    #[Test]
    public function it_counts_root_and_nested_files_apart()
    {
        $this->makeContainer('assets', ['root.jpg', 'logos/one.jpg', 'logos/two.jpg', 'icons/deep/three.svg']);

        $output = $this->withoutMockingConsoleOutput()
            ->artisan('statamic:asset-usage:doctor', ['--folders' => true]);

        $this->assertSame(0, $output);

        $text = Artisan::output();

        // 4 files, 3 of them inside folders, across 3 folders.
        $this->assertMatchesRegularExpression('/what this addon indexes\D+4 files \(3 nested\)/', $text);
        $this->assertStringContainsString('icons/deep', $text);
        $this->assertStringContainsString('(root)', $text);
    }

    #[Test]
    public function it_fails_without_an_enabled_container()
    {
        config(['statamic.asset-usage.containers' => ['nope']]);

        $this->makeContainer('assets', ['root.jpg']);

        $this->artisan('statamic:asset-usage:doctor')->assertFailed();
    }

    /**
     * A published config replaces `scanned_types` wholesale, so a config
     * written before a type existed silently switches that type off.
     */
    #[Test]
    public function it_names_the_scanned_types_a_published_config_is_missing()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        config(['statamic.asset-usage.scanned_types' => ['entries' => true]]);

        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('statamic:asset-usage:doctor'));

        $text = Artisan::output();

        $this->assertStringContainsString('not in your config', $text);
        $this->assertStringContainsString('addon_settings', $text);
        $this->assertStringContainsString('blueprints', $text);
    }

    #[Test]
    public function it_does_not_complain_when_every_scanned_type_is_configured()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $output = $this->withoutMockingConsoleOutput()->artisan('statamic:asset-usage:doctor');

        $this->assertSame(0, $output);
        $this->assertStringNotContainsString('not in your config', Artisan::output());
    }
}
