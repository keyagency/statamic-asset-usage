<?php

namespace KeyAgency\AssetUsage\Compression;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;
use League\Glide\ServerFactory;
use Statamic\Facades\Blink;
use Statamic\Facades\Glide;

/**
 * The image manager compression works with: the one Glide renders with, so a
 * site's `image_manipulation.driver` applies here too, whether that is "gd",
 * "imagick", a custom driver class or an array with options.
 */
final class ImageManagers
{
    /** Built once per request and driver: building Glide's server isn't free, and polling asks every few seconds. */
    public static function glide(): ImageManagerInterface
    {
        $key = 'asset-usage-image-manager-'.md5(serialize(config('statamic.assets.image_manipulation.driver')));

        return Blink::once($key, fn () => self::build());
    }

    private static function build(): ImageManagerInterface
    {
        $api = Glide::server()->getApi();

        // Not part of ApiInterface, so a custom Api may not have it.
        if (method_exists($api, 'getImageManager')) {
            return $api->getImageManager();
        }

        return (new ServerFactory([
            'driver' => config('statamic.assets.image_manipulation.driver'),
        ]))->getImageManager();
    }

    /** A method in Intervention v3, a public property in v4. */
    public static function driver(ImageManagerInterface $manager): ?DriverInterface
    {
        if (method_exists($manager, 'driver')) {
            return $manager->driver();
        }

        return $manager instanceof ImageManager && isset($manager->driver) ? $manager->driver : null;
    }

    /**
     * GD decodes into PHP's own memory, so it is the one driver that can run
     * into `memory_limit` instead of failing gracefully.
     */
    public static function countsAgainstMemoryLimit(ImageManagerInterface $manager): bool
    {
        return self::driver($manager) instanceof GdDriver;
    }

    /**
     * Intervention v4 renamed read() to decodeBinary(). Statamic allows both
     * versions, so whichever this site has is used.
     */
    public static function decode(ImageManagerInterface $manager, string $bytes): ImageInterface
    {
        return method_exists($manager, 'decodeBinary')
            ? $manager->decodeBinary($bytes)
            : $manager->read($bytes);
    }

    /**
     * An encoder with only the options its version knows: v3.9 has neither
     * `strip` nor, for WebP, `progressive`, and passes metadata through.
     */
    public static function encoder(string $class, array $options): object
    {
        $known = array_map(fn ($parameter) => $parameter->getName(), (new \ReflectionMethod($class, '__construct'))->getParameters());

        return new $class(...array_intersect_key($options, array_flip($known)));
    }
}
