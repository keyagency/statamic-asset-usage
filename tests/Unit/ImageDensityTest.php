<?php

namespace KeyAgency\AssetUsage\Tests\Unit;

use KeyAgency\AssetUsage\Support\ImageDensity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ImageDensityTest extends TestCase
{
    public static function png(int $width = 1, int $height = 1, ?array $phys = null): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0));

        if ($phys) {
            $png .= $chunk('pHYs', pack('NNC', $phys[0], $phys[0], $phys[1]));
        }

        $rows = str_repeat("\0".str_repeat("\0", $width), $height);

        return $png.$chunk('IDAT', gzcompress($rows)).$chunk('IEND', '');
    }

    public static function jpeg(?array $jfif = null, ?array $exif = null): string
    {
        $segment = fn (int $marker, string $data) => "\xFF".chr($marker).pack('n', strlen($data) + 2).$data;

        $jpeg = "\xFF\xD8";

        if ($jfif) {
            $jpeg .= $segment(0xE0, "JFIF\0".pack('CCCnnCC', 1, 1, $jfif[1], $jfif[0], $jfif[0], 0, 0));
        }

        if ($exif) {
            [$resolution, $unit, $big] = $exif + [2 => false];
            $s = $big ? 'n' : 'v';
            $l = $big ? 'N' : 'V';

            // Header, then IFD0 at offset 8 with two entries, then the rational at offset 38.
            $tiff = ($big ? 'MM' : 'II').pack($s, 42).pack($l, 8)
                .pack($s, 2)
                .pack($s, 0x011A).pack($s, 5).pack($l, 1).pack($l, 38)
                .pack($s, 0x0128).pack($s, 3).pack($l, 1).pack($s, $unit).pack($s, 0)
                .pack($l, 0)
                .pack($l, $resolution).pack($l, 1);

            $jpeg .= $segment(0xE1, "Exif\0\0".$tiff);
        }

        return $jpeg.$segment(0xDA, "\0\0\0").'scan-data';
    }

    #[Test]
    public function it_reads_the_dpi_of_a_png()
    {
        // 11811 pixels per metre is 300 DPI.
        $this->assertSame(300, ImageDensity::fromBytes(self::png(phys: [11811, 1])));
        $this->assertSame(72, ImageDensity::fromBytes(self::png(phys: [2835, 1])));
    }

    #[Test]
    public function a_png_without_an_absolute_density_has_none()
    {
        $this->assertNull(ImageDensity::fromBytes(self::png()));
        $this->assertNull(ImageDensity::fromBytes(self::png(phys: [1, 0])));
    }

    #[Test]
    public function it_reads_the_dpi_of_a_jpeg_from_jfif()
    {
        $this->assertSame(96, ImageDensity::fromBytes(self::jpeg(jfif: [96, 1])));
        $this->assertSame(300, ImageDensity::fromBytes(self::jpeg(jfif: [118, 2])));
        $this->assertNull(ImageDensity::fromBytes(self::jpeg(jfif: [1, 0])));
    }

    #[Test]
    public function exif_wins_over_jfif()
    {
        $this->assertSame(300, ImageDensity::fromBytes(self::jpeg(jfif: [72, 1], exif: [300, 2])));
        $this->assertSame(300, ImageDensity::fromBytes(self::jpeg(exif: [300, 2, true])));
        $this->assertSame(254, ImageDensity::fromBytes(self::jpeg(exif: [100, 3])));
    }

    #[Test]
    public function anything_else_has_no_dpi()
    {
        $this->assertNull(ImageDensity::fromBytes('GIF89a'));
        $this->assertNull(ImageDensity::fromBytes(''));
        $this->assertNull(ImageDensity::fromBytes("\xFF\xD8\xFF"));
        $this->assertNull(ImageDensity::fromBytes(substr(self::png(phys: [11811, 1]), 0, 40)));
    }
}
