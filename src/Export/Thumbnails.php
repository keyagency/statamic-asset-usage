<?php

namespace KeyAgency\AssetUsage\Export;

use Illuminate\Support\Facades\File;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Interfaces\ImageManagerInterface;
use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Compressor;
use KeyAgency\AssetUsage\Compression\ImageManagers;
use KeyAgency\AssetUsage\Compression\Requirements;
use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * The small images in the PDF. Each is made from the original once per version
 * of the file and kept, so a second download doesn't decode every image again.
 * They live next to the index rather than in Glide's cache, whose layout is
 * Statamic's to change.
 */
class Thumbnails
{
    /** Twice the size the PDF shows them at, so they stay sharp when zoomed in or printed. */
    public const SIZE = 96;

    private const QUALITY = 70;

    /** Enough of a file for its dimensions; the header sits at the very start. */
    private const HEADER_BYTES = 262144;

    public function __construct(private ?ImageManagerInterface $manager = null) {}

    public function directory(): string
    {
        return storage_path('statamic/asset-usage/thumbnails');
    }

    public function path(Asset $asset): string
    {
        return $this->directory().'/'.md5($asset->id()).'-'.Analyzer::version($asset).'.jpg';
    }

    /** An SVG, a PDF or a video gets its extension in the PDF instead. */
    public static function applies(Asset $asset): bool
    {
        return $asset->isImage() && strtolower($asset->extension()) !== 'svg';
    }

    public function has(Asset $asset): bool
    {
        return File::exists($this->path($asset));
    }

    public function dataUri(Asset $asset): ?string
    {
        return $this->has($asset) ? 'data:image/jpeg;base64,'.base64_encode(File::get($this->path($asset))) : null;
    }

    /** Whether the asset has a thumbnail now. One that can't be made is left out, never an error. */
    public function make(Asset $asset): bool
    {
        if ($this->has($asset)) {
            return true;
        }

        if (! self::applies($asset) || ! Requirements::available()) {
            return false;
        }

        $manager = $this->manager ??= ImageManagers::glide();
        $driver = ImageManagers::driver($manager);

        if ($driver && ! $driver->supports(strtolower($asset->extension()))) {
            return false;
        }

        try {
            // Checked before the whole file is read: running out of memory is a fatal error nothing can catch.
            if (! $this->fitsInMemory($asset, $manager)) {
                return false;
            }

            $image = ImageManagers::decode($manager, (string) $asset->contents());

            if ($image->isAnimated()) {
                $image->removeAnimation();
            }

            $bytes = (string) $image->cover(self::SIZE, self::SIZE)
                ->encode(ImageManagers::encoder(JpegEncoder::class, ['quality' => self::QUALITY, 'strip' => true]));

            unset($image);
        } catch (Throwable) {
            return false;
        }

        $this->write($asset, $bytes);

        return true;
    }

    /** Every version kept for this asset id, for when the asset is deleted or moved. */
    public function delete(string $id): void
    {
        File::delete(File::glob($this->directory().'/'.md5($id).'-*.jpg'));
    }

    /** The same estimate the compressor makes: GD decodes the whole image into PHP's memory. */
    private function fitsInMemory(Asset $asset, ImageManagerInterface $manager): bool
    {
        if (($limit = Compressor::memoryLimit()) === null) {
            return true;
        }

        $needed = (int) $asset->size() * 2;

        if (ImageManagers::countsAgainstMemoryLimit($manager)) {
            [$width, $height] = $this->dimensions($asset);
            $needed += ((int) $width * (int) $height + self::SIZE * self::SIZE) * 5;
        }

        return $needed < $limit - memory_get_usage();
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(Asset $asset): array
    {
        $stream = $asset->stream();
        $header = (string) stream_get_contents($stream, self::HEADER_BYTES);
        fclose($stream);

        $info = @getimagesizefromstring($header);

        return $info ? [$info[0], $info[1]] : [null, null];
    }

    /** Older versions of the file go, and the new one is renamed into place so a reader never sees half of it. */
    private function write(Asset $asset, string $bytes): void
    {
        File::ensureDirectoryExists($this->directory());

        $this->delete($asset->id());

        $temp = $this->path($asset).'.'.uniqid().'.tmp';

        File::put($temp, $bytes);
        File::move($temp, $this->path($asset));
    }
}
