<?php

namespace KeyAgency\AssetUsage\Log;

/**
 * Where a deletion happened, for the log. The addon's own delete paths say so
 * explicitly; anything else is told apart by the request it happens in.
 */
final class DeletionSource
{
    /** The Tools page of this addon. */
    public const TOOLS = 'tools';

    /** `asset-usage:unused --delete`. */
    public const CLI = 'cli';

    /** Anywhere else in the Control Panel, such as Statamic's asset browser. */
    public const CP = 'cp';

    /** Another command or a queued job. */
    public const CONSOLE = 'console';

    /** Outside the CP, such as a front-end form or an API. */
    public const OTHER = 'other';

    private static ?string $current = null;

    public static function during(string $source, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $source;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public static function current(): string
    {
        if (self::$current) {
            return self::$current;
        }

        if ($route = request()->route()) {
            return str_starts_with((string) $route->getName(), 'statamic.cp.') ? self::CP : self::OTHER;
        }

        return app()->runningInConsole() ? self::CONSOLE : self::OTHER;
    }
}
