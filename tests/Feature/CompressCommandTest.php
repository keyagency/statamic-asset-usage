<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\Source;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;

/**
 * `asset-usage:compress`: the CLI side of "Compress all", over the same images.
 */
class CompressCommandTest extends TestCase
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

    #[Test]
    public function it_compresses_every_image_that_can_get_smaller_and_keeps_the_originals()
    {
        $heavy = Storage::disk('assets')->size('img/heavy.jpg');
        $small = Storage::disk('assets')->get('img/small.jpg');

        $this->artisan('statamic:asset-usage:compress', ['--force' => true])
            ->expectsOutputToContain('2 images compressed')
            ->assertSuccessful();

        $this->assertLessThan($heavy, Storage::disk('assets')->size('img/heavy.jpg'));
        $this->assertSame($small, Storage::disk('assets')->get('img/small.jpg'));
        $this->assertTrue((new Backups)->has(Asset::find('assets::img/heavy.jpg')));
        $this->assertCount(2, $entries = (new AssetLog)->entries(AssetLog::COMPRESSED));
        $this->assertSame(Source::CLI, $entries[0]['source']);
        $this->assertNull($entries[0]['by']);

        // Compressed images aren't offered again.
        $this->artisan('statamic:asset-usage:compress', ['--force' => true])
            ->expectsOutputToContain('No images can get smaller')
            ->assertSuccessful();
    }

    #[Test]
    public function it_asks_before_replacing_anything()
    {
        $heavy = Storage::disk('assets')->get('img/heavy.jpg');

        $this->artisan('statamic:asset-usage:compress')
            ->expectsConfirmation('Replace these 2 images with their compressed versions?', 'no')
            ->expectsOutputToContain('Nothing was compressed')
            ->assertSuccessful();

        $this->assertSame($heavy, Storage::disk('assets')->get('img/heavy.jpg'));
        $this->assertSame([], (new AssetLog)->entries(AssetLog::COMPRESSED));
    }

    #[Test]
    public function a_dry_run_only_lists_them()
    {
        $heavy = Storage::disk('assets')->get('img/heavy.jpg');

        $this->artisan('statamic:asset-usage:compress', ['--dry-run' => true])
            ->expectsOutputToContain('2 images can get')
            ->assertSuccessful();

        $this->assertSame($heavy, Storage::disk('assets')->get('img/heavy.jpg'));
    }

    #[Test]
    public function it_mentions_the_images_that_were_never_analysed()
    {
        $this->makeContainer('more');
        Storage::disk('more')->put('new.jpg', Images::jpeg(600, 600, quality: 98));

        $this->artisan('statamic:asset-usage:compress', ['--dry-run' => true])
            ->expectsOutputToContain('1 image has not been analysed yet')
            ->assertSuccessful();
    }

    /**
     * None of the results count, and an image left out can't be told apart
     * from one with nothing to gain. When the CP disagrees, it sees other
     * settings than the command line, which is worth saying.
     */
    #[Test]
    public function it_mentions_the_images_analysed_with_other_settings()
    {
        config(['statamic.asset-usage.compression.jpg_quality' => 75]);

        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('statamic:asset-usage:compress', ['--dry-run' => true]));

        $output = preg_replace('/\s+/', ' ', Artisan::output());

        $this->assertStringContainsString('No images can get smaller', $output);
        $this->assertStringContainsString('3 images were analysed with other settings', $output);
        $this->assertStringContainsString('Set pngquant_binary to its full path', $output);
    }

    #[Test]
    public function it_can_analyse_those_first()
    {
        config(['statamic.asset-usage.compression.jpg_quality' => 75]);

        $this->artisan('statamic:asset-usage:compress', ['--analyze' => true, '--force' => true])
            ->expectsOutputToContain('2 images compressed')
            ->assertSuccessful();

        $this->assertCount(2, (new AssetLog)->entries(AssetLog::COMPRESSED));
    }

    #[Test]
    public function it_only_analyses_the_container_it_compresses()
    {
        config(['statamic.asset-usage.compression.jpg_quality' => 75]);
        $this->makeContainer('more');
        Storage::disk('more')->put('new.jpg', Images::jpeg(600, 600, quality: 98));

        $this->artisan('statamic:asset-usage:compress', ['--analyze' => true, '--container' => 'more', '--force' => true])
            ->expectsOutputToContain('1 image compressed')
            ->assertSuccessful();

        $analyzer = Analyzer::make();

        // Made with the old settings, so the other container wasn't analysed.
        $this->assertNotSame($analyzer->compressor()->fingerprint(), $analyzer->store()->record('assets::img/heavy.jpg')['settings']);
    }

    #[Test]
    public function it_outputs_json_for_scripts()
    {
        $this->withoutMockingConsoleOutput();

        $this->assertSame(0, $this->artisan('statamic:asset-usage:compress', ['--json' => true, '--dry-run' => true]));
        $this->assertSame(['assets::img/heavy.jpg', 'assets::photos/profile.jpg'], json_decode(Artisan::output(), true));

        $this->assertSame(0, $this->artisan('statamic:asset-usage:compress', ['--json' => true, '--force' => true]));

        $result = json_decode(Artisan::output(), true);

        $this->assertSame(['assets::img/heavy.jpg', 'assets::photos/profile.jpg'], $result['compressed']);
        $this->assertSame([], $result['skipped']);
        $this->assertGreaterThan(0, $result['saved_bytes']);
    }

    /**
     * With --json there is no way to answer a question, so it has to be told
     * to go ahead rather than compress without asking.
     */
    #[Test]
    public function json_output_needs_force_to_compress()
    {
        $heavy = Storage::disk('assets')->get('img/heavy.jpg');

        $this->artisan('statamic:asset-usage:compress', ['--json' => true])->assertFailed();

        $this->assertSame($heavy, Storage::disk('assets')->get('img/heavy.jpg'));
    }

    #[Test]
    public function it_only_looks_at_enabled_containers()
    {
        $this->artisan('statamic:asset-usage:compress', ['--container' => 'nope'])
            ->expectsOutputToContain('The container [nope] is not enabled for this addon.')
            ->assertFailed();
    }

    #[Test]
    public function it_waits_for_a_running_analysis()
    {
        $analyzer = Analyzer::make();
        $analyzer->store()->markPreparing($analyzer->compressor()->fingerprint());

        $this->artisan('statamic:asset-usage:compress', ['--force' => true])->assertFailed();

        $this->assertSame([], (new AssetLog)->entries(AssetLog::COMPRESSED));
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

        $this->artisan('statamic:asset-usage:compress', ['--force' => true])->assertFailed();

        $this->assertSame($heavy, Storage::disk('assets')->get('img/heavy.jpg'));
        $this->assertSame([], (new AssetLog)->entries(AssetLog::COMPRESSED));
    }
}
