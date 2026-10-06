<?php

namespace KeyAgency\AssetUsage\Tests\Support;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Interfaces\ColorInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * GD that fails to decode anything, with the exception the test sets. A driver
 * rather than an ImageManager subclass, because the manager is final in
 * Intervention v3. Both versions' decode methods are overridden: v3 decodes
 * through handleInput(), v4 through decodeImage().
 */
class FailingDriver extends Driver
{
    public ?Throwable $failure = null;

    public static function throwing(Throwable $failure): self
    {
        return tap(new self, fn (self $driver) => $driver->failure = $failure);
    }

    public function handleInput(mixed $input, array $decoders = []): ImageInterface|ColorInterface
    {
        throw $this->failure;
    }

    public function decodeImage(mixed $input, ?array $decoders = null): ImageInterface
    {
        throw $this->failure;
    }
}
