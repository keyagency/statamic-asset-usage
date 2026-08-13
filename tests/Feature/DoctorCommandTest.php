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
            ->expectsOutputToContain('Asset query — what this addon indexes')
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
}
