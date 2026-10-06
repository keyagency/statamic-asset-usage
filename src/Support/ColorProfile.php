<?php

namespace KeyAgency\AssetUsage\Support;

/**
 * Tells whether an image file carries an embedded ICC colour profile. Whether
 * one survives compression depends on the image driver (GD always drops it),
 * so compression compares the file before and after instead of guessing from
 * the driver.
 */
final class ColorProfile
{
    public static function embedded(string $bytes): bool
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return self::inPng($bytes);
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::inJpeg($bytes);
        }

        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return self::inWebp($bytes);
        }

        return false;
    }

    private static function inPng(string $bytes): bool
    {
        $offset = 8;

        while ($offset + 8 <= strlen($bytes)) {
            ['length' => $length, 'type' => $type] = unpack('Nlength/a4type', $bytes, $offset);

            if ($type === 'iCCP') {
                return true;
            }

            if ($type === 'IDAT' || $type === 'IEND') {
                return false;
            }

            $offset += 12 + $length;
        }

        return false;
    }

    private static function inJpeg(string $bytes): bool
    {
        $offset = 2;

        while ($offset + 4 <= strlen($bytes) && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xDA) {
                return false;
            }

            $length = unpack('n', $bytes, $offset + 2)[1];

            if ($marker === 0xE2 && substr($bytes, $offset + 4, 12) === "ICC_PROFILE\0") {
                return true;
            }

            $offset += 2 + $length;
        }

        return false;
    }

    private static function inWebp(string $bytes): bool
    {
        $offset = 12;

        while ($offset + 8 <= strlen($bytes)) {
            $type = substr($bytes, $offset, 4);
            $length = unpack('V', $bytes, $offset + 4)[1];

            if ($type === 'ICCP') {
                return true;
            }

            // Chunks are padded to an even length.
            $offset += 8 + $length + ($length % 2);
        }

        return false;
    }
}
