<?php

namespace KeyAgency\AssetUsage\Tests\Support;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\FileExtension;
use Intervention\Image\Format;
use Intervention\Image\MediaType;

/** GD as some servers have it: built without WebP. */
class NoWebpDriver extends Driver
{
    public function supports(string|Format|FileExtension|MediaType $identifier): bool
    {
        return Format::tryCreate($identifier) !== Format::WEBP && parent::supports($identifier);
    }
}
