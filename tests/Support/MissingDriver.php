<?php

namespace KeyAgency\AssetUsage\Tests\Support;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\MissingDependencyException;

/** Behaves like a driver whose PHP extension is not installed. */
class MissingDriver extends Driver
{
    public function checkHealth(): void
    {
        throw new MissingDependencyException('Fake extension must be installed to use this driver');
    }
}
