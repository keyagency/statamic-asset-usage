<?php

namespace KeyAgency\AssetUsage\Compression;

use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\NotSupportedException;
use Intervention\Image\Interfaces\ImageManagerInterface;
use KeyAgency\AssetUsage\Support\ColorProfile;
use KeyAgency\AssetUsage\Support\ImageDensity;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Facades\Blink;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Compresses one image the way the key-asset-convert skill does: scale down to
 * a maximum size (never up), set the DPI, strip metadata and re-encode in the
 * same format. PNGs go through pngquant when the server has it.
 */
class Compressor
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** pngquant's exit codes for "quality not reached" and "result is larger". */
    private const PNGQUANT_SKIPPED = [98, 99];

    public function __construct(
        private readonly ImageManagerInterface $manager,
        private readonly int $maxDimension,
        private readonly int $dpi,
        private readonly int $jpgQuality,
        private readonly int $webpQuality,
        private readonly string $pngQuality,
        private readonly ?string $pngquant,
    ) {}

    public static function make(): self
    {
        return new self(
            ImageManagers::glide(),
            Settings::compressionMaxDimension(),
            Settings::compressionDpi(),
            Settings::jpgQuality(),
            Settings::webpQuality(),
            Settings::pngQuality(),
            self::findPngquant(),
        );
    }

    /** Looked up once per request: searching the PATH on every status poll adds up. */
    public static function findPngquant(): ?string
    {
        return Blink::once('asset-usage-pngquant-'.Settings::pngquantBinary(), function () {
            if ($configured = Settings::pngquantBinary()) {
                return is_executable($configured) ? $configured : null;
            }

            return (new ExecutableFinder)->find('pngquant');
        });
    }

    public static function supports(string $extension): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS, true);
    }

    /**
     * Everything a result depends on. Stored with each analysis so a change in
     * settings, driver or pngquant shows up as out of date.
     */
    public function fingerprint(): string
    {
        $driver = ImageManagers::driver($this->manager);

        return md5(json_encode([
            $this->maxDimension,
            $this->dpi,
            $this->jpgQuality,
            $this->webpQuality,
            $this->pngQuality,
            $this->pngquant !== null,
            $driver ? $driver::class : $this->manager::class,
        ]));
    }

    public function describe(): array
    {
        return [
            'max_dimension' => $this->maxDimension,
            'dpi' => $this->dpi,
            'jpg_quality' => $this->jpgQuality,
            'webp_quality' => $this->webpQuality,
            'pngquant' => $this->pngquant !== null,
        ];
    }

    public function compress(string $bytes, string $extension): CompressionResult
    {
        $extension = strtolower($extension);
        $before = strlen($bytes);

        if (! self::supports($extension)) {
            return CompressionResult::failed(CompressionResult::UNSUPPORTED, $before, "Unsupported format: {$extension}");
        }

        $driver = ImageManagers::driver($this->manager);

        if ($driver && ! $driver->supports($extension)) {
            return CompressionResult::failed(CompressionResult::UNSUPPORTED, $before, "The image driver on this server can't handle {$extension}.");
        }

        if (! $info = @getimagesizefromstring($bytes)) {
            return CompressionResult::failed(CompressionResult::ERROR, $before, 'The file could not be read as an image.');
        }

        [$width, $height] = $info;

        if (! $this->fitsInMemory($width, $height, $before)) {
            return CompressionResult::failed(CompressionResult::TOO_LARGE, $before, 'Not enough memory to decode this image with GD.', $width, $height);
        }

        try {
            $image = $this->decode($bytes);

            if ($image->isAnimated()) {
                return CompressionResult::failed(CompressionResult::UNSUPPORTED, $before, 'Animated images are not compressed.', $width, $height);
            }

            // Decoding already applied the EXIF rotation, in v3 and v4 alike.
            $image->scaleDown($this->maxDimension, $this->maxDimension)
                ->setResolution($this->dpi, $this->dpi);

            /**
             * Intervention sets only the numbers on Imagick, which keeps the
             * source's unit: a PNG stored per centimetre would end up at 72 per
             * centimetre, so 183 DPI.
             */
            foreach ($image as $frame) {
                if ($frame->native() instanceof \Imagick) {
                    $frame->native()->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
                }
            }

            $output = (string) $image->encode(match ($extension) {
                'png' => new PngEncoder,
                'webp' => self::encoder(WebpEncoder::class, ['quality' => $this->webpQuality, 'strip' => true]),
                default => self::encoder(JpegEncoder::class, ['quality' => $this->jpgQuality, 'progressive' => true, 'strip' => true]),
            });

            unset($image);
        } catch (NotSupportedException $e) {
            return CompressionResult::failed(CompressionResult::UNSUPPORTED, $before, $e->getMessage(), $width, $height);
        } catch (Throwable $e) {
            return CompressionResult::failed(CompressionResult::ERROR, $before, $e->getMessage(), $width, $height);
        }

        if ($extension === 'png' && $this->pngquant) {
            $output = $this->quantize($output);
        }

        [$afterWidth, $afterHeight] = getimagesizefromstring($output) ?: [null, null];

        return new CompressionResult(
            CompressionResult::OK,
            $before,
            strlen($output),
            $width,
            $height,
            $afterWidth,
            $afterHeight,
            ImageDensity::fromBytes(substr($bytes, 0, 131072)),
            ImageDensity::fromBytes(substr($output, 0, 131072)),
            ColorProfile::embedded($bytes) && ! ColorProfile::embedded($output),
            bytes: $output,
        );
    }

    /**
     * Intervention v4 renamed read() to decodeBinary(). Statamic allows both
     * versions, so whichever this site has is used.
     */
    private function decode(string $bytes)
    {
        return method_exists($this->manager, 'decodeBinary')
            ? $this->manager->decodeBinary($bytes)
            : $this->manager->read($bytes);
    }

    /**
     * An encoder with only the options its version knows: v3.9 has neither
     * `strip` nor, for WebP, `progressive`, and passes metadata through.
     */
    private static function encoder(string $class, array $options): object
    {
        $known = array_map(fn ($parameter) => $parameter->getName(), (new \ReflectionMethod($class, '__construct'))->getParameters());

        return new $class(...array_intersect_key($options, array_flip($known)));
    }

    /**
     * pngquant keeps the pHYs chunk, so the DPI set above survives. When it
     * can't reach the quality range, or would make the file larger, the
     * lossless version stays.
     */
    private function quantize(string $png): string
    {
        $process = new Process([$this->pngquant, '--quality='.$this->pngQuality, '--skip-if-larger', '-']);
        $process->setInput($png);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (Throwable) {
            return $png;
        }

        if ($process->isSuccessful() && $process->getOutput() !== '') {
            return $process->getOutput();
        }

        if (! in_array($process->getExitCode(), self::PNGQUANT_SKIPPED, true)) {
            report(new \RuntimeException('pngquant failed: '.trim($process->getErrorOutput())));
        }

        return $png;
    }

    /**
     * A rough estimate of the memory compressing needs. Every driver holds the
     * file and the result as strings in PHP. GD also decodes into PHP's memory:
     * the source, the scaled copy and the copy the encoder blends onto, at
     * about 5 bytes a pixel. Other drivers decode outside the memory limit.
     */
    public function fitsInMemory(?int $width, ?int $height, int $fileBytes): bool
    {
        $limit = self::memoryLimit();

        if ($limit === null) {
            return true;
        }

        $needed = $fileBytes * 2;

        if ($width && $height && ImageManagers::countsAgainstMemoryLimit($this->manager)) {
            $scale = min(1, $this->maxDimension / max($width, $height, 1));
            $target = (int) ($width * $scale) * (int) ($height * $scale);
            $needed += ($width * $height + 2 * $target) * 5;
        }

        return $needed < $limit - memory_get_usage();
    }

    /** In bytes, or null when unlimited. */
    public static function memoryLimit(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
