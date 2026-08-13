<?php

namespace KeyAgency\AssetUsage\Usage;

/**
 * A scannable piece of content, flattened to the handful of things the index
 * needs: how to identify it, how to link to it, and its data.
 */
final class Item
{
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly ?string $site,
        public readonly string $title,
        public readonly ?string $editUrl,
        public readonly array $data,
    ) {}

    public function itemKey(): string
    {
        return Usage::makeItemKey($this->type, $this->key, $this->site);
    }

    public function usage(string $field): Usage
    {
        return new Usage(
            type: $this->type,
            key: $this->key,
            site: $this->site,
            title: $this->title,
            editUrl: $this->editUrl,
            field: $field,
        );
    }
}
