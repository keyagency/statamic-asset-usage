<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\DeletionSource;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class AssetLogTest extends TestCase
{
    private const ID = 'assets::img/heavy.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.asset-usage.compression.max_dimension' => 200]);

        $this->makeContainer('assets', ['docs/manual.pdf']);
        Storage::disk('assets')->put('img/heavy.jpg', Images::jpeg(600, 600, dpi: 300, quality: 98));
        Storage::disk('assets')->put('img/second.jpg', Images::jpeg(500, 500, quality: 98));
    }

    private function compress(string $id = self::ID): void
    {
        $this->actingAs($this->superUser());

        $version = $this->get(cp_route('asset-usage.compress.show', ['asset' => $id]))->viewData('page')['props']['asset']['version'];

        $this->postJson(cp_route('asset-usage.compress.store'), ['asset' => $id, 'version' => $version])->assertOk();
    }

    #[Test]
    public function every_compression_is_logged_with_who_did_it()
    {
        $this->compress();

        $entries = (new AssetLog)->entries(AssetLog::COMPRESSED);

        $this->assertCount(1, $entries);
        $this->assertSame(self::ID, $entries[0]['asset_id']);
        $this->assertSame([600, 200], [$entries[0]['before_width'], $entries[0]['after_width']]);
        $this->assertSame(strlen(Storage::disk('assets')->get('img/heavy.jpg')), $entries[0]['after_bytes']);
        $this->assertSame($this->superUser()->id(), $entries[0]['by']['id']);
        $this->assertNull($entries[0]['restored_at']);

        $totals = (new AssetLog)->compressionTotals();

        $this->assertSame(1, $totals['count']);
        $this->assertSame(1, $totals['resized']);
        $this->assertGreaterThan($totals['after_bytes'], $totals['before_bytes']);
    }

    #[Test]
    public function a_restore_stays_in_the_log_but_no_longer_counts()
    {
        $this->compress();
        $this->postJson(cp_route('asset-usage.compress.restore'), ['asset' => self::ID])->assertOk();

        $entry = (new AssetLog)->entries()[0];

        $this->assertNotNull($entry['restored_at']);
        $this->assertSame($this->superUser()->id(), $entry['restored_by']['id']);
        $this->assertSame(['count' => 0, 'restored' => 1], array_intersect_key((new AssetLog)->compressionTotals(), ['count' => 0, 'restored' => 0]));
    }

    #[Test]
    public function a_deletion_from_the_tools_page_is_logged_with_its_size_and_who_did_it()
    {
        $size = Asset::find('assets::docs/manual.pdf')->size();
        (new IndexBuilder(new IndexStore))->build();

        $this->actingAs($this->superUser())
            ->deleteJson(cp_route('asset-usage.destroy'), ['ids' => ['assets::docs/manual.pdf']])
            ->assertOk();

        $entry = (new AssetLog)->entries(AssetLog::DELETED)[0];

        $this->assertSame('assets::docs/manual.pdf', $entry['asset_id']);
        $this->assertSame($size, $entry['bytes']);
        $this->assertSame(DeletionSource::TOOLS, $entry['source']);
        $this->assertSame(0, $entry['usage_count']);
        $this->assertSame($this->superUser()->id(), $entry['by']['id']);
        $this->assertSame(['count' => 1, 'bytes' => $size], (new AssetLog)->deletionTotals());
    }

    /**
     * Wherever it happens, including outside the addon, and with whether the
     * asset was still in use, which is what makes a mistake easy to trace.
     */
    #[Test]
    public function a_deletion_anywhere_else_is_logged_too()
    {
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/second.jpg']);
        (new IndexBuilder(new IndexStore))->build();

        Asset::find('assets::img/second.jpg')->delete();

        $entry = (new AssetLog)->entries(AssetLog::DELETED)[0];

        $this->assertSame(DeletionSource::CONSOLE, $entry['source']);
        $this->assertSame(1, $entry['usage_count']);
        $this->assertSame([500, 500], [$entry['width'], $entry['height']]);
        $this->assertNull($entry['by']);
    }

    #[Test]
    public function a_deletion_outside_the_enabled_containers_is_not_logged()
    {
        config(['statamic.asset-usage.containers' => ['other']]);

        Asset::find('assets::img/second.jpg')->delete();

        $this->assertSame([], (new AssetLog)->entries());
    }

    #[Test]
    public function the_overview_shows_the_totals()
    {
        $this->compress();
        Asset::find('assets::docs/manual.pdf')->delete();

        $meta = $this->getJson(cp_route('asset-usage.assets'))->json('meta');

        $this->assertSame(1, $meta['compression']['log']['count']);
        $this->assertSame(1, $meta['deletions']['count']);
    }

    #[Test]
    public function the_log_page_lists_everything_newest_first_and_filters_by_type()
    {
        $this->compress();
        $this->travel(1)->minutes();
        Asset::find('assets::docs/manual.pdf')->delete();
        $this->travel(1)->minutes();
        $this->compress('assets::img/second.jpg');

        $props = fn (array $query = []) => $this->get(cp_route('asset-usage.log', $query))->assertOk()->viewData('page')['props'];

        $all = $props();
        $this->assertSame(['img/second.jpg', 'docs/manual.pdf', 'img/heavy.jpg'], array_column($all['entries'], 'path'));
        $this->assertSame('all', $all['type']);
        $this->assertNotNull($all['entries'][0]['edit_url']);
        $this->assertSame(2, $all['compressionTotals']['count']);
        $this->assertSame(1, $all['deletionTotals']['count']);

        $this->assertSame(['docs/manual.pdf'], array_column($props(['type' => 'deleted'])['entries'], 'path'));
        $this->assertSame(['img/second.jpg', 'img/heavy.jpg'], array_column($props(['type' => 'compressed'])['entries'], 'path'));
    }

    #[Test]
    public function the_log_page_needs_the_view_permission()
    {
        Role::make('nobody')->addPermission(['access cp'])->save();
        $user = tap(User::make()->email('robin@example.com')->assignRole('nobody'))->save();

        $this->actingAs($user)->get(cp_route('asset-usage.log'))->assertForbidden();
    }

    #[Test]
    public function the_log_is_available_on_the_command_line()
    {
        $this->compress();
        Asset::find('assets::docs/manual.pdf')->delete();

        $this->artisan('statamic:asset-usage:log')
            ->expectsOutputToContain(self::ID)
            ->expectsOutputToContain('assets::docs/manual.pdf')
            ->assertSuccessful();

        $this->artisan('statamic:asset-usage:log', ['--type' => 'deleted', '--json' => true])
            ->expectsOutputToContain('"count": 1')
            ->assertSuccessful();

        $this->artisan('statamic:asset-usage:log', ['--type' => 'nonsense'])->assertFailed();
    }

    /**
     * Paths, sizes and totals from a container a user can't view stay hidden,
     * the same as everywhere else in the addon.
     */
    #[Test]
    public function the_log_only_shows_containers_the_user_can_view()
    {
        $this->makeContainer('private', ['secret/plan.pdf'], '/private');
        Asset::find('assets::docs/manual.pdf')->delete();
        Asset::find('private::secret/plan.pdf')->delete();

        Role::make('viewer')->addPermission(['access cp', 'view asset usage', 'view asset log', 'view assets assets'])->save();
        $viewer = tap(User::make()->email('robin@example.com')->assignRole('viewer'))->save();

        $props = $this->actingAs($viewer)->get(cp_route('asset-usage.log'))->assertOk()->viewData('page')['props'];

        $this->assertSame(['docs/manual.pdf'], array_column($props['entries'], 'path'));
        $this->assertSame(1, $props['deletionTotals']['count']);
    }

    /**
     * Who deleted or compressed what is not for everyone who may see the
     * usage, so the log has a permission of its own.
     */
    #[Test]
    public function the_log_needs_a_permission_of_its_own()
    {
        Role::make('viewer')->addPermission(['access cp', 'view asset usage', 'view assets assets'])->save();
        $viewer = tap(User::make()->email('robin@example.com')->assignRole('viewer'))->save();

        $this->actingAs($viewer)->get(cp_route('asset-usage.log'))->assertForbidden();
        // The Tools pages leave out the way to it, too.
        $this->assertNull($this->get(cp_route('asset-usage.index'))->viewData('page')['props']['logUrl']);

        Role::find('viewer')->addPermission('view asset log')->save();
        $this->actingAs(User::find($viewer->id()));

        $this->get(cp_route('asset-usage.log'))->assertOk();
        $this->assertSame(cp_route('asset-usage.log'), $this->get(cp_route('asset-usage.index'))->viewData('page')['props']['logUrl']);
    }

    /**
     * Statamic's name() falls back to the email address. That is not stored,
     * and only shown to users who may see other users anyway.
     */
    #[Test]
    public function the_log_stores_names_not_email_addresses()
    {
        $this->actingAs($this->superUser());
        Asset::find('assets::docs/manual.pdf')->delete();

        $this->assertNull((new AssetLog)->entries()[0]['by']['name']);

        // A super user may see other users, so the address is filled in for them.
        $props = $this->get(cp_route('asset-usage.log'))->viewData('page')['props'];
        $this->assertSame('super@example.com', $props['entries'][0]['by']['name']);

        Role::make('viewer')->addPermission(['access cp', 'view asset usage', 'view asset log', 'view assets assets'])->save();
        $viewer = tap(User::make()->email('robin@example.com')->assignRole('viewer'))->save();

        $props = $this->actingAs($viewer)->get(cp_route('asset-usage.log'))->viewData('page')['props'];
        $this->assertNull($props['entries'][0]['by']['name']);
    }

    /**
     * The kept original dates from before the first compression, so a restore
     * undoes every compression since, and none of them counts any more.
     */
    #[Test]
    public function a_restore_after_compressing_twice_undoes_both()
    {
        $this->compress();
        config(['statamic.asset-usage.compression.max_dimension' => 100]);
        $this->compress();

        $this->postJson(cp_route('asset-usage.compress.restore'), ['asset' => self::ID])->assertOk();

        $this->assertCount(2, (new AssetLog)->entries(AssetLog::COMPRESSED));
        $this->assertSame(0, (new AssetLog)->compressionTotals()['count']);
        $this->assertSame(2, (new AssetLog)->compressionTotals()['restored']);
    }

    /**
     * By the time the log is written the file is already gone, so a failure to
     * write it is reported rather than turned into a failed delete.
     */
    #[Test]
    public function a_log_that_cannot_be_written_never_breaks_a_deletion()
    {
        // A directory where the log file should be makes every write fail.
        File::ensureDirectoryExists((new AssetLog)->path());

        Asset::find('assets::docs/manual.pdf')->delete();

        $this->assertFalse(Storage::disk('assets')->exists('docs/manual.pdf'));
    }

    /**
     * One entry per line, so a line cut short by a crash costs that entry only.
     */
    #[Test]
    public function a_line_cut_short_never_loses_the_rest_of_the_log()
    {
        Asset::find('assets::docs/manual.pdf')->delete();
        File::append((new AssetLog)->path(), '{"type":"deleted","asset_id":"assets::img/');

        Asset::find('assets::img/second.jpg')->delete();

        $this->assertSame(['docs/manual.pdf', 'img/second.jpg'], array_column((new AssetLog)->entries(), 'path'));
    }
}
