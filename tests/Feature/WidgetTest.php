<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\Source;
use KeyAgency\AssetUsage\ServiceProvider;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\Support\MissingDriver;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use KeyAgency\AssetUsage\Widgets\AssetUsage as UsageWidget;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Blink;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The dashboard widget. It renders without numbers and fetches them from the
 * summary endpoint, so a large library doesn't slow the dashboard down.
 */
class WidgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.cp.widgets' => [['type' => 'asset_usage', 'width' => 50]]]);
    }

    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    private function userWith(array $permissions)
    {
        Role::make('usage')->addPermission($permissions)->save();

        return tap(User::make()->email('robin@example.com')->assignRole('usage'))->save();
    }

    private function summary($user = null)
    {
        return $this->actingAs($user ?? $this->superUser())->getJson(cp_route('asset-usage.summary'));
    }

    #[Test]
    public function it_is_registered_as_a_dashboard_widget()
    {
        $this->assertSame(UsageWidget::class, app('statamic.widgets')->get('asset_usage'));
    }

    #[Test]
    public function it_shows_on_the_dashboard()
    {
        $this->makeContainer('assets', ['img/photo.jpg']);

        $this->actingAs($this->superUser())
            ->get(cp_route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('widgets', 1)
                ->where('widgets.0.width', 50)
                ->where('widgets.0.component.name', 'asset-usage-widget')
                ->where('widgets.0.component.props.title', 'Asset Usage')
                ->where('widgets.0.component.props.summaryUrl', cp_route('asset-usage.summary'))
                ->where('widgets.0.component.props.overviewUrl', cp_route('asset-usage.index'))
                ->where('widgets.0.component.props.unusedUrl', cp_route('asset-usage.index', ['usage' => 'unused']))
                ->where('widgets.0.component.props.compressibleUrl', cp_route('asset-usage.compression', ['compression' => 'compressible']))
                ->where('widgets.0.component.props.logUrl', cp_route('asset-usage.log')));
    }

    #[Test]
    public function its_title_can_be_set_in_the_widget_config()
    {
        config(['statamic.cp.widgets' => [['type' => 'asset_usage', 'title' => 'Media']]]);

        $this->actingAs($this->superUser())
            ->get(cp_route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('widgets.0.component.props.title', 'Media'));
    }

    #[Test]
    public function it_is_left_off_the_dashboard_without_the_view_permission()
    {
        $this->actingAs($this->userWith(['access cp']))
            ->get(cp_route('dashboard'))
            ->assertInertia(fn ($page) => $page->has('widgets', 0));
    }

    #[Test]
    public function it_only_links_to_the_pages_the_user_may_open()
    {
        $this->actingAs($this->userWith(['access cp', ServiceProvider::PERMISSION_VIEW]))
            ->get(cp_route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('widgets', 1)
                ->where('widgets.0.component.props.compressibleUrl', null)
                ->where('widgets.0.component.props.logUrl', null));
    }

    #[Test]
    public function it_does_not_link_to_the_compression_page_when_compression_is_off()
    {
        config(['statamic.asset-usage.compression.enabled' => false]);

        $this->actingAs($this->superUser())
            ->get(cp_route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('widgets.0.component.props.compressibleUrl', null));
    }

    #[Test]
    public function the_summary_needs_the_view_permission()
    {
        $this->summary($this->userWith(['access cp']))->assertForbidden();
    }

    #[Test]
    public function the_summary_has_no_unused_count_before_the_index_is_built()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);

        $user = tap($this->superUser(), fn () => (new IndexStore)->delete());

        $this->summary($user)
            ->assertOk()
            ->assertJsonPath('index.exists', false)
            ->assertJsonPath('unused', null);
    }

    #[Test]
    public function the_summary_has_no_unused_count_when_the_index_is_stale()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $this->build();

        config(['statamic.asset-usage.scan_urls' => ! config('statamic.asset-usage.scan_urls')]);

        $this->summary()
            ->assertOk()
            ->assertJsonPath('index.stale', true)
            ->assertJsonPath('unused', null);
    }

    #[Test]
    public function the_summary_counts_the_unused_assets_the_user_may_view()
    {
        config(['statamic.asset-usage.ignore' => ['*.svg']]);

        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg', 'img/other.jpg', 'img/logo.svg']);
        $this->makeContainer('documents', ['docs/orphan.pdf'], '/documents');
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        $this->summary()->assertOk()->assertJsonPath('unused', 3)->assertJsonPath('assets', 5);

        $user = $this->userWith(['access cp', ServiceProvider::PERMISSION_VIEW, 'view assets assets']);

        $this->summary($user)->assertOk()->assertJsonPath('unused', 2)->assertJsonPath('assets', 4);
    }

    #[Test]
    public function the_summary_counts_the_assets_without_usage_data_as_well()
    {
        $this->makeContainer('assets', ['img/one.jpg', 'img/two.jpg']);

        $user = tap($this->superUser(), fn () => (new IndexStore)->delete());

        $this->summary($user)->assertOk()->assertJsonPath('unused', null)->assertJsonPath('assets', 2);
    }

    #[Test]
    public function the_summary_counts_everything_whatever_the_query_string_says()
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg']);
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();

        // The overview's filters; an unused asset belongs to no site, so `site` alone would zero the count.
        $this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.summary', ['site' => 'en', 'search' => 'nothing-matches', 'container' => 'other']))
            ->assertOk()
            ->assertJsonPath('unused', 1)
            ->assertJsonPath('assets', 2);
    }

    #[Test]
    public function the_summary_has_no_compression_without_the_image_driver()
    {
        config(['statamic.assets.image_manipulation.driver' => MissingDriver::class]);
        Blink::forget('asset-usage-compression-requirements');

        $this->summary()->assertOk()->assertJsonPath('compression', null);
    }

    #[Test]
    public function the_summary_shows_compression_only_to_users_who_may_compress()
    {
        config(['statamic.asset-usage.compression.max_dimension' => 200]);

        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/heavy.jpg', Images::jpeg(600, 600, dpi: 300, quality: 98));
        AnalyzeAllCompression::dispatch();

        $compression = $this->summary()->assertOk()->json('compression');

        $this->assertSame(1, $compression['compressible']);
        $this->assertGreaterThan(0, $compression['savable_bytes']);

        $user = $this->userWith(['access cp', ServiceProvider::PERMISSION_VIEW, 'view assets assets']);

        $this->summary($user)->assertOk()->assertJsonPath('compression', null);

        config(['statamic.asset-usage.compression.enabled' => false]);

        $this->summary()->assertOk()->assertJsonPath('compression', null);
    }

    #[Test]
    public function the_summary_counts_the_log_for_the_containers_the_user_may_view()
    {
        $this->makeContainer('assets');
        $this->makeContainer('documents', [], '/documents');

        $log = new AssetLog;
        $log->recordDeletion('assets::img/old.jpg', 'assets', 'img/old.jpg', ['bytes' => 1000], Source::TOOLS);
        $log->recordDeletion('documents::docs/old.pdf', 'documents', 'docs/old.pdf', ['bytes' => 5000], Source::TOOLS);

        $this->summary()
            ->assertOk()
            ->assertJsonPath('log.deletions.count', 2)
            ->assertJsonPath('log.deletions.bytes', 6000)
            ->assertJsonPath('log.compression.count', 0);

        $user = $this->userWith(['access cp', ServiceProvider::PERMISSION_VIEW, 'view assets assets']);

        $this->summary($user)
            ->assertOk()
            ->assertJsonPath('log.deletions.count', 1)
            ->assertJsonPath('log.deletions.bytes', 1000);
    }
}
