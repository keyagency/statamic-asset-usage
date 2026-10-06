<?php

namespace KeyAgency\AssetUsage\Listeners;

use Illuminate\Events\Dispatcher;
use KeyAgency\AssetUsage\Log\AssetLog;
use KeyAgency\AssetUsage\Log\DeletionSource;
use KeyAgency\AssetUsage\Usage\Containers;
use KeyAgency\AssetUsage\Usage\IndexStore;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetDeleting;
use Statamic\Facades\User;
use Throwable;

/**
 * Logs every deleted asset in the enabled containers, wherever it is deleted.
 * The details are read on AssetDeleting, while the file and its meta still
 * exist, and written on AssetDeleted, so a deletion another listener cancels
 * never shows up.
 */
class LogAssetDeletion
{
    /** @var array<string, array> captured per asset id, until the deletion goes through */
    private static array $pending = [];

    public function subscribe(Dispatcher $events): array
    {
        return [
            AssetDeleting::class => 'capture',
            AssetDeleted::class => 'record',
        ];
    }

    /** Returns nothing on purpose: false would cancel the deletion. */
    public function capture(AssetDeleting $event): void
    {
        self::quietly(fn () => $this->remember($event->asset));
    }

    /**
     * By now the file is gone, so a failure to log (a lock timeout, a full
     * disk) is reported instead of turning a finished delete into an error,
     * and stopping the rest of a bulk delete with it.
     */
    public function record(AssetDeleted $event): void
    {
        self::quietly(fn () => $this->write($event->asset));
    }

    private function remember($asset): void
    {
        if (! Containers::includes($asset->container()->handle())) {
            return;
        }

        $store = new IndexStore;

        self::$pending[$asset->id()] = [
            'bytes' => self::read(fn () => $asset->size()),
            'width' => $asset->isImage() ? self::read(fn () => $asset->width()) : null,
            'height' => $asset->isImage() ? self::read(fn () => $asset->height()) : null,
            'usage_count' => $store->isStale() ? null : $store->indexOrEmpty()->countFor($asset->id()),
        ];
    }

    private function write($asset): void
    {
        if (! Containers::includes($asset->container()->handle())) {
            return;
        }

        $details = self::$pending[$asset->id()] ?? [];
        unset(self::$pending[$asset->id()]);

        (new AssetLog)->recordDeletion(
            $asset->id(),
            $asset->container()->handle(),
            $asset->path(),
            $details,
            DeletionSource::current(),
            User::current(),
        );
    }

    private static function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A missing detail never stands in the way of the deletion itself. */
    private static function read(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
