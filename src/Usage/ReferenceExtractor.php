<?php

namespace KeyAgency\AssetUsage\Usage;

use KeyAgency\AssetUsage\Support\Settings;

/**
 * Finds every asset reference in one item's data.
 *
 * The walk is deliberately generic rather than blueprint-driven: every format
 * Statamic uses is recognisable from the string itself (`asset::container::path`
 * for Link fields, Bard image nodes and Bard link marks, `statamic://asset::…`
 * inside Markdown and Bard HTML), and the one exception — the bare,
 * container-relative path an `assets` field stores — is resolved against the
 * list of paths that actually exist. That means nav trees and form submissions,
 * which have no useful blueprint, are covered by the same code, and so is any
 * addon fieldtype that stores one of the standard formats.
 */
final class ReferenceExtractor
{
    /**
     * Keys whose values are never content: ids, bookkeeping and the `type` keys
     * that carry set handles and Bard node/mark names.
     */
    private const EXCLUDED_KEYS = [
        'id',
        'blueprint',
        'created_by',
        'updated_by',
        'updated_at',
    ];

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * `asset::{container}::{path}`, optionally prefixed with `statamic://`. The
     * character classes stop at the delimiters these references sit between in
     * markdown links, HTML attributes and JSON.
     */
    private const EXPLICIT_PATTERN = '/(?:statamic:\/\/)?asset::([^:\s"\'()<>]+)::([^\s"\'()<>]+)/';

    /** @var array<string, string[]> assetId => dotted field paths, built per extract() */
    private array $found = [];

    public function __construct(private readonly Containers $containers) {}

    /**
     * @return array<string, string[]> asset id => the dotted field paths it appears in
     */
    public function extract(array $data): array
    {
        $this->found = [];

        $this->walk($data, '');

        return $this->found;
    }

    private function walk(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            if ($this->isExcludedKey($key, $value)) {
                continue;
            }

            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_string($value)) {
                $this->scan($value, $childPath);
            } elseif (is_array($value)) {
                $this->walk($value, $childPath);
            }
        }
    }

    private function scan(string $value, string $path): void
    {
        if ($value === '' || $this->isStructuralValue($value)) {
            return;
        }

        $this->scanExplicit($value, $path);
        $this->scanBarePath($value, $path);

        if (Settings::scansUrls()) {
            $this->scanUrls($value, $path);
        }
    }

    private function scanExplicit(string $value, string $path): void
    {
        if (! str_contains($value, 'asset::')) {
            return;
        }

        preg_match_all(self::EXPLICIT_PATTERN, $value, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $container, $assetPath]) {
            if ($reference = $this->containers->resolveExplicit($container, $assetPath)) {
                $this->record($reference, $path);
            }
        }
    }

    /**
     * An `assets` field stores a plain container-relative path, so the whole
     * value is the reference — anything else in the string means it isn't one.
     */
    private function scanBarePath(string $value, string $path): void
    {
        if (str_contains($value, ' ') || strlen($value) > 1024) {
            return;
        }

        foreach ($this->containers->resolveBarePath($value) as $reference) {
            $this->record($reference, $path);
        }
    }

    private function scanUrls(string $value, string $path): void
    {
        foreach ($this->containers->urlPrefixes() as ['prefix' => $prefix, 'container' => $container]) {
            if (! str_contains($value, $prefix)) {
                continue;
            }

            $pattern = '#'.preg_quote($prefix, '#').'([^\s"\'<>?)\]]+)#';

            preg_match_all($pattern, $value, $matches);

            foreach ($matches[1] as $assetPath) {
                $this->recordUrlPath($container, $assetPath, $path);
            }
        }
    }

    /**
     * A URL that ends a sentence or a markdown link drags punctuation into the
     * match, so fall back to the trimmed version when the raw path is unknown.
     */
    private function recordUrlPath(string $container, string $assetPath, string $path): void
    {
        $reference = $this->containers->resolveExplicit($container, $assetPath)
            ?? $this->containers->resolveExplicit($container, rtrim($assetPath, '.,;:!'));

        if ($reference) {
            $this->record($reference, $path);
        }
    }

    private function record(Reference $reference, string $path): void
    {
        $id = $reference->id();

        if (! in_array($path, $this->found[$id] ?? [], true)) {
            $this->found[$id][] = $path;
        }
    }

    private function isExcludedKey(int|string $key, mixed $value): bool
    {
        return in_array($key, self::EXCLUDED_KEYS, true)
            || ($key === 'type' && is_string($value));
    }

    private function isStructuralValue(string $value): bool
    {
        /*
         * Both patterns start on a hexadecimal digit, so anything else is
         * content. Worth the check: this runs on every string value scanned.
         */
        if (! ctype_xdigit($value[0])) {
            return false;
        }

        return preg_match(self::UUID_PATTERN, $value) === 1
            || preg_match(self::DATE_PATTERN, $value) === 1;
    }
}
