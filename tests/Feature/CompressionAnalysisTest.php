<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\CompressionResult;
use KeyAgency\AssetUsage\Compression\CompressionService;
use KeyAgency\AssetUsage\Compression\PathReplacementFile;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Jobs\AnalyzeCompression;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\AssetReuploaded;
use Statamic\Events\AssetUploaded;
use Statamic\Facades\Asset;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class CompressionAnalysisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Small images are enough once the maximum is small too.
        config(['statamic.asset-usage.compression.max_dimension' => 200]);

        $this->makeContainer('assets', ['docs/manual.pdf']);
        Storage::disk('assets')->put('img/heavy.jpg', Images::jpeg(600, 600, dpi: 300, quality: 98));
        Storage::disk('assets')->put('img/small.jpg', Images::jpeg(100, 100, quality: 30));
    }

    private function response(array $query = [])
    {
        return $this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.assets').'?'.http_build_query($query));
    }

    private function rows(array $query = [])
    {
        return collect($this->response($query)->json('data'))->keyBy('path');
    }

    #[Test]
    public function images_are_not_analysed_until_the_analysis_runs()
    {
        $rows = $this->rows();

        $this->assertFalse($rows['img/heavy.jpg']['compression']['analyzed']);
        $this->assertFalse($rows['img/heavy.jpg']['compression']['compressible']);
        $this->assertNull($rows['docs/manual.pdf']['compression']);

        $status = $this->response()->json('meta.compression');

        $this->assertTrue($status['never_analyzed']);
        $this->assertNull($status['analyzed_at']);
        $this->assertSame(2, $status['images']);
        $this->assertSame(2, $status['unanalyzed']);
    }

    #[Test]
    public function the_analysis_finds_the_images_that_can_get_smaller()
    {
        AnalyzeAllCompression::dispatch();

        $rows = $this->rows();
        $heavy = $rows['img/heavy.jpg']['compression'];
        $small = $rows['img/small.jpg']['compression'];

        $this->assertTrue($heavy['analyzed']);
        $this->assertTrue($heavy['compressible']);
        $this->assertGreaterThan(20, $heavy['savings']);
        $this->assertGreaterThan(0, $heavy['savable_bytes']);
        $this->assertFalse($heavy['icc_lost']);

        // Already squeezed harder than the configured quality, so re-encoding only makes it bigger.
        $this->assertTrue($small['analyzed']);
        $this->assertFalse($small['compressible']);
        $this->assertNull($small['savable_bytes']);

        $status = $this->response()->json('meta.compression');

        $this->assertFalse($status['never_analyzed']);
        $this->assertFalse($status['analyzing']);
        $this->assertNotNull($status['analyzed_at']);
        $this->assertSame(1, $status['compressible']);
        $this->assertSame(0, $status['unanalyzed']);
        $this->assertGreaterThan(0, $status['savable_bytes']);
    }

    #[Test]
    public function the_threshold_decides_what_counts_as_compressible()
    {
        AnalyzeAllCompression::dispatch();

        config(['statamic.asset-usage.compression.threshold_percent' => 100]);

        $this->assertFalse($this->rows()['img/heavy.jpg']['compression']['compressible']);
    }

    /**
     * The old result no longer applies the moment the file is replaced, and
     * the replace queues a new analysis.
     */
    #[Test]
    public function a_replaced_file_reads_as_not_analysed_until_it_is_analysed_again()
    {
        AnalyzeAllCompression::dispatch();

        config(['queue.default' => 'redis']);
        Queue::fake();

        $replacement = tempnam(sys_get_temp_dir(), 'au').'.jpg';
        file_put_contents($replacement, Images::jpeg(500, 400));
        Asset::find('assets::img/heavy.jpg')->reupload(new PathReplacementFile($replacement));
        unlink($replacement);

        $this->assertFalse($this->rows()['img/heavy.jpg']['compression']['analyzed']);
        Queue::assertPushed(AnalyzeCompression::class, fn ($job) => $job->ids === ['assets::img/heavy.jpg']);
    }

    #[Test]
    public function changed_settings_make_the_results_out_of_date()
    {
        AnalyzeAllCompression::dispatch();

        config(['statamic.asset-usage.compression.max_dimension' => 150]);

        $this->assertFalse($this->rows()['img/heavy.jpg']['compression']['analyzed']);
        $this->assertTrue($this->response()->json('meta.compression.settings_changed'));
    }

    #[Test]
    public function it_sorts_by_saving_with_the_rest_last()
    {
        AnalyzeAllCompression::dispatch();

        $paths = fn (string $order) => $this->rows(['sort' => 'savings', 'order' => $order])->keys()->all();

        $this->assertSame(['img/heavy.jpg', 'img/small.jpg', 'docs/manual.pdf'], $paths('desc'));
        // The image that can get smaller leads in this direction too.
        $this->assertSame(['img/heavy.jpg', 'img/small.jpg', 'docs/manual.pdf'], $paths('asc'));
    }

    /**
     * A compressed image shows what compressing saved, which can be more than
     * any image still has to gain. It sorts with the rest all the same.
     */
    #[Test]
    public function it_sorts_the_images_that_can_get_smaller_first()
    {
        Storage::disk('assets')->put('img/medium.jpg', Images::jpeg(260, 260, quality: 92));
        AnalyzeAllCompression::dispatch();

        $heavy = Asset::find('assets::img/heavy.jpg');
        CompressionService::make()->compress($heavy, Analyzer::version($heavy));

        $rows = $this->rows();
        $this->assertTrue($rows['img/medium.jpg']['compression']['compressible']);
        $this->assertSame('compressed', $rows['img/heavy.jpg']['compression']['status']);
        $this->assertGreaterThan($rows['img/medium.jpg']['compression']['savings'], $rows['img/heavy.jpg']['compression']['savings']);

        $paths = fn (string $order) => $this->rows(['sort' => 'savings', 'order' => $order])->keys()->all();

        $this->assertSame(['img/medium.jpg', 'img/heavy.jpg', 'img/small.jpg', 'docs/manual.pdf'], $paths('desc'));
        $this->assertSame(['img/medium.jpg', 'img/small.jpg', 'img/heavy.jpg', 'docs/manual.pdf'], $paths('asc'));
    }

    #[Test]
    public function uploaded_and_replaced_images_are_analysed_on_the_queue()
    {
        config(['queue.default' => 'redis']);
        Queue::fake();

        $image = Asset::find('assets::img/heavy.jpg');
        $pdf = Asset::find('assets::docs/manual.pdf');

        AssetUploaded::dispatch($image, 'heavy.jpg');
        AssetReuploaded::dispatch($image, 'heavy.jpg');
        AssetUploaded::dispatch($pdf, 'manual.pdf');

        Queue::assertPushed(AnalyzeCompression::class, 2);
        Queue::assertPushed(AnalyzeCompression::class, fn ($job) => $job->ids === ['assets::img/heavy.jpg']);
    }

    #[Test]
    public function the_analyse_button_says_it_is_running_before_a_worker_picks_it_up()
    {
        Queue::fake();

        $response = $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze'));

        $response->assertOk();
        $this->assertTrue($response->json('compression.analyzing'));
        Queue::assertPushed(AnalyzeAllCompression::class);
    }

    #[Test]
    public function on_the_sync_queue_the_analysis_is_done_when_the_button_returns()
    {
        $response = $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze'));

        $this->assertFalse($response->json('compression.analyzing'));
        $this->assertNotNull($response->json('compression.analyzed_at'));
        $this->assertSame(1, $response->json('compression.compressible'));
    }

    #[Test]
    public function compression_can_be_switched_off()
    {
        config(['statamic.asset-usage.compression.enabled' => false]);
        Queue::fake();

        $this->assertNull($this->rows()['img/heavy.jpg']['compression']);
        $this->assertNull($this->response()->json('meta.compression'));

        $this->actingAs($this->superUser())->postJson(cp_route('asset-usage.compress.analyze'))->assertNotFound();

        AssetUploaded::dispatch(Asset::find('assets::img/heavy.jpg'), 'heavy.jpg');
        Queue::assertNotPushed(AnalyzeCompression::class);
    }

    #[Test]
    public function the_compression_page_is_the_overview_narrowed_down()
    {
        $props = $this->actingAs($this->superUser())->get(cp_route('asset-usage.compression'))->assertOk()->viewData('page')['props'];

        $this->assertSame('compression', $props['view']);
        $this->assertSame('usage', $this->get(cp_route('asset-usage.index'))->viewData('page')['props']['view']);
    }

    #[Test]
    public function the_compression_view_lists_images_that_can_get_smaller_or_were_compressed()
    {
        AnalyzeAllCompression::dispatch();

        $this->assertSame(['img/heavy.jpg'], $this->rows(['compression' => 'compressible'])->keys()->all());
        $this->assertCount(3, $this->rows());

        // Compress it, and it moves from "can get smaller" to "compressed".
        $version = $this->get(cp_route('asset-usage.compress.show', ['asset' => 'assets::img/heavy.jpg']))->viewData('page')['props']['asset']['version'];
        $this->postJson(cp_route('asset-usage.compress.store'), ['asset' => 'assets::img/heavy.jpg', 'version' => $version])->assertOk();

        $this->assertSame([], $this->rows(['compression' => 'compressible'])->keys()->all());
        $this->assertSame(['img/heavy.jpg'], $this->rows(['compression' => 'compressed'])->keys()->all());
        $this->assertSame(['img/heavy.jpg'], $this->rows(['compression' => 'all'])->keys()->all());
    }

    #[Test]
    public function the_compression_page_is_gone_when_compression_is_off()
    {
        config(['statamic.asset-usage.compression.enabled' => false]);

        $this->actingAs($this->superUser())->get(cp_route('asset-usage.compression'))->assertNotFound();
    }

    /**
     * Without the compress permission there is nothing to do on the page, so
     * the page and the ways to it are left out. The Saving column stays.
     */
    #[Test]
    public function the_compression_page_is_for_users_who_may_compress()
    {
        Role::make('viewer')->addPermission(['access cp', 'view asset usage', 'view asset log', 'view assets assets'])->save();
        $viewer = tap(User::make()->email('robin@example.com')->assignRole('viewer'))->save();

        $this->actingAs($viewer)->get(cp_route('asset-usage.compression'))->assertForbidden();
        $this->assertNull($this->get(cp_route('asset-usage.index'))->viewData('page')['props']['compressionPageUrl']);
        $this->assertNull($this->get(cp_route('asset-usage.log'))->viewData('page')['props']['compressionPageUrl']);

        $rows = collect($this->getJson(cp_route('asset-usage.assets'))->json('data'))->keyBy('path');
        $this->assertNotNull($rows['img/heavy.jpg']['compression']);

        Role::find('viewer')->addPermission('compress assets')->save();
        $this->actingAs(User::find($viewer->id()));

        $this->get(cp_route('asset-usage.compression'))->assertOk();
        $this->assertSame(cp_route('asset-usage.compression'), $this->get(cp_route('asset-usage.index'))->viewData('page')['props']['compressionPageUrl']);
    }

    /**
     * Decided from the header, before the whole file is read: reading
     * something too big for the memory limit is a fatal error.
     */
    #[Test]
    public function an_image_too_large_for_memory_is_refused_before_it_is_read()
    {
        Storage::disk('assets')->put('img/huge.jpg', Images::jpegHeader(20000, 20000));
        $limit = ini_get('memory_limit');

        ini_set('memory_limit', (string) max(512 * 1024 * 1024, memory_get_usage(true) + 64 * 1024 * 1024));

        try {
            $result = Analyzer::make()->compress(Asset::find('assets::img/huge.jpg'));
        } finally {
            ini_set('memory_limit', $limit);
        }

        $this->assertSame(CompressionResult::TOO_LARGE, $result->status);
        $this->assertSame([20000, 20000], [$result->beforeWidth, $result->beforeHeight]);
    }

    #[Test]
    public function a_file_that_is_not_a_compressible_image_is_never_read()
    {
        $this->assertSame(CompressionResult::UNSUPPORTED, Analyzer::make()->compress(Asset::find('assets::docs/manual.pdf'))->status);
    }

    /**
     * json_encode() returns false on invalid UTF-8, which used to write an
     * empty file over every record.
     */
    #[Test]
    public function a_broken_character_never_wipes_the_stored_records()
    {
        AnalyzeAllCompression::dispatch();
        $store = new AnalysisStore;

        $store->store(['assets::img/odd.jpg' => ['status' => 'error', 'reason' => "Broken \xB1 name"]]);

        $this->assertNotNull($store->record('assets::img/heavy.jpg'));
        $this->assertStringContainsString('Broken', $store->record('assets::img/odd.jpg')['reason']);
    }

    /**
     * Without a real queue the analysis waits until the upload has been
     * answered, rather than holding up or failing the upload itself.
     */
    #[Test]
    public function on_the_sync_queue_an_upload_is_analysed_after_the_response()
    {
        AssetUploaded::dispatch(Asset::find('assets::img/heavy.jpg'), 'heavy.jpg');

        $this->assertNull((new AnalysisStore)->record('assets::img/heavy.jpg'));

        $this->app->terminate();

        $this->assertNotNull((new AnalysisStore)->record('assets::img/heavy.jpg'));
    }

    /**
     * A batch can finish twice, as a retry after a timeout can. It still
     * counts once, so the run doesn't end before its last batch.
     */
    #[Test]
    public function a_batch_that_finishes_twice_counts_once()
    {
        $store = new AnalysisStore;
        $store->markAnalyzing(['one' => [], 'two' => []], 'fingerprint');

        $store->store([], 'one');
        $store->store([], 'one');

        $this->assertTrue($store->isAnalyzing());

        $store->store([], 'two');

        $this->assertFalse($store->isAnalyzing());
        $this->assertNotNull($store->meta()['analyzed_at']);
    }

    #[Test]
    public function a_failed_batch_is_struck_off_all_the_same()
    {
        $store = new AnalysisStore;
        $store->markAnalyzing(['one' => ['assets::img/heavy.jpg']], 'fingerprint');

        (new AnalyzeCompression(['assets::img/heavy.jpg'], 'one'))->failed(new \RuntimeException('Worker died'));

        $this->assertFalse($store->isAnalyzing());
    }

    #[Test]
    public function the_command_analyses_every_image()
    {
        $this->artisan('statamic:asset-usage:analyze')
            ->expectsOutputToContain('Analysing 2 images.')
            ->assertSuccessful();

        $store = new AnalysisStore;

        $this->assertFalse($store->isAnalyzing());
        $this->assertNotNull($store->meta()['analyzed_at']);
        $this->assertNotNull($store->record('assets::img/heavy.jpg'));
        $this->assertNotNull($store->record('assets::img/small.jpg'));
    }

    /**
     * Only part of the images, so the analysis as a whole keeps its date and
     * the settings it was made with, and the other containers keep theirs.
     */
    #[Test]
    public function the_command_can_analyse_one_container()
    {
        $this->makeContainer('more');
        Storage::disk('more')->put('photo.jpg', Images::jpeg(600, 600, quality: 98));

        $this->artisan('statamic:asset-usage:analyze', ['--container' => 'more'])
            ->expectsOutputToContain('Analysing 1 image.')
            ->assertSuccessful();

        $store = new AnalysisStore;

        $this->assertNotNull($store->record('more::photo.jpg'));
        $this->assertNull($store->record('assets::img/heavy.jpg'));
        $this->assertNull($store->meta()['analyzed_at']);

        $this->artisan('statamic:asset-usage:analyze', ['--container' => 'nope'])->assertFailed();
        $this->artisan('statamic:asset-usage:analyze', ['--container' => 'more', '--queue' => true])->assertFailed();
    }

    /**
     * A full run covers every image there is, so it drops what it was keeping
     * for assets that are gone.
     */
    #[Test]
    public function a_full_run_drops_the_analysis_of_deleted_assets()
    {
        $this->artisan('statamic:asset-usage:analyze')->assertSuccessful();

        Asset::find('assets::img/small.jpg')->delete();
        $this->assertNotNull((new AnalysisStore)->record('assets::img/small.jpg'));

        $this->artisan('statamic:asset-usage:analyze')->assertSuccessful();

        $this->assertNull((new AnalysisStore)->record('assets::img/small.jpg'));
        $this->assertNotNull((new AnalysisStore)->record('assets::img/heavy.jpg'));
    }

    #[Test]
    public function the_analysis_follows_a_renamed_image()
    {
        $this->artisan('statamic:asset-usage:analyze')->assertSuccessful();

        Asset::find('assets::img/heavy.jpg')->rename('renamed');

        $this->assertNull((new AnalysisStore)->record('assets::img/heavy.jpg'));
        $this->assertTrue($this->rows()['img/renamed.jpg']['compression']['analyzed']);
    }

    /**
     * The counts cover the containers the user can view, the same ones the
     * overview lists.
     */
    #[Test]
    public function the_counts_only_cover_containers_the_user_can_view()
    {
        $this->makeContainer('private', [], '/private');
        Storage::disk('private')->put('secret.jpg', Images::jpeg(300, 300));

        Role::make('viewer')->addPermission(['access cp', 'view asset usage', 'view assets assets'])->save();
        $viewer = tap(User::make()->email('robin@example.com')->assignRole('viewer'))->save();

        $this->assertSame(3, $this->response()->json('meta.compression.images'));
        $this->assertSame(2, $this->actingAs($viewer)->getJson(cp_route('asset-usage.assets'))->json('meta.compression.images'));
    }

    /**
     * The command moves its progress bar on per image, so it shows from the
     * start instead of once a whole batch is done.
     */
    #[Test]
    public function the_analysis_reports_progress_per_image_skipped_ones_included()
    {
        $analyzer = Analyzer::make();
        $assets = [Asset::find('assets::img/heavy.jpg'), Asset::find('assets::img/small.jpg')];
        $analyzer->analyze([$assets[0]]);

        $seen = [];
        $analyzer->analyze($assets, progress: function ($asset) use (&$seen) {
            $seen[] = $asset->id();
        });

        $this->assertSame(['assets::img/heavy.jpg', 'assets::img/small.jpg'], $seen);
    }
}
