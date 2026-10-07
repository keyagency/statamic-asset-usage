<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\CompressionResult;
use KeyAgency\AssetUsage\Compression\Compressor;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Jobs\AnalyzeAllCompression;
use KeyAgency\AssetUsage\Jobs\AnalyzeCompression;
use KeyAgency\AssetUsage\Listeners\InjectUsageField;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\Support\MissingDriver;
use KeyAgency\AssetUsage\Tests\Support\NoWebpDriver;
use KeyAgency\AssetUsage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\AssetUploaded;
use Statamic\Facades\Asset;
use Statamic\Facades\Blink;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class CompressionRequirementsTest extends TestCase
{
    private const ID = 'assets::img/heavy.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.asset-usage.compression.max_dimension' => 200]);

        $this->makeContainer('assets');
        Storage::disk('assets')->put('img/heavy.jpg', Images::jpeg(600, 600, dpi: 300, quality: 98));
    }

    private function useDriver(string $driver): void
    {
        config(['statamic.assets.image_manipulation.driver' => $driver]);

        // The check is made once per request.
        Blink::forget('asset-usage-compression-requirements');
    }

    private function editorMeta(string $id = self::ID): ?array
    {
        return Asset::find($id)->blueprint()->field(InjectUsageField::HANDLE)->meta()['compression'];
    }

    #[Test]
    public function a_missing_driver_extension_is_reported_instead_of_breaking_the_tools_page()
    {
        $this->useDriver(MissingDriver::class);

        $response = $this->actingAs($this->superUser())->getJson(cp_route('asset-usage.assets'));

        $response->assertOk();
        $this->assertFalse($response->json('meta.compression.available'));
        $this->assertSame(MissingDriver::class, $response->json('meta.compression.requirements.driver'));
        // A custom driver's documentation could be anywhere, so there is no link to it.
        $this->assertNull($response->json('meta.compression.requirements.driver_install_url'));
        $this->assertNull($response->json('data.0.compression'));
        // The exception message is for the console, not for every CP user.
        $this->assertArrayNotHasKey('error', $response->json('meta.compression.requirements'));
    }

    #[Test]
    public function nothing_tries_to_compress_without_the_driver()
    {
        $this->useDriver(MissingDriver::class);
        Queue::fake();

        $user = $this->superUser();

        $this->actingAs($user)->postJson(cp_route('asset-usage.compress.analyze'))->assertStatus(503);
        $this->actingAs($user)->get(cp_route('asset-usage.compress.show', ['asset' => self::ID]))->assertStatus(503);

        AssetUploaded::dispatch(Asset::find(self::ID), 'heavy.jpg');
        Queue::assertNotPushed(AnalyzeCompression::class);

        $this->assertNull($this->editorMeta());

        $this->artisan('statamic:asset-usage:analyze')->assertFailed();
        $this->artisan('statamic:asset-usage:compress', ['--force' => true])->assertFailed();
    }

    #[Test]
    public function formats_the_driver_cannot_handle_are_reported_and_skipped()
    {
        $this->useDriver(NoWebpDriver::class);

        $this->assertSame(['webp'], Requirements::check()['unsupported_formats']);

        $result = Compressor::make()->compress('RIFF....WEBP', 'webp');

        $this->assertSame(CompressionResult::UNSUPPORTED, $result->status);
    }

    #[Test]
    public function the_status_says_whether_pngquant_is_there()
    {
        $status = $this->actingAs($this->superUser())->getJson(cp_route('asset-usage.assets'))->json('meta.compression');

        $this->assertTrue($status['available']);
        $this->assertSame(Compressor::findPngquant() !== null, $status['requirements']['pngquant']);
        $this->assertSame(Requirements::PNGQUANT_URL, $status['requirements']['pngquant_url']);
        $this->assertStringContainsString('php.net', $status['requirements']['driver_install_url']);
    }

    #[Test]
    public function the_asset_editor_offers_to_compress_an_image_that_can_get_smaller()
    {
        $this->actingAs($this->superUser());

        // Not analysed yet, which the editor says rather than showing nothing.
        $meta = $this->editorMeta();

        $this->assertSame('not_analyzed', $meta['state']);
        $this->assertFalse($meta['compressible']);
        $this->assertStringContainsString('asset-usage/compress', $meta['url']);

        AnalyzeAllCompression::dispatch();

        $meta = $this->editorMeta();

        $this->assertSame('compressible', $meta['state']);
        $this->assertTrue($meta['compressible']);
        $this->assertGreaterThan(20, $meta['savings']);
        $this->assertFalse($meta['has_backup']);
        $this->assertStringContainsString('asset-usage/compress', $meta['url']);
    }

    #[Test]
    public function the_asset_editor_offers_the_original_back_after_compressing()
    {
        $this->actingAs($this->superUser());
        AnalyzeAllCompression::dispatch();

        $version = $this->get(cp_route('asset-usage.compress.show', ['asset' => self::ID]))->viewData('page')['props']['asset']['version'];
        $this->postJson(cp_route('asset-usage.compress.store'), ['asset' => self::ID, 'version' => $version])->assertOk();

        $meta = $this->editorMeta();

        $this->assertSame('restorable', $meta['state']);
        $this->assertFalse($meta['compressible']);
        $this->assertTrue($meta['has_backup']);
        $this->assertNotNull($meta['expires_at']);
        $this->assertGreaterThan(0, $meta['compressed']['savings']);
    }

    /**
     * An image that was checked shows the outcome too, so an upload that has
     * nothing to gain doesn't look like one that was never checked.
     */
    #[Test]
    public function the_asset_editor_says_when_compressing_would_not_help()
    {
        Storage::disk('assets')->put('img/small.jpg', Images::jpeg(100, 100, quality: 30));

        $this->actingAs($this->superUser());
        AnalyzeAllCompression::dispatch();

        // Already squeezed harder than the configured quality, so re-encoding only makes it bigger.
        $meta = $this->editorMeta('assets::img/small.jpg');

        $this->assertSame('larger', $meta['state']);
        $this->assertFalse($meta['compressible']);
        $this->assertLessThan(0, $meta['savings']);

        config(['statamic.asset-usage.compression.threshold_percent' => 99]);

        $meta = $this->editorMeta();

        $this->assertSame('below_threshold', $meta['state']);
        $this->assertSame(99, $meta['threshold']);
        $this->assertGreaterThan(0, $meta['savings']);
    }

    /**
     * Once the original is pruned there is nothing to put back, and the image
     * is still not offered again.
     */
    #[Test]
    public function the_asset_editor_says_an_image_was_compressed_after_its_original_is_gone()
    {
        $this->actingAs($this->superUser());
        AnalyzeAllCompression::dispatch();

        $version = $this->get(cp_route('asset-usage.compress.show', ['asset' => self::ID]))->viewData('page')['props']['asset']['version'];
        $this->postJson(cp_route('asset-usage.compress.store'), ['asset' => self::ID, 'version' => $version])->assertOk();

        $this->travel(31)->days();
        $this->artisan('statamic:asset-usage:prune-originals')->assertSuccessful();

        $meta = $this->editorMeta();

        $this->assertSame('compressed', $meta['state']);
        $this->assertFalse($meta['has_backup']);
        $this->assertNull($meta['compressed']);
    }

    #[Test]
    public function the_asset_editor_says_when_an_image_is_too_large_to_compress()
    {
        Storage::disk('assets')->put('img/huge.jpg', Images::jpegHeader(20000, 20000));
        $limit = ini_get('memory_limit');

        ini_set('memory_limit', (string) max(512 * 1024 * 1024, memory_get_usage(true) + 64 * 1024 * 1024));

        try {
            Analyzer::make()->analyze([Asset::find('assets::img/huge.jpg')]);
        } finally {
            ini_set('memory_limit', $limit);
        }

        $this->actingAs($this->superUser());
        $meta = $this->editorMeta('assets::img/huge.jpg');

        $this->assertSame('too_large', $meta['state']);
        $this->assertSame([20000, 20000], [$meta['width'], $meta['height']]);
    }

    #[Test]
    public function the_asset_editor_offers_nothing_to_users_who_may_not_compress()
    {
        AnalyzeAllCompression::dispatch();

        Role::make('editor')->addPermission(['access cp', 'view assets assets', 'edit assets assets', 'upload assets assets'])->save();
        $this->actingAs(tap(User::make()->email('robin@example.com')->assignRole('editor'))->save());

        $this->assertNull($this->editorMeta());

        Role::find('editor')->addPermission('compress assets')->save();
        $this->actingAs(User::findByEmail('robin@example.com'));

        $this->assertTrue($this->editorMeta()['compressible']);
    }
}
