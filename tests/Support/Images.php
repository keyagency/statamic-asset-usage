<?php

namespace KeyAgency\AssetUsage\Tests\Support;

/**
 * Real images made with GD, with enough detail that compression has
 * something to do.
 */
final class Images
{
    public static function jpeg(int $width, int $height, int $dpi = 72, int $quality = 95): string
    {
        $image = self::canvas($width, $height);
        imageresolution($image, $dpi);

        ob_start();
        imagejpeg($image, null, $quality);

        return ob_get_clean();
    }

    public static function png(int $width, int $height, int $dpi = 72): string
    {
        $image = self::canvas($width, $height);
        imageresolution($image, $dpi);

        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    /** A JPEG with an (empty) ICC profile segment right after the start marker. */
    public static function jpegWithProfile(int $width, int $height): string
    {
        $jpeg = self::jpeg($width, $height);
        $data = "ICC_PROFILE\0\x01\x01".str_repeat("\0", 128);

        return "\xFF\xD8\xFF\xE2".pack('n', strlen($data) + 2).$data.substr($jpeg, 2);
    }

    /**
     * A JPEG with an EXIF orientation, which tells a viewer to rotate it. 6 means
     * a quarter turn clockwise, so a wide image displays tall.
     */
    public static function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $jpeg = self::jpeg($width, $height);

        // Big-endian TIFF with one IFD0 entry: Orientation (0x0112), SHORT, count 1.
        $tiff = 'MM'.pack('nN', 42, 8).pack('n', 1).pack('nnNnn', 0x0112, 3, 1, $orientation, 0).pack('N', 0);
        $data = "Exif\0\0".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($data) + 2).$data.substr($jpeg, 2);
    }

    /**
     * Only the start of a JPEG, claiming any size. Enough for getimagesize(),
     * so a size check can be tested without decoding a huge image.
     */
    public static function jpegHeader(int $width, int $height): string
    {
        return "\xFF\xD8\xFF\xC0".pack('nCnnC', 17, 8, $height, $width, 3)."\x01\x22\x00\x02\x11\x01\x03\x11\x01";
    }

    private static function canvas(int $width, int $height)
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(42);

        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                $color = imagecolorallocate($image, ($x + mt_rand(0, 40)) % 256, ($y + mt_rand(0, 40)) % 256, mt_rand(0, 255));
                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $color);
            }
        }

        return $image;
    }
}
