<?php

namespace KeyAgency\AssetUsage\Compression;

use Illuminate\Contracts\Filesystem\Filesystem;
use Statamic\Assets\ReplacementFile;

/**
 * Lets `Asset::reupload()` take a file from an absolute path. Statamic's own
 * ReplacementFile reads from the uploads disk, but going through reupload() is
 * what we want: it writes to the same path, regenerates the meta and fires
 * AssetReuploaded, which clears Glide and feeds the git integration.
 */
class PathReplacementFile extends ReplacementFile
{
    public function writeTo(Filesystem $disk, $path)
    {
        $stream = fopen($this->path(), 'rb');

        try {
            $disk->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
