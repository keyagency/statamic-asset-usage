<?php

namespace KeyAgency\AssetUsage\Log;

/**
 * Where a deletion or a compression happened, for the log. The addon's own
 * paths say so explicitly; a deletion anywhere else is told apart by the
 * request it happens in. Compressions only happen on the Tools page and in
 * the CLI.
 */
final class Source
{
    /** The Tools page of this addon, and for a compression the before and after page. */
    public const TOOLS = 'tools';

    /** `asset-usage:unused --delete`, or `asset-usage:compress` for a compression. */
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
