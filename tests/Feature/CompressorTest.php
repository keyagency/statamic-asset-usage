<?php

namespace KeyAgency\AssetUsage\Tests\Feature;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Exceptions\NotSupportedException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;
use KeyAgency\AssetUsage\Compression\CompressionResult;
use KeyAgency\AssetUsage\Compression\Compressor;
use KeyAgency\AssetUsage\Compression\ImageManagers;
use KeyAgency\AssetUsage\Tests\Support\FailingDriver;
use KeyAgency\AssetUsage\Tests\Support\Images;
use KeyAgency\AssetUsage\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

class CompressorTest extends TestCase
{
    private function compressor($manager = null, ?string $pngquant = null, int $max = 3840): Compressor
    {
        return new Compressor($manager ?? self::manager(GdDriver::class), $max, 72, 82, 80, '70-90', $pngquant);
    }

    /** Intervention v4 renamed withDriver() to usingDriver(), and Statamic allows both versions. */
    private static function manager(string|DriverInterface $driver): ImageManagerInterface
    {
        return method_exists(ImageManager::class, 'usingDriver')
            ? ImageManager::usingDriver($driver)
            : ImageManager::withDriver($driver);
    }

    #[Test]
    public function it_scales_a_large_image_down_and_sets_the_dpi()
    {
        $result = $this->compressor(max: 400)->compress(Images::jpeg(800, 200, dpi: 300), 'jpg');

        $this->assertSame(CompressionResult::OK, $result->status);
        $this->assertSame([800, 200, 300], [$result->beforeWidth, $result->beforeHeight, $result->beforeDpi]);
        $this->assertSame([400, 100, 72], [$result->afterWidth, $result->afterHeight, $result->afterDpi]);
        $this->assertGreaterThan(0, $result->savings());
        $this->assertSame($result->afterBytes, strlen($result->bytes));
    }

    /**
     * Decoding applies the EXIF rotation, so compressing must not rotate again:
     * a wide photo marked to display tall comes out tall, not back to wide.
     */
    #[Test]
    public function an_exif_rotation_is_applied_exactly_once()
    {
        $result = $this->compressor()->compress(Images::jpegWithOrientation(300, 100, 6), 'jpg');

        $this->assertSame([300, 100], [$result->beforeWidth, $result->beforeHeight]);
        $this->assertSame([100, 300], [$result->afterWidth, $result->afterHeight]);
    }

    #[Test]
    public function it_never_enlarges_an_image()
    {
        $result = $this->compressor(max: 400)->compress(Images::jpeg(200, 100), 'jpeg');

        $this->assertSame([200, 100], [$result->afterWidth, $result->afterHeight]);
    }

    /**
     * Re-encoding an image that is already compressed harder than the
     * configured quality makes it bigger, which shows as a negative saving.
     */
    #[Test]
    public function an_image_that_is_already_small_shows_no_saving()
    {
        $result = $this->compressor()->compress(Images::jpeg(300, 300, quality: 30), 'jpg');

        $this->assertSame(CompressionResult::OK, $result->status);
        $this->assertLessThanOrEqual(0, $result->savings());
    }

    #[Test]
    public function a_png_without_pngquant_is_resized_and_saved_losslessly()
    {
        $result = $this->compressor(max: 100)->compress(Images::png(200, 50, dpi: 300), 'png');

        $this->assertSame(CompressionResult::OK, $result->status);
        $this->assertSame([100, 25, 72], [$result->afterWidth, $result->afterHeight, $result->afterDpi]);
        $this->assertStringStartsWith("\x89PNG", $result->bytes);
    }

    #[Test]
    public function a_png_goes_through_pngquant_when_it_is_installed()
    {
        if (! $pngquant = (new ExecutableFinder)->find('pngquant')) {
            $this->markTestSkipped('pngquant is not installed.');
        }

        $png = Images::png(200, 200);
        $lossless = $this->compressor()->compress($png, 'png');
        $quantized = $this->compressor(pngquant: $pngquant)->compress($png, 'png');

        $this->assertLessThanOrEqual($lossless->afterBytes, $quantized->afterBytes);
        $this->assertSame(72, $quantized->afterDpi);
    }

