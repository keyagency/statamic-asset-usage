<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Scope;

class UsageFilterTest extends TestCase
{
    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    private function filter(string $container = 'assets')
    {
        return Scope::find('asset_usage', ['container' => $container]);
    }

    /** @return string[] the paths the filter leaves in the query */
    private function paths(string $usage, string $container = 'assets'): array
    {
        $query = AssetContainer::findByHandle($container)->queryAssets();

        $this->filter($container)->apply($query, ['usage' => $usage]);

        return $query->get()->map->path()->sort()->values()->all();
    }

    #[Test]
    public function it_is_offered_in_the_asset_browser()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        $handles = Scope::filters('assets', ['container' => 'assets'])->map->handle();

        $this->assertTrue($handles->contains('asset_usage'));
    }

    #[Test]
    public function it_is_hidden_elsewhere_in_the_cp()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home']);
        $this->build();

        $handles = Scope::filters('entries', ['collection' => 'pages'])->map->handle();

        $this->assertFalse($handles->contains('asset_usage'));
    }

    #[Test]
    public function it_is_hidden_while_the_index_is_out_of_date()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $handles = Scope::filters('assets', ['container' => 'assets'])->map->handle();

        $this->assertFalse($handles->contains('asset_usage'));
    }

    #[Test]
    public function it_is_hidden_for_a_container_that_is_not_enabled()
    {
        config(['statamic.asset-usage.containers' => ['documents']]);

        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeContainer('documents', ['docs/report.pdf'], '/documents');
        $this->build();

        $this->assertFalse(
            Scope::filters('assets', ['container' => 'assets'])->map->handle()->contains('asset_usage')
        );
        $this->assertTrue(
            Scope::filters('assets', ['container' => 'documents'])->map->handle()->contains('asset_usage')
        );
    }

    #[Test]
    public function it_narrows_the_browser_down_to_unused_assets()
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg', 'docs/orphan.pdf']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->assertSame(['docs/orphan.pdf', 'img/unused.jpg'], $this->paths('unused'));
    }

    #[Test]
    public function it_narrows_the_browser_down_to_used_assets()
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->assertSame(['img/used.jpg'], $this->paths('used'));
    }

    #[Test]
    public function it_returns_nothing_rather_than_everything_when_no_asset_matches()
    {
        $this->makeContainer('assets', ['img/used.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->assertSame([], $this->paths('unused'));
    }

    #[Test]
    public function it_only_considers_the_container_being_browsed()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->makeContainer('documents', ['docs/orphan.pdf'], '/documents');
        $this->build();

        $this->assertSame(['img/unused.jpg'], $this->paths('unused'));
        $this->assertSame(['docs/orphan.pdf'], $this->paths('unused', 'documents'));
    }
}
