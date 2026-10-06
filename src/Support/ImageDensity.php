<?php

namespace KeyAgency\AssetUsage\Support;

use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * Reads the DPI an image file declares. Statamic doesn't keep it in an asset's
 * meta, so it comes from the file itself: the EXIF or JFIF header of a JPEG and
 * the pHYs chunk of a PNG. Other formats have no DPI worth showing.
 *
 * Only the start of the file is read, and the result is cached per version of
 * the file, so a remote disk is asked once and not on every page view.
 */
final class ImageDensity
{
    /** Both headers sit at the very start of the file, well within this. */
    private const READ_BYTES = 131072;

    public static function for(Asset $asset): ?int
    {
        if (! in_array(strtolower($asset->extension()), ['jpg', 'jpeg', 'png'], true)) {
            return null;
        }

        $key = 'asset-usage::dpi::'.md5($asset->id().'|'.$asset->size().'|'.$asset->lastModified()->timestamp);

        if (($cached = Cache::get($key)) !== null) {
            return $cached ?: null;
        }

        // A read that failed (a remote disk hiccup) isn't cached, so the next request tries again.
        if (($bytes = self::read($asset)) === null) {
            return null;
        }

        // Cached as 0 when the file declares nothing, because the cache doesn't store null.
        $dpi = self::fromBytes($bytes) ?? 0;
        Cache::forever($key, $dpi);

        return $dpi ?: null;
    }

    /** The start of the file, or null when it couldn't be read. */
    private static function read(Asset $asset): ?string
    {
        try {
            $stream = $asset->stream();
            $bytes = '';

            while (strlen($bytes) < self::READ_BYTES && ! feof($stream)) {
                $chunk = fread($stream, self::READ_BYTES - strlen($bytes));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $bytes .= $chunk;
            }

            fclose($stream);
        } catch (Throwable) {
            return null;
        }

        return $bytes;
    }

    public static function fromBytes(string $bytes): ?int
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return self::fromPng($bytes);
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::fromJpeg($bytes);
        }

        return null;
    }

    private static function fromPng(string $bytes): ?int
    {
        $offset = 8;

        while ($offset + 8 <= strlen($bytes)) {
            ['length' => $length, 'type' => $type] = unpack('Nlength/a4type', $bytes, $offset);

            if ($type === 'pHYs' && $length >= 9 && $offset + 17 <= strlen($bytes)) {
                ['x' => $perUnit, 'unit' => $unit] = unpack('Nx/Ny/Cunit', $bytes, $offset + 8);

                // Unit 1 is pixels per metre; 0 only gives an aspect ratio.
                return $unit === 1 && $perUnit > 0 ? (int) round($perUnit * 0.0254) : null;
            }

            if ($type === 'IDAT' || $type === 'IEND') {
                return null;
            }

            $offset += 12 + $length;
        }

        return null;
    }

    /**
     * EXIF wins over JFIF when both are present: editors that set a DPI write
     * it to EXIF, while JFIF often holds a default of 72 or no unit at all.
     */
    private static function fromJpeg(string $bytes): ?int
    {
        $offset = 2;
        $jfif = null;

        while ($offset + 4 <= strlen($bytes) && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);

            // Start of scan: the headers are behind us.
            if ($marker === 0xDA) {
                break;
            }

            $length = unpack('n', $bytes, $offset + 2)[1];
            $segment = substr($bytes, $offset + 4, max(0, $length - 2));

            if ($marker === 0xE1 && str_starts_with($segment, "Exif\0\0")) {
                if ($dpi = self::fromExif(substr($segment, 6))) {
                    return $dpi;
                }
            }

            if ($marker === 0xE0 && str_starts_with($segment, "JFIF\0") && strlen($segment) >= 12) {
                ['unit' => $unit, 'x' => $density] = unpack('Cunit/nx', $segment, 7);

                // Unit 1 is dots per inch, 2 is dots per centimetre.
                $jfif = match ($unit) {
                    1 => $density,
                    2 => (int) round($density * 2.54),
                    default => null,
                };
            }

            $offset += 2 + $length;
        }

        return $jfif ?: null;
    }

    /** Reads XResolution and ResolutionUnit from IFD0 of a TIFF structure. */
    private static function fromExif(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }

        $big = substr($tiff, 0, 2) === 'MM';
        $short = $big ? 'n' : 'v';
        $long = $big ? 'N' : 'V';

        $ifd = unpack($long, $tiff, 4)[1];

        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }

        $entries = unpack($short, $tiff, $ifd)[1];
        $resolution = null;
        $unit = 2;

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($tiff)) {
                break;
            }

            $tag = unpack($short, $tiff, $entry)[1];

            if ($tag === 0x011A) {
                $pointer = unpack($long, $tiff, $entry + 8)[1];

                if ($pointer + 8 <= strlen($tiff)) {
                    $numerator = unpack($long, $tiff, $pointer)[1];
                    $denominator = unpack($long, $tiff, $pointer + 4)[1];
                    $resolution = $denominator ? $numerator / $denominator : null;
                }
            }

            if ($tag === 0x0128) {
                $unit = unpack($short, $tiff, $entry + 8)[1];
            }
        }

        if (! $resolution) {
            return null;
        }

        // Unit 2 is inches, 3 is centimetres; 1 has no absolute unit.
        return match ($unit) {
            2 => (int) round($resolution),
            3 => (int) round($resolution * 2.54),
            default => null,
        };
    }
}
