<?php

namespace KeyAgency\AssetUsage\Support;

/**
 * Fixed tuning values for scanning, read directly rather than from config.
 * They are static only so tests can exercise chunking with a handful of items;
 * `reset()` restores the shipped values.
 */
final class Defaults
{
    /** Items loaded per chunk while scanning content. */
    public static int $scanChunkSize = self::SCAN_CHUNK_SIZE;

    /** Usages recorded per asset before we stop collecting for that asset. */
    public static int $maxUsagesPerAsset = self::MAX_USAGES_PER_ASSET;

    private const SCAN_CHUNK_SIZE = 100;

    private const MAX_USAGES_PER_ASSET = 250;

    public static function reset(): void
    {
        self::$scanChunkSize = self::SCAN_CHUNK_SIZE;
        self::$maxUsagesPerAsset = self::MAX_USAGES_PER_ASSET;
    }
}
