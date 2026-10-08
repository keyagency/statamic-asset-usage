<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Compression\PathReplacementFile;
use KeyAgency\AssetUsage\Export\Pdf;
use KeyAgency\AssetUsage\Export\Report;
use KeyAgency\AssetUsage\Export\Thumbnails;
use KeyAgency\AssetUsage\Http\Controllers\ExportController;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use KeyAgency\AssetUsage\Usage\IndexBuilder;
use KeyAgency\AssetUsage\Usage\IndexStore;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The overview and the Compression page as a PDF: the same rows in the same
 * order as the list, with thumbnails the page has made beforehand.
 */
class PdfExportTest extends TestCase
{
    private string $memoryLimit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memoryLimit = ini_get('memory_limit');
    }

    /** The export raises the limit the way Statamic does for Glide, which would otherwise last for the rest of the run. */
    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function build(): void
    {
        (new IndexBuilder(new IndexStore))->build();
    }

    private function userWith(array $permissions)
    {
        Role::make('exporter')->addPermission($permissions)->save();

        return tap(User::make()->email('robin@example.com')->assignRole('exporter'))->save();
    }

    /** The report the PDF would be rendered from, without rendering it. */
    private function report(array $query = [], $user = null): Report
    {
        $report = null;

        $this->mock(Pdf::class)
            ->shouldReceive('render')
            ->andReturnUsing(function (Report $given) use (&$report) {
                $report = $given;

                return '%PDF-1.7';
            });

        $this->actingAs($user ?? $this->superUser())
            ->get(cp_route('asset-usage.export.download').'?'.http_build_query($query))
            ->assertOk();

        return $report;
    }

    /** @return string[] */
    private function listedPaths(array $query): array
    {
        return collect($this->actingAs($this->superUser())
            ->getJson(cp_route('asset-usage.assets').'?'.http_build_query($query))
            ->json('data'))->pluck('path')->all();
    }

    private function overview(): void
    {
        $this->makeContainer('assets', ['img/used.jpg', 'img/unused.jpg', 'img/another.jpg']);
        $this->makeContainer('documents', ['docs/orphan.pdf'], '/documents');
        $this->makeEntry('home', ['title' => 'Home', 'hero' => 'img/used.jpg']);
        $this->build();
    }

    #[Test]
    public function it_needs_the_permissions_of_the_page_it_is_made_from()
    {
        $this->overview();

        $this->actingAs($this->userWith(['access cp']))
            ->getJson(cp_route('asset-usage.export.download'))
            ->assertForbidden();

        $viewer = $this->userWith(['access cp', 'view asset usage', 'view assets assets']);

        $this->actingAs($viewer)
            ->getJson(cp_route('asset-usage.export.download').'?view=compression')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->getJson(cp_route('asset-usage.export.thumbnails').'?view=compression')
            ->assertForbidden();
    }

    #[Test]
    public function it_downloads_a_pdf()
    {
        $this->overview();
        Carbon::setTestNow('2026-10-08 23:30:00');

        $response = $this->actingAs($this->superUser())
            ->get(cp_route('asset-usage.export.download').'?timezone=Europe/Amsterdam')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // A day later in Amsterdam than in UTC, and the file is named after the date and time there.
        $this->assertStringContainsString('filename=asset-usage-overview-2026-10-09-013000.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    #[Test]
    public function it_lists_the_same_assets_in_the_same_order_as_the_overview()
    {
        $this->overview();

        foreach ([[], ['usage' => 'unused'], ['usage' => 'used'], ['container' => 'documents'], ['search' => 'an'], ['sort' => 'path', 'order' => 'desc'], ['sort' => 'usage', 'order' => 'desc']] as $query) {
            $this->assertSame(
                $this->listedPaths($query),
                array_column($this->report($query)->rows, 'path'),
                'For '.json_encode($query)
            );
        }
    }

    #[Test]
    public function it_names_the_active_filters_and_the_sort()
    {
        $this->overview();
        Carbon::setTestNow('2026-10-08 12:00:00');

        $report = $this->report(['usage' => 'unused', 'container' => 'assets', 'search' => 'un', 'sort' => 'size', 'order' => 'desc', 'timezone' => 'Europe/Amsterdam']);

        $this->assertSame('Asset Usage', $report->title);
        $this->assertSame('Overview', $report->subtitle);
        $this->assertSame('October 8, 2026 2:00 PM', $report->createdAt);
        $this->assertSame([
            ['label' => 'Usage', 'value' => 'Unused'],
            ['label' => 'Container', 'value' => 'Assets'],
            ['label' => 'Search', 'value' => 'un'],
        ], $report->filters);
        $this->assertSame('Size, descending', $report->sort);
        $this->assertSame(cp_route('asset-usage.index').'?search=un&usage=unused&container=assets&sort=size&order=desc', $report->overviewUrl);

        $this->assertSame([], $this->report()->filters);
        $this->assertSame('Name, ascending', $this->report()->sort);
        $this->assertSame(cp_route('asset-usage.index'), $this->report()->overviewUrl);
    }

    #[Test]
    public function a_container_the_user_may_not_view_is_not_named()
    {
        $this->overview();

        $report = $this->report(['container' => 'documents'], $this->userWith(['access cp', 'view asset usage', 'view assets assets']));

        $this->assertSame([['label' => 'Container', 'value' => 'documents']], $report->filters);
        $this->assertSame([], $report->rows);
    }

    #[Test]
    public function it_lists_at_most_the_limit()
    {
        $this->makeContainer('assets');
        $jpeg = Images::jpeg(8, 8);

        for ($i = 0; $i <= ExportController::MAX_ROWS; $i++) {
            Storage::disk('assets')->put(sprintf('img/%04d.jpg', $i), $jpeg);
        }

        $this->build();

        $pending = $this->actingAs($this->superUser())->getJson(cp_route('asset-usage.export.thumbnails'))->json('ids');
        $report = $this->report();

        $this->assertCount(ExportController::MAX_ROWS, $pending);
        $this->assertCount(ExportController::MAX_ROWS, $report->rows);
        $this->assertSame(ExportController::MAX_ROWS + 1, $report->total);
        $this->assertTrue($report->truncated());
    }

    #[Test]
    public function each_row_links_to_the_asset_and_says_how_often_it_is_used()
    {
        $this->overview();

        $rows = collect($this->report()->rows)->keyBy('path');

        $this->assertSame(Asset::find('assets::img/used.jpg')->editUrl(), $rows['img/used.jpg']['edit_url']);
        $this->assertStringStartsWith('http', $rows['img/used.jpg']['edit_url']);
        $this->assertSame('1 place', $rows['img/used.jpg']['usage']);
        $this->assertFalse($rows['img/used.jpg']['unused']);
        $this->assertSame('Unused', $rows['img/unused.jpg']['usage']);
        $this->assertTrue($rows['img/unused.jpg']['unused']);
        $this->assertSame('Documents', $rows['docs/orphan.pdf']['container']);
        $this->assertSame('pdf', $rows['docs/orphan.pdf']['extension']);
    }

    #[Test]
    public function the_overview_is_refused_while_the_usage_data_cannot_be_believed()
    {
        $this->makeContainer('assets', ['img/unused.jpg']);
        $user = tap($this->superUser(), fn () => (new IndexStore)->delete());

        foreach (['asset-usage.export.download', 'asset-usage.export.thumbnails'] as $route) {
            $this->actingAs($user)
                ->getJson(cp_route($route))
                ->assertStatus(409)
                ->assertJson(['message' => __('asset-usage::messages.export.unusable_index')]);
        }
    }

    #[Test]
    public function the_compression_page_says_the_usage_is_not_checked_instead()
    {
        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/heavy.jpg', Images::jpeg(600, 600, quality: 98));
        config(['statamic.asset-usage.compression.max_dimension' => 200]);
        AnalyzeAllCompression::dispatch();
        $user = tap($this->superUser(), fn () => (new IndexStore)->delete());

        $report = $this->report(['view' => 'compression'], $user);

        $this->assertSame('Compression', $report->subtitle);
        $this->assertSame('Saving, descending', $report->sort);
        $this->assertSame(['Not checked yet'], array_column($report->rows, 'usage'));
    }

    #[Test]
    public function the_page_has_the_missing_thumbnails_made_first()
    {
        $this->makeContainer('assets', ['docs/manual.pdf']);
        Storage::disk('assets')->put('img/photo.jpg', Images::jpeg(800, 600));
        Storage::disk('assets')->put('img/logo.png', Images::png(300, 300));
        $this->build();

        $pending = fn () => $this->actingAs($this->superUser())->getJson(cp_route('asset-usage.export.thumbnails'))->json('ids');

        $this->assertSame(['assets::img/logo.png', 'assets::img/photo.jpg'], $pending());

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.export.make-thumbnails'), ['ids' => $pending()])
            ->assertOk()
            ->assertJson(['made' => 2]);

        $this->assertSame([], $pending());

        $rows = collect($this->report()->rows)->keyBy('path');

        $this->assertStringStartsWith('data:image/jpeg;base64,', $rows['img/photo.jpg']['thumbnail']);
        $this->assertSame([Thumbnails::SIZE, Thumbnails::SIZE], array_slice(getimagesizefromstring(base64_decode(substr($rows['img/photo.jpg']['thumbnail'], 23))), 0, 2));
        $this->assertNull($rows['docs/manual.pdf']['thumbnail']);
    }

    #[Test]
    public function an_image_that_cannot_be_read_is_skipped()
    {
        $this->makeContainer('assets', ['img/broken.jpg']);
        Storage::disk('assets')->put('img/photo.jpg', Images::jpeg(200, 200));
        $this->build();

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.export.make-thumbnails'), ['ids' => ['assets::img/broken.jpg', 'assets::img/photo.jpg']])
            ->assertOk()
            ->assertJson(['made' => 1]);

        $this->assertNull(collect($this->report()->rows)->keyBy('path')['img/broken.jpg']['thumbnail']);
    }

    /**
     * Statamic gives Glide no memory limit by default. Lifting it here as well
     * would let GD decode an image of any size; kept, it is too large and skipped.
     */
    #[Test]
    public function making_thumbnails_leaves_the_memory_limit_alone()
    {
        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/huge.jpg', Images::jpegHeader(30000, 30000));
        $this->build();

        config(['statamic.system.php_memory_limit' => '-1']);
        ini_set('memory_limit', '512M');

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.export.make-thumbnails'), ['ids' => ['assets::img/huge.jpg']])
            ->assertOk()
            ->assertJson(['made' => 0]);

        $this->assertSame('512M', ini_get('memory_limit'));
    }

    #[Test]
    public function thumbnails_are_only_made_for_assets_the_user_may_see()
    {
        $this->makeContainer('assets');
        $this->makeContainer('private', [], '/private');
        Storage::disk('assets')->put('img/photo.jpg', Images::jpeg(200, 200));
        Storage::disk('private')->put('img/secret.jpg', Images::jpeg(200, 200));
        config(['statamic.asset-usage.containers' => ['assets']]);
        $this->build();

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.export.make-thumbnails'), ['ids' => ['private::img/secret.jpg', 'missing::img/photo.jpg']])
            ->assertOk()
            ->assertJson(['made' => 0]);

        $this->actingAs($this->userWith(['access cp', 'view asset usage']))
            ->postJson(cp_route('asset-usage.export.make-thumbnails'), ['ids' => ['assets::img/photo.jpg']])
            ->assertOk()
            ->assertJson(['made' => 0]);

        $this->actingAs($this->superUser())
            ->postJson(cp_route('asset-usage.export.make-thumbnails'), ['ids' => array_fill(0, 6, 'assets::img/photo.jpg')])
            ->assertUnprocessable();
    }

    #[Test]
    public function a_thumbnail_goes_with_its_asset()
    {
        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/photo.jpg', Images::jpeg(200, 200));

        $asset = Asset::find('assets::img/photo.jpg');
        $thumbnails = new Thumbnails;
        $thumbnails->make($asset);
        $path = $thumbnails->path($asset);

        $this->assertFileExists($path);

        $asset->delete();

        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function a_replaced_file_gets_a_new_thumbnail()
    {
        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/photo.jpg', Images::jpeg(200, 200));

        $thumbnails = new Thumbnails;
        $thumbnails->make(Asset::find('assets::img/photo.jpg'));

        $replacement = tempnam(sys_get_temp_dir(), 'au').'.jpg';
        file_put_contents($replacement, Images::jpeg(300, 300));
        Asset::find('assets::img/photo.jpg')->reupload(new PathReplacementFile($replacement));
        unlink($replacement);

        $asset = Asset::find('assets::img/photo.jpg');

        $this->assertFalse($thumbnails->has($asset));
        $this->assertTrue($thumbnails->make($asset));
        $this->assertCount(1, File::files($thumbnails->directory()));
    }

    #[Test]
    public function the_pdf_holds_the_thumbnails_and_the_links()
    {
        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/photo.jpg', Images::jpeg(400, 300));
        $this->build();

        (new Thumbnails)->make(Asset::find('assets::img/photo.jpg'));

        $pdf = $this->actingAs($this->superUser())
            ->get(cp_route('asset-usage.export.download'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('/URI', $pdf);
    }

    #[Test]
    public function the_view_escapes_file_names_and_says_when_rows_were_left_out()
    {
        $html = view('asset-usage::export', ['report' => new Report(
            title: 'Asset Usage',
            subtitle: 'Overview',
            createdAt: 'October 8, 2026 2:00 PM',
            filters: [['label' => 'Search', 'value' => '<script>']],
            sort: 'Name, ascending',
            total: 2500,
            totalSize: '1.2 GB',
            rows: [[
                'path' => 'img/<b>bold</b>.jpg', 'extension' => 'jpg', 'container' => 'Assets', 'edit_url' => 'https://example.com/cp/assets/1',
                'thumbnail' => null, 'size' => '1 KB', 'dimensions' => null, 'dpi' => null, 'saving' => null, 'saving_marked' => false,
                'last_modified' => 'Oct 8, 2026', 'usage' => 'Unused', 'unused' => true,
            ]],
            showsContainer: false,
            showsSavings: false,
            emptyText: 'No assets match these filters.',
            overviewUrl: 'https://example.com/cp/asset-usage/overview?search=%3Cscript%3E',
            filename: 'asset-usage-overview-2026-10-08.pdf',
        )])->render();

        $this->assertStringContainsString('img/&lt;b&gt;bold&lt;/b&gt;.jpg', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="https://example.com/cp/assets/1"', $html);
        $this->assertStringContainsString('This PDF is limited to 1 assets', $html);
        $this->assertStringContainsString('It lists the first 1 of the 2500 assets that match.', $html);
    }
}
