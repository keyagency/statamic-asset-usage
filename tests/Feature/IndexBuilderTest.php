<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

class IndexBuilderTest extends TestCase
{
    private function build()
    {
        return (new IndexBuilder(new IndexStore))->build();
    }

    #[Test]
    public function it_records_an_asset_used_in_an_entry()
    {
        $this->makeContainer('assets', ['img/photo.jpg', 'img/unused.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);

        $index = $this->build();

        $this->assertTrue($index->isUsed('assets::img/photo.jpg'));
        $this->assertFalse($index->isUsed('assets::img/unused.jpg'));

        $usage = $index->for('assets::img/photo.jpg')[0];
        $this->assertSame('entry', $usage->type);
        $this->assertSame('Home', $usage->title);
        $this->assertSame('hero', $usage->field);
        $this->assertNotNull($usage->editUrl);
    }

    #[Test]
    public function it_records_the_site_an_asset_is_used_in()
    {
        $this->setSites([
            'en' => ['url' => '/', 'locale' => 'en_US', 'name' => 'English'],
            'nl' => ['url' => '/nl/', 'locale' => 'nl_NL', 'name' => 'Nederlands'],
        ]);

        $this->makeContainer('assets', ['img/photo.jpg', 'img/dutch.jpg']);

        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg'], 'pages', 'en');
        $this->makeEntry('thuis', ['title' => 'Thuis', 'hero' => 'img/dutch.jpg'], 'pages', 'nl');

        $index = $this->build();

        $this->assertSame(['en'], $index->sitesFor('assets::img/photo.jpg'));
        $this->assertSame(['nl'], $index->sitesFor('assets::img/dutch.jpg'));
    }

    #[Test]
    public function it_records_one_asset_used_in_two_sites()
    {
        $this->setSites([
            'en' => ['url' => '/', 'locale' => 'en_US', 'name' => 'English'],
            'nl' => ['url' => '/nl/', 'locale' => 'nl_NL', 'name' => 'Nederlands'],
        ]);

        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg'], 'pages', 'en');
        $this->makeEntry('thuis', ['title' => 'Thuis', 'hero' => 'img/photo.jpg'], 'pages', 'nl');

        $index = $this->build();

        $this->assertSame(2, $index->countFor('assets::img/photo.jpg'));
        $this->assertSame(['en', 'nl'], $index->sitesFor('assets::img/photo.jpg'));
    }

    #[Test]
    public function it_records_an_asset_used_in_a_global_set()
    {
        $this->makeContainer('assets', ['img/logo.svg']);

        $set = tap(GlobalSet::make('branding')->title('Branding'))->save();
        $set->makeLocalization(Site::default()->handle())->data(['logo' => 'img/logo.svg'])->save();

        $index = $this->build();

        $usage = $index->for('assets::img/logo.svg')[0];
        $this->assertSame('global', $usage->type);
        $this->assertSame('Branding', $usage->title);
    }

    #[Test]
    public function it_records_an_asset_used_in_a_taxonomy_term()
    {
        $this->makeContainer('assets', ['img/tag.jpg']);

        Taxonomy::make('topics')->title('Topics')->save();
        Term::make('design')->taxonomy('topics')->data(['title' => 'Design', 'icon' => 'img/tag.jpg'])->save();

        $index = $this->build();

        $usage = $index->for('assets::img/tag.jpg')[0];
        $this->assertSame('term', $usage->type);
        $this->assertSame('Design', $usage->title);
    }

    #[Test]
    public function it_records_an_asset_used_in_a_navigation()
    {
        $this->makeContainer('assets', ['docs/brochure.pdf']);

        $nav = tap(Nav::make()->handle('main')->title('Main Navigation'))->save();
        $nav->makeTree(Site::default()->handle(), [
            ['id' => 'node-1', 'title' => 'Brochure', 'url' => 'asset::assets::docs/brochure.pdf'],
        ])->save();

        $index = $this->build();

        $usage = $index->for('assets::docs/brochure.pdf')[0];
        $this->assertSame('nav', $usage->type);
        $this->assertSame('Main Navigation', $usage->title);
    }

    #[Test]
    public function it_records_an_asset_used_on_a_user()
    {
        $this->makeContainer('assets', ['img/avatar.jpg']);

        User::make()->email('robin@example.com')->data(['avatar' => 'img/avatar.jpg'])->save();

        $index = $this->build();

        $usage = $index->for('assets::img/avatar.jpg')[0];
        $this->assertSame('user', $usage->type);
    }

    #[Test]
    public function it_records_an_asset_referenced_by_another_asset()
    {
        $this->makeContainer('assets', ['img/photo.jpg', 'img/thumbnail.jpg']);
        $this->makeAsset('assets', 'img/photo.jpg', ['thumbnail' => 'img/thumbnail.jpg']);

        $index = $this->build();

        $usage = $index->for('assets::img/thumbnail.jpg')[0];
        $this->assertSame('asset', $usage->type);
        $this->assertSame('img/photo.jpg', $usage->title);
    }

    #[Test]
    public function it_records_an_asset_uploaded_through_a_form()
    {
        $this->makeContainer('assets', ['uploads/cv.pdf']);

        $form = tap(Form::make('apply')->title('Apply'))->save();
        $form->makeSubmission()->data(['cv' => 'uploads/cv.pdf'])->save();

        $index = $this->build();

        $usage = $index->for('assets::uploads/cv.pdf')[0];
        $this->assertSame('form_submission', $usage->type);
    }

    #[Test]
    public function it_skips_content_types_that_are_turned_off()
    {
        config(['statamic.asset-usage.scanned_types.users' => false]);

        $this->makeContainer('assets', ['img/avatar.jpg']);
        User::make()->email('robin@example.com')->data(['avatar' => 'img/avatar.jpg'])->save();

        $this->assertFalse($this->build()->isUsed('assets::img/avatar.jpg'));
    }

    /**
     * A published config replaces the whole `scanned_types` array, so listing
     * one type has to mean that type only.
     */
    #[Test]
    public function a_scanned_types_config_is_the_complete_set()
    {
        config(['statamic.asset-usage.scanned_types' => ['entries' => true]]);

        $this->makeContainer('assets', ['img/photo.jpg', 'img/logo.svg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);

        $set = tap(GlobalSet::make('branding')->title('Branding'))->save();
        $set->makeLocalization(Site::default()->handle())->data(['logo' => 'img/logo.svg'])->save();

        $index = $this->build();

        $this->assertTrue($index->isUsed('assets::img/photo.jpg'));
        $this->assertFalse($index->isUsed('assets::img/logo.svg'));
    }

    #[Test]
    public function it_writes_a_readable_index_to_storage()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);

        $this->build();

        $store = new IndexStore;

        $this->assertTrue($store->exists());
        $this->assertFalse($store->isStale());
        $this->assertSame(IndexStore::STATE_READY, $store->meta()['state']);
        $this->assertSame(['assets'], $store->meta()['containers']);
        $this->assertTrue($store->index()->isUsed('assets::img/photo.jpg'));
    }

    #[Test]
    public function an_index_built_for_other_containers_is_stale()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        $store = new IndexStore;
        $this->assertFalse($store->isStale());

        $this->makeContainer('documents', ['docs/report.pdf'], '/documents');

        $this->assertTrue($store->isStale());
    }

    #[Test]
    public function an_index_built_with_different_url_scanning_is_stale()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        config(['statamic.asset-usage.scan_urls' => false]);

        $this->assertTrue((new IndexStore)->isStale());
    }

    #[Test]
    public function an_index_built_for_other_content_types_is_stale()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        $store = new IndexStore;
        $this->assertFalse($store->isStale());
        $this->assertSame(Settings::SCANNABLE_TYPES, $store->meta()['scanned_types']);

        config(['statamic.asset-usage.scanned_types' => ['entries' => true]]);

        $this->assertTrue($store->isStale());
    }

    #[Test]
    public function an_index_built_with_different_working_copy_handling_is_stale()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        config(['statamic.asset-usage.include_working_copies' => false]);

        $this->assertTrue((new IndexStore)->isStale());
    }

    #[Test]
    public function there_is_no_index_before_one_is_built()
    {
        $store = new IndexStore;

        $this->assertFalse($store->exists());
        $this->assertTrue($store->isStale());
        $this->assertNull($store->index());
    }
}
