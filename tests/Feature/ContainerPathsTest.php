<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\Containers;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\AssetContainer;

class ContainerPathsTest extends TestCase
{
    #[Test]
    public function it_reads_paths_from_the_asset_query_and_not_from_the_file_listing()
    {
        $this->makeContainer('assets', ['root.jpg', 'logos/one.jpg', 'icons/deep/two.svg']);

        $real = AssetContainer::findByHandle('assets');

        /*
         * Stands in for a container on the eloquent driver, whose file listing
         * reports the root only. Everything below it has to come through
         * queryAssets() or assets in folders go missing.
         */
        $container = Mockery::mock($real)->makePartial();
        $container->shouldReceive('files')->andReturn(collect(['root.jpg']));

        $ids = (new Containers(collect([$container])))->assetIds();

        sort($ids);

        $this->assertSame([
            'assets::icons/deep/two.svg',
            'assets::logos/one.jpg',
            'assets::root.jpg',
        ], $ids);
    }

    #[Test]
    public function it_resolves_a_bare_path_inside_a_folder()
    {
        $this->makeContainer('assets', ['logos/one.jpg']);

        $references = Containers::make()->resolveBarePath('logos/one.jpg');

        $this->assertCount(1, $references);
        $this->assertSame('assets::logos/one.jpg', $references[0]->id());
    }
}
