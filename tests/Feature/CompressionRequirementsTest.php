<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    private function editorMeta(): ?array
    {
        return Asset::find(self::ID)->blueprint()->field(InjectUsageField::HANDLE)->meta()['compression'];
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

        // Not analysed yet, so there is nothing to offer.
        $this->assertNull($this->editorMeta());

        AnalyzeAllCompression::dispatch();

        $meta = $this->editorMeta();

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

        $this->assertFalse($meta['compressible']);
        $this->assertTrue($meta['has_backup']);
        $this->assertNotNull($meta['expires_at']);
        $this->assertGreaterThan(0, $meta['compressed']['savings']);
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