    #[Test]
    public function formats_it_does_not_compress_are_unsupported()
    {
        $result = $this->compressor()->compress('GIF89a', 'gif');

        $this->assertSame(CompressionResult::UNSUPPORTED, $result->status);
        $this->assertNull($result->savings());
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_an_error()
    {
        $this->assertSame(CompressionResult::ERROR, $this->compressor()->compress('fake-file-contents', 'jpg')->status);
    }

    /**
     * Decided before decoding, so a huge image never takes the process down.
     */
    #[Test]
    public function gd_refuses_an_image_that_would_not_fit_in_memory()
    {
        $limit = ini_get('memory_limit');

        ini_set('memory_limit', (string) max(512 * 1024 * 1024, memory_get_usage(true) + 64 * 1024 * 1024));

        try {
            $result = $this->compressor()->compress(Images::jpegHeader(20000, 20000), 'jpg');
        } finally {
            ini_set('memory_limit', $limit);
        }

        $this->assertSame(CompressionResult::TOO_LARGE, $result->status);
        $this->assertSame([20000, 20000], [$result->beforeWidth, $result->beforeHeight]);
    }

    #[Test]
    public function only_gd_counts_against_the_memory_limit()
    {
        $this->assertTrue(ImageManagers::countsAgainstMemoryLimit(self::manager(GdDriver::class)));

        if (extension_loaded('imagick')) {
            $this->assertFalse(ImageManagers::countsAgainstMemoryLimit(self::manager(ImagickDriver::class)));
        }
    }

    #[Test]
    public function a_driver_that_cannot_handle_the_image_makes_it_unsupported()
    {
        $unsupported = self::manager(FailingDriver::throwing(new NotSupportedException('No decoder for this format')));
        $broken = self::manager(FailingDriver::throwing(new RuntimeException('Something broke')));

        $jpeg = Images::jpeg(50, 50);

        $result = $this->compressor($unsupported)->compress($jpeg, 'jpg');
        $this->assertSame(CompressionResult::UNSUPPORTED, $result->status);
        $this->assertSame('No decoder for this format', $result->reason);

        $this->assertSame(CompressionResult::ERROR, $this->compressor($broken)->compress($jpeg, 'jpg')->status);
    }

    #[Test]
    public function it_reports_a_colour_profile_that_did_not_survive()
    {
        $this->assertTrue($this->compressor()->compress(Images::jpegWithProfile(50, 50), 'jpg')->iccLost);
        $this->assertFalse($this->compressor()->compress(Images::jpeg(50, 50), 'jpg')->iccLost);
    }

    /**
     * Intervention sets only the numbers on Imagick, so a PNG stored per
     * centimetre used to come out at 72 per centimetre.
     */
    #[Test]
    public function imagick_writes_the_dpi_per_inch()
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is not installed.');
        }

        $result = $this->compressor(self::manager(ImagickDriver::class))->compress(Images::png(50, 50, dpi: 300), 'png');

        $this->assertSame(72, $result->afterDpi);
    }

    #[Test]
    public function it_uses_the_driver_glide_is_configured_with()
    {
        $driver = function ($config) {
            config(['statamic.assets.image_manipulation.driver' => $config]);

            return ImageManagers::driver(ImageManagers::glide());
        };

        $this->assertInstanceOf(GdDriver::class, $driver('gd'));
        $this->assertInstanceOf(GdDriver::class, $driver(GdDriver::class));

        // Glide 3.2, which goes with Intervention v3, has no array form; Glide breaks on it itself.
        if (method_exists(ImageManager::class, 'usingDriver')) {
            $this->assertInstanceOf(GdDriver::class, $driver(['driver' => 'gd']));
        }

        if (extension_loaded('imagick')) {
            $this->assertInstanceOf(ImagickDriver::class, $driver('imagick'));
        }
    }

    #[Test]
    public function the_fingerprint_changes_with_the_settings()
    {
        $this->assertSame($this->compressor()->fingerprint(), $this->compressor()->fingerprint());
        $this->assertNotSame($this->compressor()->fingerprint(), $this->compressor(max: 2000)->fingerprint());
    }
}
