<?php

namespace KeyAgency\AssetUsage\Listeners;

use KeyAgency\AssetUsage\Compression\Analyzer;
use KeyAgency\AssetUsage\Compression\Requirements;
use KeyAgency\AssetUsage\Jobs\AnalyzeCompression;
use KeyAgency\AssetUsage\Support\Settings;
use Statamic\Events\AssetReuploaded;
use Statamic\Events\AssetUploaded;

/**
 * New and replaced images are analysed right away (on the queue), so the Tools
 * page only needs a manual run for what was there before.
 */
class AnalyzeUploadedImage
{
    public function handle(AssetUploaded|AssetReuploaded $event): void
    {
        $asset = $event->asset;

        if (! Settings::compressionEnabled() || ! Analyzer::applies($asset) || ! Requirements::available()) {
            return;
        }

        /*
         * Without a real queue the analysis would run inside the upload request:
         * the upload would wait for the decoding, and a failure in it would fail
         * the upload. After the response it costs the uploader nothing.
         */
        if (config('queue.default') === 'sync') {
            AnalyzeCompression::dispatchAfterResponse([$asset->id()]);

            return;
        }

        AnalyzeCompression::dispatch([$asset->id()]);
    }
}
