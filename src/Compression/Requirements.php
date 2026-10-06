<?php

namespace KeyAgency\AssetUsage\Compression;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Statamic\Facades\Blink;
use Throwable;

/**
 * What this server can do: whether the image driver Glide is configured with
 * can be built at all (its PHP extension is missing otherwise), which of the
 * formats it can't handle, and whether pngquant is there. The CP says so
 * instead of failing, and compression is skipped when the driver is missing.
 */
final class Requirements
{
    /** pngquant is a program on the server, not a PHP extension. */
    public const PNGQUANT_URL = 'https://pngquant.org/';

    /** Where installing each built-in driver's PHP extension is explained. */
    private const INSTALL_URLS = [
        'gd' => 'https://www.php.net/manual/en/image.installation.php',
        'imagick' => 'https://www.php.net/manual/en/imagick.installation.php',
    ];

    public static function check(): array
    {
        return Blink::once('asset-usage-compression-requirements', function () {
            $driverName = self::configuredDriver();

            try {
                $manager = ImageManagers::glide();
            } catch (Throwable $e) {
                return [
                    'available' => false,
                    'driver' => $driverName,
                    'driver_install_url' => self::installUrl($driverName),
                    'pngquant_url' => self::PNGQUANT_URL,
                    'error' => $e->getMessage(),
                    'unsupported_formats' => [],
                    'pngquant' => Compressor::findPngquant() !== null,
                ];
            }

            $driver = ImageManagers::driver($manager);

            return [
                'available' => true,
                'driver' => $driverName,
                'driver_install_url' => self::installUrl($driverName),
                'pngquant_url' => self::PNGQUANT_URL,
                'error' => null,
                'unsupported_formats' => $driver
                    ? array_values(array_filter(['jpg', 'png', 'webp'], fn (string $format) => ! $driver->supports($format)))
                    : [],
                'pngquant' => Compressor::findPngquant() !== null,
            ];
        });
    }

    public static function available(): bool
    {
        return self::check()['available'];
    }

    /** Null for a custom driver, whose documentation could be anywhere. */
    private static function installUrl(string $driver): ?string
    {
        $key = match ($driver) {
            'gd', GdDriver::class => 'gd',
            'imagick', ImagickDriver::class => 'imagick',
            default => null,
        };

        return $key ? self::INSTALL_URLS[$key] : null;
    }

    /** As the site names it: "gd", "imagick" or a class name. */
    private static function configuredDriver(): string
    {
        $driver = config('statamic.assets.image_manipulation.driver', 'gd');

        if (is_array($driver)) {
            $driver = $driver['driver'] ?? 'gd';
        }

        return is_string($driver) ? $driver : get_debug_type($driver);
    }
}
