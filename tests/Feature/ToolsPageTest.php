<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Jobs\BuildIndex;
use KeyAgency\AssetUsage\ServiceProvider;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Tests\Unit\ImageDensityTest;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Usage\Unused;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class ToolsPageTest extends TestCase
{
    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    /**
     * Saving a user builds the index on a fresh install, so it is dropped again
     * for the tests about the state before the first build.
     */
    private function superUserWithoutIndex()
    {
        return tap($this->superUser(), fn () => (new IndexStore)->delete());
    }

    private function userWith(array $permissions)
    {
        Role::make('usage')->addPermission($permissions)->save();

        return tap(User::make()->email('robin@example.com')->assignRole('usage'))->save();
    }

    #[Test]
    public function it_needs_the_view_permission()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->get(cp_route('asset-usage.index'))->assertRedirect();

        $this->actingAs($this->userWith(['access cp']))
            ->get(cp_route('asset-usage.index'))
            ->assertForbidden();

        $this->actingAs($this->userWith(['access cp', ServiceProvider::PERMISSION_VIEW]))
            ->get(cp_route('asset-usage.index'))
            ->assertOk();
    }

    #[Test]
    public function it_lists_assets_with_their_usage()
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $response = $this
            ->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.assets'))
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(1, $rows['assets::img/used.jpg']['count']);
        $this->assertSame('Home', $rows['assets::img/used.jpg']['usages'][0]['title']);
        $this->assertSame('Entry', $rows['assets::img/used.jpg']['usages'][0]['type_label']);
        $this->assertSame(0, $rows['assets::img/unused.jpg']['count']);
        $this->assertNull($rows['assets::img/unused.jpg']['blocker']);
        $this->assertNotNull($rows['assets::img/used.jpg']['blocker']);
    }

    #[Test]
    public function it_filters_by_usage_container_site_and_search()
    {
        $this->setSites([
            'en' => ['url' => '/', 'locale' => 'en_US', 'name' => 'English'],
            'nl' => ['url' => '/nl/', 'locale' => 'nl_NL', 'name' => 'Nederlands'],
        ]);

        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg']);
        $this->makeContainer('documents', ['docs/orphan.pdf'], '/documents');
        $this->makeEntry('thuis', ['title' => 'Thuis', 'hero' => 'img/used.jpg'], 'pages', 'nl');
        $this->build();

        $ids = fn (array $query) => collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets').'?'.http_build_query($query))
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame(['documents::docs/orphan.pdf', 'assets::img/unused.jpg'], $ids(['usage' => 'unused']));
        $this->assertSame(['assets::img/used.jpg'], $ids(['usage' => 'used']));
        $this->assertSame(['documents::docs/orphan.pdf'], $ids(['container' => 'documents']));
        $this->assertSame(['assets::img/used.jpg'], $ids(['site' => 'nl']));
        $this->assertSame([], $ids(['site' => 'en']));
        $this->assertSame(['documents::docs/orphan.pdf'], $ids(['search' => 'orphan']));
    }

    #[Test]
    public function it_sorts_the_overview()
    {
        $this->makeContainer('assets', ['img/beta.jpg', 'img/alpha.jpg', 'img/popular.jpg']);
        $this->makeEntry('one', ['title' => 'One', 'hero' => 'img/popular.jpg']);
        $this->makeEntry('two', ['title' => 'Two', 'hero' => 'img/popular.jpg', 'thumbnail' => 'img/beta.jpg']);
        $this->build();

        $paths = fn (array $query) => collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets').'?'.http_build_query($query))
                ->json('data')
        )->pluck('path')->all();

        $this->assertSame(['img/alpha.jpg', 'img/beta.jpg', 'img/popular.jpg'], $paths([]));
        $this->assertSame(['img/popular.jpg', 'img/beta.jpg', 'img/alpha.jpg'], $paths(['sort' => 'path', 'order' => 'desc']));
        $this->assertSame(['img/popular.jpg', 'img/beta.jpg', 'img/alpha.jpg'], $paths(['sort' => 'usage', 'order' => 'desc']));
        $this->assertSame(['img/alpha.jpg', 'img/beta.jpg', 'img/popular.jpg'], $paths(['sort' => 'usage', 'order' => 'asc']));

        // Anything unrecognised falls back to the default order.
        $this->assertSame(['img/alpha.jpg', 'img/beta.jpg', 'img/popular.jpg'], $paths(['sort' => 'nonsense']));
    }

    #[Test]
    public function it_sorts_the_overview_by_date()
    {
        $this->makeContainer('assets', ['img/old.jpg', 'img/new.jpg', 'img/middle.jpg', 'img/also-middle.jpg']);

        $this->makeContainer('documents', ['docs/recent.pdf'], '/documents');

        $ages = ['img/old.jpg' => 30, 'img/new.jpg' => 1, 'img/middle.jpg' => 10, 'img/also-middle.jpg' => 10];
        $timestamp = fn (int $days) => now()->startOfDay()->subDays($days)->timestamp;

        foreach ($ages as $path => $days) {
            touch(Storage::disk('assets')->path($path), $timestamp($days));
        }

        touch(Storage::disk('documents')->path('docs/recent.pdf'), $timestamp(5));

        $this->build();

        $paths = fn (array $query) => collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets').'?'.http_build_query($query))
                ->json('data')
        )->pluck('path')->all();

        // Dates are compared across containers, and a tie keeps name order.
        $this->assertSame(['img/new.jpg', 'docs/recent.pdf', 'img/also-middle.jpg', 'img/middle.jpg', 'img/old.jpg'], $paths(['sort' => 'last_modified', 'order' => 'desc']));
        $this->assertSame(['img/old.jpg', 'img/also-middle.jpg', 'img/middle.jpg', 'docs/recent.pdf', 'img/new.jpg'], $paths(['sort' => 'last_modified', 'order' => 'asc']));
    }

    #[Test]
    public function it_sorts_the_overview_by_file_size()
    {
        $this->makeContainer('assets', ['img/small.jpg', 'img/large.jpg', 'img/medium.jpg', 'img/also-medium.jpg']);

        $this->makeContainer('documents', ['docs/big.pdf'], '/documents');

        $sizes = ['img/small.jpg' => 10, 'img/large.jpg' => 1000, 'img/medium.jpg' => 100, 'img/also-medium.jpg' => 100];

        foreach ($sizes as $path => $bytes) {
            Storage::disk('assets')->put($path, str_repeat('x', $bytes));
        }

        Storage::disk('documents')->put('docs/big.pdf', str_repeat('x', 500));

        $this->build();

        $paths = fn (array $query) => collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets').'?'.http_build_query($query))
                ->json('data')
        )->pluck('path')->all();

        // Sizes are compared across containers, and a tie keeps name order.
        $this->assertSame(['img/large.jpg', 'docs/big.pdf', 'img/also-medium.jpg', 'img/medium.jpg', 'img/small.jpg'], $paths(['sort' => 'size', 'order' => 'desc']));
        $this->assertSame(['img/small.jpg', 'img/also-medium.jpg', 'img/medium.jpg', 'docs/big.pdf', 'img/large.jpg'], $paths(['sort' => 'size', 'order' => 'asc']));
    }

    #[Test]
    public function it_sorts_the_overview_by_resolution_and_dpi()
    {
        $this->makeContainer('assets', ['docs/manual.pdf']);
        Storage::disk('assets')->put('img/print.png', ImageDensityTest::png(2, 2, [11811, 1]));
        Storage::disk('assets')->put('img/screen.png', ImageDensityTest::png(4, 3, [2835, 1]));
        Storage::disk('assets')->put('img/plain.png', ImageDensityTest::png(1, 1));
        $this->build();

        $paths = fn (array $query) => collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets').'?'.http_build_query($query))
                ->json('data')
        )->pluck('path')->all();

        // Assets without a value go last whichever way the column is sorted.
        $this->assertSame(['img/screen.png', 'img/print.png', 'img/plain.png', 'docs/manual.pdf'], $paths(['sort' => 'resolution', 'order' => 'desc']));
        $this->assertSame(['img/plain.png', 'img/print.png', 'img/screen.png', 'docs/manual.pdf'], $paths(['sort' => 'resolution', 'order' => 'asc']));
        $this->assertSame(['img/print.png', 'img/screen.png', 'docs/manual.pdf', 'img/plain.png'], $paths(['sort' => 'dpi', 'order' => 'desc']));
        $this->assertSame(['img/screen.png', 'img/print.png', 'docs/manual.pdf', 'img/plain.png'], $paths(['sort' => 'dpi', 'order' => 'asc']));
    }

    #[Test]
    public function it_shows_the_resolution_and_dpi_of_images_only()
    {
        $this->makeContainer('assets', ['docs/manual.pdf']);
        Storage::disk('assets')->put('img/print.png', ImageDensityTest::png(4, 3, [11811, 1]));
        Storage::disk('assets')->put('img/web.png', ImageDensityTest::png(2, 2));
        $this->build();

        $rows = collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets'))
                ->json('data')
        )->keyBy('path');

        $this->assertSame('4 × 3', $rows['img/print.png']['dimensions']);
        $this->assertSame(300, $rows['img/print.png']['dpi']);

        // An image that declares no density still has a resolution.
        $this->assertSame('2 × 2', $rows['img/web.png']['dimensions']);
        $this->assertNull($rows['img/web.png']['dpi']);

        $this->assertNull($rows['docs/manual.pdf']['dimensions']);
        $this->assertNull($rows['docs/manual.pdf']['dpi']);
    }

    #[Test]
    public function it_lists_assets_from_subfolders()
    {
        $this->makeContainer('assets', ['top.jpg', 'img/one/deep/nested.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/one/deep/nested.jpg']);
        $this->build();

        $rows = collect(
            $this->actingAs($this->superUser())
                ->getJson(cp_route('asset-usage.assets'))
                ->json('data')
        )->keyBy('path');

        $this->assertSame(['img/one/deep/nested.jpg', 'top.jpg'], $rows->keys()->all());
        $this->assertSame(1, $rows['img/one/deep/nested.jpg']['count']);
        $this->assertSame(0, $rows['top.jpg']['count']);
    }

    #[Test]
    public function it_reports_the_index_state()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $state = $this->actingAs($this->superUserWithoutIndex())
            ->getJson(cp_route('asset-usage.status'))
            ->assertOk()
            ->json('index');

        $this->assertFalse($state['exists']);
        $this->assertTrue($state['stale']);

        $this->build();

        $state = $this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.status'))
            ->json('index');

        $this->assertTrue($state['exists']);
        $this->assertFalse($state['stale']);
        $this->assertNotNull($state['built_at']);
    }

    #[Test]
    public function it_rebuilds_the_index()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/photo.jpg']);

        /*
         * On the sync connection the rebuild is finished by the time the
         * response lands, so it may not report itself as still running.
         */
        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.rebuild'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('index.building', false)
            ->assertJsonPath('index.stale', false)
            ->assertJsonPath('message', __('asset-usage::messages.index.refreshed'));

        $this->assertTrue((new IndexStore)->indexOrEmpty()->isUsed('assets::img/photo.jpg'));
    }

    #[Test]
    public function a_queued_rebuild_reports_itself_as_running()
    {
        Queue::fake();

        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.rebuild'))
            ->assertOk()
            ->assertJsonPath('index.building', true)
            ->assertJsonPath('message', __('asset-usage::messages.index.refresh_started'));

        Queue::assertPushed(BuildIndex::class);
    }

    #[Test]
    public function deleting_needs_its_own_permission()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        $this->actingAs($this->userWith(['access cp', ServiceProvider::PERMISSION_VIEW]))
            ->deleteJson(cp_route('asset-usage.destroy'), ['ids' => ['assets::img/unused.jpg']])
            ->assertForbidden();

        $this->assertNotNull(Asset::find('assets::img/unused.jpg'));
    }

    #[Test]
    public function it_deletes_an_unused_asset()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy'), ['ids' => ['assets::img/unused.jpg']])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertNull(Asset::find('assets::img/unused.jpg'));
    }

    #[Test]
    public function it_deletes_a_whole_selection()
    {
        $this->makeContainer('assets', ['img/one.jpg', 'img/two.jpg', 'img/used.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy'), [
                'ids' => ['assets::img/one.jpg', 'assets::img/two.jpg'],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertNull(Asset::find('assets::img/one.jpg'));
        $this->assertNull(Asset::find('assets::img/two.jpg'));
        $this->assertNotNull(Asset::find('assets::img/used.jpg'));
    }

    #[Test]
    public function it_deletes_every_unused_asset_the_filters_cover()
    {
        config(['statamic.asset-usage.ignore' => ['*.pdf']]);

        $this->makeContainer('assets', ['img/one.jpg', 'img/two.jpg', 'img/used.jpg', 'docs/keep.pdf']);
        $this->makeContainer('documents', ['docs/other.jpg'], '/documents');
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $response = $this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.assets'))
            ->assertOk();

        // Ignored and used assets are left out of the count, across both containers.
        $this->assertSame(3, $response->json('meta.unused_total'));

        $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy-unused').'?container=assets')
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertNull(Asset::find('assets::img/one.jpg'));
        $this->assertNull(Asset::find('assets::img/two.jpg'));

        // Outside the filter, protected by `ignore`, or in use.
        $this->assertNotNull(Asset::find('documents::docs/other.jpg'));
        $this->assertNotNull(Asset::find('assets::docs/keep.pdf'));
        $this->assertNotNull(Asset::find('assets::img/used.jpg'));
    }

    #[Test]
    public function deleting_all_unused_ignores_the_usage_filter()
    {
        $this->makeContainer('assets', ['img/unused.jpg', 'img/used.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        // Looking at used assets shouldn't turn the button into a no-op.
        $response = $this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.assets').'?usage=used')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.unused_total'));

        $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy-unused').'?usage=used')
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertNull(Asset::find('assets::img/unused.jpg'));
        $this->assertNotNull(Asset::find('assets::img/used.jpg'));
    }

    /**
     * Without an index nothing is known to be unused, so the safety rail has to
     * say so per asset rather than let the endpoint check be the only thing
     * standing between a fresh install and a bulk delete.
     */
    #[Test]
    public function nothing_is_deletable_before_the_first_build()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $unused = Unused::make(new IndexStore);

        $this->assertNotNull($unused->blocker(Asset::find('assets::img/photo.jpg')));
        $this->assertSame([], $unused->ids());
    }

    #[Test]
    public function the_listing_offers_no_delete_before_the_first_build()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->actingAs($this->superUserWithoutIndex())
            ->getJson(cp_route('asset-usage.assets'))
            ->assertOk()
            ->assertJsonPath('data.0.blocker', __('asset-usage::messages.errors.no_usage_data'))
            ->assertJsonPath('meta.unused_total', 0);
    }

    /**
     * An index built for other settings is unusable too, but for a different
     * reason, and the row should say which one it is.
     */
    #[Test]
    public function a_stale_index_blocks_deleting_and_says_why()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);
        $this->build();

        config(['statamic.asset-usage.scan_urls' => false]);

        $this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.assets'))
            ->assertOk()
            ->assertJsonPath('data.0.blocker', __('asset-usage::messages.errors.stale_index'))
            ->assertJsonPath('meta.unused_total', 0);
    }

    #[Test]
    public function deleting_all_unused_needs_the_permission_and_a_current_index()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);

        $this->actingAs($this->superUserWithoutIndex())
            ->deleteJson(cp_route('asset-usage.destroy-unused'))
            ->assertStatus(409);

        $this->build();

        $this->actingAs($this->userWith(['access cp', ServiceProvider::PERMISSION_VIEW]))
            ->deleteJson(cp_route('asset-usage.destroy-unused'))
            ->assertForbidden();

        $this->assertNotNull(Asset::find('assets::img/unused.jpg'));
    }

    #[Test]
    public function it_refuses_to_delete_an_asset_that_is_used()
    {
        $this->makeContainer('assets', ['img/used.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $response = $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy'), ['ids' => ['assets::img/used.jpg']])
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        // Fetched as a whole: asset ids contain dots, which json() would read as nesting.
        $errors = $response->json('errors');

        $this->assertStringContainsString('used in 1', $errors['assets::img/used.jpg']);
        $this->assertNotNull(Asset::find('assets::img/used.jpg'));
    }

    #[Test]
    public function it_refuses_to_delete_anything_while_the_index_is_out_of_date()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);

        $this->actingAs($this->superUserWithoutIndex())
            ->deleteJson(cp_route('asset-usage.destroy'), ['ids' => ['assets::img/unused.jpg']])
            ->assertStatus(409);

        $this->assertNotNull(Asset::find('assets::img/unused.jpg'));
    }

    #[Test]
    public function it_refuses_to_delete_a_protected_or_recent_asset()
    {
        config([
            'statamic.asset-usage.ignore' => ['*.pdf'],
            'statamic.asset-usage.minimum_age_in_days' => 7,
        ]);

        $this->makeContainer('assets', ['img/unused.jpg', 'docs/orphan.pdf']);
        $this->build();

        $response = $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy'), [
                'ids' => ['assets::img/unused.jpg', 'assets::docs/orphan.pdf'],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        $errors = $response->json('errors');

        $this->assertStringContainsString('less than 7 days', $errors['assets::img/unused.jpg']);
        $this->assertStringContainsString('ignore', $errors['assets::docs/orphan.pdf']);
    }

    #[Test]
    public function it_will_not_touch_an_asset_outside_the_enabled_containers()
    {
        config(['statamic.asset-usage.containers' => ['assets']]);

        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->makeContainer('documents', ['docs/orphan.pdf'], '/documents');
        $this->build();

        $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy'), ['ids' => ['documents::docs/orphan.pdf']])
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        $this->assertNotNull(Asset::find('documents::docs/orphan.pdf'));
    }

    #[Test]
    public function it_only_covers_containers_whose_assets_the_user_may_view()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->makeContainer('documents', ['docs/orphan.pdf'], '/documents');
        $this->build();

        $user = $this->userWith([
            'access cp',
            ServiceProvider::PERMISSION_VIEW,
            ServiceProvider::PERMISSION_DELETE,
            'view assets assets',
            'delete assets assets',
        ]);

        $this->actingAs($user)
            ->get(cp_route('asset-usage.index'))
            ->assertInertia(fn ($page) => $page
                ->has('containers', 1)
                ->where('containers.0.handle', 'assets'));

        $response = $this->actingAs($user)
            ->getJson(cp_route('asset-usage.assets'))
            ->assertOk();

        $this->assertSame(['assets::img/unused.jpg'], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(1, $response->json('meta.unused_total'));

        $this->actingAs($user)
            ->getJson(cp_route('asset-usage.assets').'?container=documents')
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($user)
            ->deleteJson(cp_route('asset-usage.destroy-unused'))
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('errors', []);

        $this->assertNotNull(Asset::find('documents::docs/orphan.pdf'));
    }

    #[Test]
    public function it_needs_at_least_one_id_to_delete()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy'), ['ids' => []])
            ->assertStatus(422);
    }
}
