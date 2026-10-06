<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Listeners\InjectUsageField;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;

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

    /**
     * Headed with the addon's name, so the panel reads as part of the addon and
     * not as one of the container's own fields.
     */
    #[Test]
    public function the_panel_sits_in_a_section_of_its_own()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $sections = fn () => collect(Asset::find('assets::img/photo.jpg')->blueprint()->contents()['tabs'])
            ->flatMap(fn ($tab) => $tab['sections'] ?? []);

        $section = $sections()->first(fn ($section) => collect($section['fields'] ?? [])->contains('handle', InjectUsageField::HANDLE));

        $this->assertSame(__('asset-usage::messages.nav_title'), $section['display']);
        $this->assertCount(1, $section['fields']);

        // Looking the blueprint up again doesn't add a second one.
        $this->assertSame(1, $sections()->flatMap(fn ($section) => $section['fields'] ?? [])->where('handle', InjectUsageField::HANDLE)->count());
    }

    #[Test]
    public function without_the_panel_the_field_gets_no_section()
    {
        config(['statamic.asset-usage.editor_panel' => false]);
        $this->makeContainer('assets', ['img/photo.jpg']);

        $displays = collect(Asset::find('assets::img/photo.jpg')->blueprint()->contents()['tabs'])
            ->flatMap(fn ($tab) => $tab['sections'] ?? [])
            ->pluck('display')
            ->filter();

        $this->assertNotContains(__('asset-usage::messages.nav_title'), $displays);
        $this->assertTrue(Asset::find('assets::img/photo.jpg')->blueprint()->hasField(InjectUsageField::HANDLE));
    }

    /**
     * Sorted by how often an asset is used, on the Stache's own index, which
     * is dropped when the usage changes so the order follows the content.
     */
    #[Test]
    public function the_column_sorts_by_usage_in_the_asset_browser()
    {
        $this->makeContainer('assets', ['img/a.jpg', 'img/b.jpg', 'img/c.jpg']);
        $this->makeEntry('one', ['title' => 'One', 'hero' => 'img/b.jpg', 'thumb' => 'img/c.jpg']);
        $this->makeEntry('two', ['title' => 'Two', 'hero' => 'img/b.jpg']);
        $this->build();

        $browse = function (string $order) {
            // Each request starts with an empty Blink, so the blueprint is resolved for that request.
            Blink::flush();

            return $this->actingAs($this->superUser())
                ->getJson('/'.config('statamic.cp.route').'/assets/browse/folders/assets/img?sort=asset_usage&order='.$order);
        };
        $paths = fn ($response) => collect($response->json('data'))->pluck('path')->all();

        $response = $browse('desc');

        $this->assertTrue(collect($response->json('meta.columns'))->firstWhere('field', InjectUsageField::HANDLE)['sortable']);
        $this->assertSame(['img/b.jpg', 'img/c.jpg', 'img/a.jpg'], $paths($response));
        $this->assertSame(['img/a.jpg', 'img/c.jpg', 'img/b.jpg'], $paths($browse('asc')));

        foreach (['three', 'four', 'five'] as $slug) {
            $this->makeEntry($slug, ['title' => ucfirst($slug), 'hero' => 'img/a.jpg']);
        }

        $this->assertSame('img/a.jpg', $paths($browse('desc'))[0]);
    }
}
