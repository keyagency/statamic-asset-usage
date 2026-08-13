<?php

namespace KeyAgency\AssetUsage\Usage;

/**
 * A reference to one asset: the container it lives in and its path within that
 * container. `container::path` is also Statamic's own asset id, so `id()` is
 * what we key the index on and what `Asset::find()` accepts.
 */
final class Reference
{
    public function __construct(
        public readonly string $container,
        public readonly string $path,
    ) {}

    public static function make(string $container, string $path): self
    {
        return new self($container, ltrim($path, '/'));
    }

    /**
     * Split an `container::path` asset id back into a reference. Paths may
     * contain `::` themselves, so only the first separator counts.
     */
    public static function parse(string $id): ?self
    {
        $parts = explode('::', $id, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return new self($parts[0], $parts[1]);
    }

    public function id(): string
    {
        return "{$this->container}::{$this->path}";
    }

    public function basename(): string
    {
        return basename($this->path);
    }
}
