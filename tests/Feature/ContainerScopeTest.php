<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `containers` config is meant to be a hard boundary: a container that isn't
 * listed should be invisible everywhere in the addon.
 */
class ContainerScopeTest extends TestCase
{
    private function setUpTwoContainers(): void
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeContainer('documents', ['docs/report.pdf'], '/documents');
    }

    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    #[Test]
    public function the_tools_page_only_offers_the_configured_containers()
    {
        config(['statamic.asset-usage.containers' => ['documents']]);

        $this->setUpTwoContainers();
        $this->build();

        $response = $this->actingAs($this->superUser())
            ->get(cp_route('asset-usage.index'))
            ->assertOk();

        $containers = collect($response->viewData('page')['props']['containers'])->pluck('handle')->all();

        $this->assertSame(['documents'], $containers);
    }

    #[Test]
    public function the_tools_page_only_lists_assets_from_the_configured_containers()
    {
        config(['statamic.asset-usage.containers' => ['documents']]);

        $this->setUpTwoContainers();
        $this->build();

        $ids = collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets'))
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame(['documents::docs/report.pdf'], $ids);
    }

    #[Test]
    public function a_container_filter_for_an_unconfigured_container_yields_nothing()
    {
        config(['statamic.asset-usage.containers' => ['documents']]);

        $this->setUpTwoContainers();
        $this->build();

        $ids = collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets').'?container=assets')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([], $ids);
    }

    #[Test]
    public function all_containers_are_offered_by_default()
    {
        $this->setUpTwoContainers();
        $this->build();

        $response = $this->actingAs($this->superUser())
            ->get(cp_route('asset-usage.index'))
            ->assertOk();

        $containers = collect($response->viewData('page')['props']['containers'])->pluck('handle')->sort()->values()->all();

        $this->assertSame(['assets', 'documents'], $containers);
    }
}
