<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The "Compress all" button on the Compression page: the page lists what it
 * covers, then sends the images in small batches, so no single request runs
 * into the time limit.
 */
class CompressAllTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.asset-usage.compression.max_dimension' => 200]);

        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/heavy.jpg', Images::jpeg(600, 600, dpi: 300, quality: 98));
        Storage::disk('assets')->put('photos/profile.jpg', Images::jpegWithProfile(500, 400));
        // Already squeezed harder than the configured quality, so it can't get smaller.
        Storage::disk('assets')->put('img/small.jpg', Images::jpeg(100, 100, quality: 30));

        AnalyzeAllCompression::dispatch();
    }

    private function summary(array $query = []): ?array
    {
        return $this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.assets').'?'.http_build_query(['compression' => 'all'] + $query))
            ->json('meta.compress_all');
    }

    private function batch(array $ids, $user = null)
    {
        return $this->actingAs($user ?? $this->superUser())
            ->postJson(cp_route('asset-usage.compress.batch'), ['ids' => $ids]);
    }

    private function userWith(array $permissions)
    {
        Role::make('compressor')->addPermission($permissions)->save();

        return tap(User::make()->email('robin@example.com')->assignRole('compressor'))->save();
    }

    #[Test]
    public function the_compression_page_says_what_compress_all_would_cover()
    {
        $summary = $this->summary();

        $this->assertSame(2, $summary['count']);
        $this->assertGreaterThan(0, $summary['savable_bytes']);
        $this->assertSame(1, $summary['icc_lost']);

        // Like the list, it follows the search.
        $this->assertSame(1, $this->summary(['search' => 'photos'])['count']);
    }

    #[Test]
    public function the_overview_and_users_who_may_not_compress_get_no_summary()
    {
        $this->assertNull($this->actingAs($this->superUser())->getJson(cp_route('asset-usage.assets'))->json('meta.compress_all'));

        $user = $this->userWith(['access cp', 'view asset usage', 'view assets assets']);

        $this->assertNull($this->actingAs($user)->getJson(cp_route('asset-usage.assets').'?compression=all')->json('meta.compress_all'));
    }

    #[Test]
    public function it_lists_the_images_that_can_get_smaller()
    {
        $ids = $this->actingAs($this->superUser())->getJson(cp_route('asset-usage.compress.all'))->assertOk()->json('ids');

        $this->assertEqualsCanonicalizing(['assets::img/heavy.jpg', 'assets::photos/profile.jpg'], $ids);

        $this->assertSame(['assets::img/heavy.jpg'], $this->getJson(cp_route('asset-usage.compress.all').'?search=img')->json('ids'));
    }

    #[Test]
    public function a_batch_compresses_every_image_in_it_and_keeps_the_originals()
    {
        $before = Storage::disk('assets')->size('img/heavy.jpg');

        $response = $this->batch(['assets::img/heavy.jpg', 'assets::photos/profile.jpg'])->assertOk();

        $this->assertSame(2, $response->json('compressed'));
        $this->assertSame([], $response->json('errors'));
        $this->assertGreaterThan(0, $response->json('saved_bytes'));
        $this->assertLessThan($before, Storage::disk('assets')->size('img/heavy.jpg'));
        $this->assertTrue((new Backups)->has(Asset::find('assets::img/heavy.jpg')));
        $this->assertCount(2, (new AssetLog)->entries(AssetLog::COMPRESSED));

        $this->assertSame(0, $this->summary()['count']);
    }

    #[Test]
    public function a_batch_reports_what_it_could_not_compress_and_carries_on()
    {
        $small = Storage::disk('assets')->get('img/small.jpg');

        $response = $this->batch(['assets::img/missing.jpg', 'assets::img/small.jpg', 'assets::img/heavy.jpg'])->assertOk();

        $this->assertSame(1, $response->json('compressed'));
        $this->assertSame(['img/missing.jpg', 'img/small.jpg'], array_column($response->json('errors'), 'path'));
        $this->assertSame($small, Storage::disk('assets')->get('img/small.jpg'));
    }

    /**
     * A preview made before the file was replaced outside Statamic still
     * matches the meta, which describes the old file too, so only the size on
     * the disk gives the replacement away.
     */
    #[Test]
    public function a_batch_skips_a_file_replaced_outside_statamic()
    {
        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.preview'), ['asset' => 'assets::img/heavy.jpg'])->assertOk();

        Storage::disk('assets')->put('img/heavy.jpg', $replacement = Images::jpeg(400, 400));

        $response = $this->batch(['assets::img/heavy.jpg'])->assertOk();

        $this->assertSame(0, $response->json('compressed'));
        $this->assertSame(__('asset-usage::messages.compress.all_file_changed'), $response->json('errors.0.message'));
        $this->assertSame($replacement, Storage::disk('assets')->get('img/heavy.jpg'));
    }

    /**
     * Without a kept original no file is replaced, and the run stops there:
     * every next image would fail the same way.
     */
    #[Test]
    public function a_failed_backup_ends_the_run()
    {
        // A file where the originals of this container go, so the copy can't be made.
        File::ensureDirectoryExists((new Backups)->directory());
        File::put((new Backups)->directory().'/assets', '');

        $heavy = Storage::disk('assets')->get('img/heavy.jpg');

        $response = $this->batch(['assets::img/heavy.jpg', 'assets::photos/profile.jpg'])->assertOk();

        $this->assertTrue($response->json('halt'));
        $this->assertSame(0, $response->json('compressed'));
        $this->assertSame([['path' => 'img/heavy.jpg', 'message' => __('asset-usage::messages.compress.backup_failed')]], $response->json('errors'));
        $this->assertSame($heavy, Storage::disk('assets')->get('img/heavy.jpg'));
        $this->assertSame([], (new AssetLog)->entries(AssetLog::COMPRESSED));
    }

    #[Test]
    public function it_needs_the_compress_permission()
    {
        $user = $this->userWith(['access cp', 'view asset usage', 'view assets assets', 'edit assets assets', 'upload assets assets']);

        $this->actingAs($user)->getJson(cp_route('asset-usage.compress.all'))->assertForbidden();
        $this->batch(['assets::img/heavy.jpg'], $user)->assertForbidden();
    }

    /**
     * Replacing the file is Statamic's reupload, so its container permissions
     * apply on top of the addon's own, image by image.
     */
    #[Test]
    public function it_leaves_images_alone_the_user_may_not_replace()
    {
        $original = Storage::disk('assets')->get('img/heavy.jpg');
        $user = $this->userWith(['access cp', 'view asset usage', 'compress assets', 'view assets assets']);

        $response = $this->batch(['assets::img/heavy.jpg'], $user)->assertOk();

        $this->assertSame(0, $response->json('compressed'));
        $this->assertSame(['img/heavy.jpg'], array_column($response->json('errors'), 'path'));
        $this->assertSame($original, Storage::disk('assets')->get('img/heavy.jpg'));
    }

    #[Test]
    public function a_batch_is_a_short_list_of_ids()
    {
        $this->actingAs($this->superUser());

        $this->postJson(cp_route('asset-usage.compress.batch'), ['ids' => 'assets::img/heavy.jpg'])->assertStatus(422);
        $this->postJson(cp_route('asset-usage.compress.batch'), ['ids' => [['assets::img/heavy.jpg']]])->assertStatus(422);
        $this->postJson(cp_route('asset-usage.compress.batch'), ['ids' => array_fill(0, 11, 'assets::img/heavy.jpg')])->assertStatus(422);
    }
}
