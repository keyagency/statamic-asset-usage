<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Listeners\InjectUsageField;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class UsageFieldTest extends TestCase
{
    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    #[Test]
    public function it_adds_the_field_to_an_asset_container_blueprint()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $blueprint = AssetContainer::findByHandle('assets')->blueprint();

        $this->assertTrue($blueprint->hasField(InjectUsageField::HANDLE));
        $this->assertSame('computed', $blueprint->field(InjectUsageField::HANDLE)->visibility());
    }

    #[Test]
    public function it_leaves_containers_that_are_not_enabled_alone()
    {
        config(['statamic.asset-usage.containers' => ['documents']]);

        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertFalse(AssetContainer::findByHandle('assets')->blueprint()->hasField(InjectUsageField::HANDLE));
    }

    #[Test]
    public function it_does_not_add_the_field_when_both_places_are_turned_off()
    {
        config([
            'statamic.asset-usage.editor_panel' => false,
            'statamic.asset-usage.listing_column' => false,
        ]);

        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertFalse(AssetContainer::findByHandle('assets')->blueprint()->hasField(InjectUsageField::HANDLE));
    }

    #[Test]
    public function it_becomes_a_column_in_the_asset_browser()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $columns = AssetContainer::findByHandle('assets')->blueprint()->columns();

        $this->assertTrue($columns->keyBy->field()->has(InjectUsageField::HANDLE));

        $column = $columns->keyBy->field()->get(InjectUsageField::HANDLE);

        $this->assertTrue($column->listable());
        $this->assertTrue($column->visible());
        // Computed fields can't be sorted on, because the query knows nothing about them.
        $this->assertFalse($column->sortable());
    }

    #[Test]
    public function it_is_not_a_column_when_the_listing_column_is_turned_off()
    {
        config(['statamic.asset-usage.listing_column' => false]);

        $this->makeContainer('assets', ['img/photo.jpg']);

        $columns = AssetContainer::findByHandle('assets')->blueprint()->columns()->rejectUnlisted();

        $this->assertFalse($columns->keyBy->field()->has(InjectUsageField::HANDLE));
    }

    #[Test]
    public function the_panel_knows_where_an_asset_is_used()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->build();

        $meta = Asset::find('assets::img/photo.jpg')
            ->blueprint()
            ->field(InjectUsageField::HANDLE)
            ->meta();

        $this->assertTrue($meta['indexed']);
        $this->assertSame(1, $meta['count']);
        $this->assertSame('Home', $meta['usages'][0]['title']);
        $this->assertSame('hero', $meta['usages'][0]['field']);
    }

    #[Test]
    public function the_panel_reports_an_unused_asset()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        $meta = Asset::find('assets::img/photo.jpg')
            ->blueprint()
            ->field(InjectUsageField::HANDLE)
            ->meta();

        $this->assertTrue($meta['indexed']);
        $this->assertSame(0, $meta['count']);
        $this->assertSame([], $meta['usages']);
    }

    #[Test]
    public function the_panel_says_so_when_there_is_no_index()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $meta = Asset::find('assets::img/photo.jpg')
            ->blueprint()
            ->field(InjectUsageField::HANDLE)
            ->meta();

        $this->assertFalse($meta['indexed']);
        $this->assertSame(0, $meta['count']);
    }

    /** The column renders a single icon, so it only needs to know the count. */
    private function columnValue(string $id): array
    {
        $asset = Asset::find($id);

        return $asset->blueprint()
            ->field(InjectUsageField::HANDLE)
            ->setValue(null)
            ->setParent($asset)
            ->preProcessIndex()
            ->value();
    }

    #[Test]
    public function the_column_reports_a_used_asset()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->build();

        $this->assertSame(['indexed' => true, 'count' => 1], $this->columnValue('assets::img/photo.jpg'));
    }

    #[Test]
    public function the_column_reports_an_unused_asset()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        $this->assertSame(['indexed' => true, 'count' => 0], $this->columnValue('assets::img/photo.jpg'));
    }

    #[Test]
    public function the_column_reports_that_there_is_no_index()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->assertSame(['indexed' => false, 'count' => 0], $this->columnValue('assets::img/photo.jpg'));
    }

    #[Test]
    public function updating_an_asset_never_writes_the_field_into_its_data()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);
        $this->build();

        $asset = Asset::find('assets::img/photo.jpg');

        $this
            ->actingAs($this->superUser())
            ->patchJson(cp_route('assets.update', ['encoded_asset' => base64_encode($asset->id())]), [
                'alt' => 'A photo',
            ])
            ->assertOk();

        $data = Asset::find('assets::img/photo.jpg')->data()->all();

        $this->assertSame('A photo', $data['alt']);
        $this->assertArrayNotHasKey(InjectUsageField::HANDLE, $data);
    }
}
