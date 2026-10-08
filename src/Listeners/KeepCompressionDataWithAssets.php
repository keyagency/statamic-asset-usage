<?php

namespace KeyAgency\AssetUsage\Listeners;

use Illuminate\Events\Dispatcher;
use KeyAgency\AssetUsage\Compression\AnalysisStore;
use KeyAgency\AssetUsage\Compression\Backups;
use KeyAgency\AssetUsage\Export\Thumbnails;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetSaved;
use Throwable;

/**
 * Keeps a compressed image's original with its file: gone when the asset is
 * deleted, and moved along when it is renamed or moved. Otherwise a file
 * uploaded later at the same path would find an unrelated original waiting.
 *
 * The analysis moves along too. A deleted asset's analysis is left for the
 * next full run to drop, rather than rewriting the store once per file in a
 * bulk delete.
 *
 * The thumbnails kept for the PDF go in both cases: the id they are filed
 * under no longer exists, and a moved file gets a new one when it is needed.
 */
class KeepCompressionDataWithAssets
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            AssetDeleted::class => 'deleted',
            AssetSaved::class => 'saved',
        ];
    }

    public function deleted(AssetDeleted $event): void
    {
        $this->quietly(fn () => (new Backups)->delete($event->asset));
        $this->quietly(fn () => (new Thumbnails)->delete($event->asset->id()));
    }

    /** A rename or a move saves the asset under its new path. */
    public function saved(AssetSaved $event): void
    {
        $asset = $event->asset;
        $original = $asset->getOriginal('path');

        if (! $original || $original === $asset->path()) {
            return;
        }

        $container = $asset->container()->handle();

        $this->quietly(fn () => (new Backups)->move($container, $original, $asset->path()));
        $this->quietly(fn () => (new AnalysisStore)->move("{$container}::{$original}", $asset->id()));
        $this->quietly(fn () => (new Thumbnails)->delete("{$container}::{$original}"));
    }

    /** Housekeeping never stands in the way of the delete or save itself. */
    private function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
