<?php

namespace KeyAgency\AssetUsage\Tests\Unit;

use KeyAgency\AssetUsage\Support\ColorProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ColorProfileTest extends TestCase
{
    #[Test]
    public function it_finds_a_profile_in_a_jpeg()
    {
        $segment = fn (int $marker, string $data) => "\xFF".chr($marker).pack('n', strlen($data) + 2).$data;

        $with = "\xFF\xD8".$segment(0xE2, "ICC_PROFILE\0\x01\x01profile").$segment(0xDA, "\0\0\0");
        $without = "\xFF\xD8".$segment(0xE1, "Exif\0\0").$segment(0xDA, "\0\0\0");

        $this->assertTrue(ColorProfile::embedded($with));
        $this->assertFalse(ColorProfile::embedded($without));
    }

    #[Test]
    public function it_finds_a_profile_in_a_png()
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $header = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0));

        $this->assertTrue(ColorProfile::embedded($header.$chunk('iCCP', "sRGB\0\0data").$chunk('IDAT', '')));
        $this->assertFalse(ColorProfile::embedded($header.$chunk('IDAT', '').$chunk('iCCP', "late\0\0")));
    }

    #[Test]
    public function it_finds_a_profile_in_a_webp()
    {
        $chunk = fn (string $type, string $data) => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
        $webp = fn (string $chunks) => 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks;

        $this->assertTrue(ColorProfile::embedded($webp($chunk('VP8X', str_repeat("\0", 10)).$chunk('ICCP', 'abc').$chunk('VP8 ', 'x'))));
        $this->assertFalse(ColorProfile::embedded($webp($chunk('VP8 ', 'x'))));
    }

    #[Test]
    public function anything_else_has_none()
    {
        $this->assertFalse(ColorProfile::embedded(''));
        $this->assertFalse(ColorProfile::embedded('GIF89a'));
    }
}
