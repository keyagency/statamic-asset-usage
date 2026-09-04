<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\File;
use KeyAgency\AssetUsage\Support\Settings;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
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
    public function it_records_an_asset_used_in_a_collection_cascade()
    {
        $this->makeContainer('assets', ['img/fallback.jpg']);
        $this->makeEntry('home', ['title' => 'Home']);

        Collection::findByHandle('pages')->cascade(['seo' => ['image' => 'img/fallback.jpg']])->save();

        $index = $this->build();

        $usage = $index->for('assets::img/fallback.jpg')[0];
        $this->assertSame('collection', $usage->type);
        $this->assertSame('Pages', $usage->title);
        $this->assertSame('seo.image', $usage->field);
        $this->assertNotNull($usage->editUrl);
    }

    #[Test]
    public function it_records_an_asset_used_in_a_taxonomy_cascade()
    {
        $this->makeContainer('assets', ['img/topic.jpg']);

        Taxonomy::make('topics')->title('Topics')->cascade(['seo' => ['image' => 'img/topic.jpg']])->save();

        $index = $this->build();

        $usage = $index->for('assets::img/topic.jpg')[0];
        $this->assertSame('taxonomy', $usage->type);
        $this->assertSame('Topics', $usage->title);
        $this->assertSame('seo.image', $usage->field);
    }

    /**
     * Addons keep their own settings in `resources/addons/{slug}.yaml`, which
     * is where a site-wide default image tends to live.
     */
    #[Test]
    public function it_records_an_asset_used_in_an_addons_settings()
    {
        $this->makeContainer('assets', ['img/social.jpg']);

        $this->fakeAddon('acme/seo-thing', ['site_defaults' => ['image' => 'img/social.jpg']]);

        $index = $this->build();

        $usage = $index->for('assets::img/social.jpg')[0];
        $this->assertSame('addon_settings', $usage->type);
        $this->assertSame('site_defaults.image', $usage->field);
    }

    /**
     * Statamic saves addon settings under the addon's slug but reads them back
     * under its package name, so an addon that overrides its slug can have a
     * settings file it cannot resolve. That is the addon's problem, not a
     * reason for the whole scan to die.
     */
    #[Test]
    public function an_addon_whose_settings_cannot_be_read_does_not_break_the_scan()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);

        File::ensureDirectoryExists(resource_path('addons'));
        File::put(resource_path('addons/statamic-asset-usage.yaml'), "site_defaults:\n  image: img/photo.jpg\n");

        $this->assertTrue($this->build()->isUsed('assets::img/photo.jpg'));
    }

    /**
     * A `default` in a blueprint holds the asset until an entry is saved over
     * it, and on a field nobody has touched it holds it forever.
     */
    #[Test]
    public function it_records_an_asset_set_as_a_default_in_an_entry_blueprint()
    {
        $this->makeContainer('assets', ['img/placeholder.jpg']);
        $this->makeBlueprint('collections/pages', 'pages', ['hero' => 'img/placeholder.jpg']);
        $this->makeEntry('home', ['title' => 'Home']);

        $index = $this->build();

        $usage = $index->for('assets::img/placeholder.jpg')[0];
        $this->assertSame('blueprint', $usage->type);
        $this->assertSame('hero', $usage->field);
        $this->assertNotNull($usage->editUrl);
    }

    #[Test]
    public function it_records_a_default_nested_in_a_grid_field()
    {
        $this->makeContainer('assets', ['img/row.jpg']);
        Blueprint::make('pages')->setNamespace('collections/pages')->setContents([
            'tabs' => ['main' => ['fields' => [[
                'handle' => 'rows',
                'field' => [
                    'type' => 'grid',
                    'fields' => [[
                        'handle' => 'image',
                        'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'default' => 'img/row.jpg'],
                    ]],
                ],
            ]]]],
        ])->save();

        $this->makeEntry('home', ['title' => 'Home']);

        $usage = $this->build()->for('assets::img/row.jpg')[0];
        $this->assertSame('blueprint', $usage->type);
        $this->assertSame('rows.image', $usage->field);
    }

    #[Test]
    public function it_records_an_asset_set_as_a_default_in_a_term_blueprint()
    {
        $this->makeContainer('assets', ['img/term-default.jpg']);
        Taxonomy::make('topics')->title('Topics')->save();

        $this->makeBlueprint('taxonomies/topics', 'topics', ['icon' => 'img/term-default.jpg']);

        $this->assertSame('blueprint', $this->build()->for('assets::img/term-default.jpg')[0]->type);
    }

    #[Test]
    public function it_records_an_asset_set_as_a_default_in_a_global_set_blueprint()
    {
        $this->makeContainer('assets', ['img/global-default.jpg']);
        GlobalSet::make('branding')->title('Branding')->save();

        $this->makeBlueprint('globals', 'branding', ['logo' => 'img/global-default.jpg']);

        $this->assertSame('blueprint', $this->build()->for('assets::img/global-default.jpg')[0]->type);
    }

    #[Test]
    public function it_records_an_asset_set_as_a_default_in_an_asset_container_blueprint()
    {
        $this->makeContainer('assets', ['img/container-default.jpg']);

        $this->makeBlueprint('assets', 'assets', ['fallback' => 'img/container-default.jpg']);

        $this->assertSame('blueprint', $this->build()->for('assets::img/container-default.jpg')[0]->type);
    }

    #[Test]
    public function it_records_an_asset_set_as_a_default_in_a_form_blueprint()
    {
        $this->makeContainer('assets', ['img/form-default.jpg']);
        Form::make('apply')->title('Apply')->save();

        $this->makeBlueprint('forms', 'apply', ['attachment' => 'img/form-default.jpg']);

        $this->assertSame('blueprint', $this->build()->for('assets::img/form-default.jpg')[0]->type);
    }

    #[Test]
    public function it_records_an_asset_set_as_a_default_in_a_nav_blueprint()
    {
        $this->makeContainer('assets', ['img/nav-default.jpg']);
        Nav::make()->handle('main')->title('Main Navigation')->save();

        $this->makeBlueprint('navigation', 'main', ['icon' => 'img/nav-default.jpg']);

        $this->assertSame('blueprint', $this->build()->for('assets::img/nav-default.jpg')[0]->type);
    }

    #[Test]
    public function it_records_an_asset_set_as_a_default_in_the_user_blueprint()
    {
        $this->makeContainer('assets', ['img/user-default.jpg']);

        $this->makeBlueprint(null, 'user', ['avatar' => 'img/user-default.jpg']);

        $this->assertSame('blueprint', $this->build()->for('assets::img/user-default.jpg')[0]->type);
    }

    /**
     * A default is a value, not more blueprint, so nothing inside it is read as
     * field configuration. A field handled `default` in there would otherwise
     * take the place of the default it sits in, and the rest of that default
     * would go with it.
     */
    #[Test]
    public function it_keeps_the_whole_default_when_the_value_has_a_default_of_its_own()
    {
        $this->makeContainer('assets', ['img/logo.jpg', 'img/fallback.jpg']);
        Blueprint::make('pages')->setNamespace('collections/pages')->setContents([
            'tabs' => ['main' => ['fields' => [[
                'handle' => 'settings',
                'field' => [
                    'type' => 'group',
                    'default' => [
                        'logo' => 'img/logo.jpg',
                        'default' => 'img/fallback.jpg',
                    ],
                ],
            ]]]],
        ])->save();

        $this->makeEntry('home', ['title' => 'Home']);

        $index = $this->build();

        $this->assertTrue($index->isUsed('assets::img/logo.jpg'));
        $this->assertTrue($index->isUsed('assets::img/fallback.jpg'));
    }

    /**
     * A fieldset's defaults are scanned on the fieldset itself, so an asset in
     * one is found whether or not a blueprint happens to import it.
     */
    #[Test]
    public function it_records_an_asset_set_as_a_default_in_a_fieldset()
    {
        $this->makeContainer('assets', ['img/fieldset-default.jpg']);

        Fieldset::make('seo')->setContents(['title' => 'Seo', 'fields' => [[
            'handle' => 'share_image',
            'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'default' => 'img/fieldset-default.jpg'],
        ]]])->save();

        $usage = $this->build()->for('assets::img/fieldset-default.jpg')[0];
        $this->assertSame('fieldset', $usage->type);
        $this->assertSame('share_image', $usage->field);
    }

    /**
     * `Item::$title` is not nullable, and a user with neither a name nor an
     * email would otherwise take the whole build down with a TypeError.
     */
    #[Test]
    public function a_user_with_nothing_to_call_it_by_does_not_break_the_scan()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);

        User::make()->save();

        $this->assertTrue($this->build()->isUsed('assets::img/photo.jpg'));
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

    /**
     * A global set and a navigation can share a handle, and their blueprints
     * are scanned in the same pass.
     */
    #[Test]
    public function it_scans_the_blueprints_of_two_owners_that_share_a_handle()
    {
        $this->makeContainer('assets', ['img/global.jpg', 'img/nav.jpg']);

        GlobalSet::make('main')->title('Main')->save();
        Nav::make()->handle('main')->title('Main Navigation')->save();

        $this->makeBlueprint('globals', 'main', ['logo' => 'img/global.jpg']);
        $this->makeBlueprint('navigation', 'main', ['icon' => 'img/nav.jpg']);

        $index = $this->build();

        $this->assertTrue($index->isUsed('assets::img/global.jpg'));
        $this->assertTrue($index->isUsed('assets::img/nav.jpg'));
    }
}
