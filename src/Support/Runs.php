<?php

namespace KeyAgency\AssetUsage\Support;

/** What the index build and the compression analysis share when they are started. */
final class Runs
{
    /**
     * Without a queue worker the job would run inside the request that starts
     * it: the button would wait for the whole run, and the page could neither
     * show progress nor stop it. After the response it runs in the same PHP
     * process, but the page has its answer and checks the progress meanwhile.
     */
    public static function dispatch(object $job): void
    {
        if (config('queue.default') === 'sync') {
            // Without PHP-FPM the browser waits for the run, and leaving the page must not end it.
            ignore_user_abort(true);

            dispatch($job)->afterResponse();

            return;
        }

        dispatch($job);
    }

    /**
     * Run after the response, the job is still held to the request's time
     * limit, so it gets the one Statamic gives Glide (none by default). A queue
     * worker has its own and is left alone.
     */
    public static function raiseTimeLimit(): void
    {
        if (! app()->runningInConsole()) {
            @set_time_limit((int) config('statamic.system.php_max_execution_time'));
        }
    }
}
