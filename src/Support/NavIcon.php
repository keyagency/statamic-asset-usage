<?php

namespace KeyAgency\AssetUsage\Support;

use Illuminate\Support\Facades\File;

final class NavIcon
{
    private static ?string $svg = null;

    public static function svg(): string
    {
        return self::$svg ??= File::get(__DIR__.'/../../resources/svg/nav-icon.svg');
    }
}
