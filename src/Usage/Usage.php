<?php

namespace KeyAgency\AssetUsage\Usage;

/**
 * One place where one asset is used: which item, in which site, and in which
 * field. Stored in the index and handed to the CP as-is.
 */
final class Usage
{
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly ?string $site,
        public readonly string $title,
        public readonly ?string $editUrl,
        public readonly string $field,
    ) {}

    /**
     * Identifies the item this usage belongs to, so an incremental update can
     * drop everything a single item contributed. The site is part of it because
     * a term or a nav tree has one key per site.
     */
    public function itemKey(): string
    {
        return self::makeItemKey($this->type, $this->key, $this->site);
    }

    public static function makeItemKey(string $type, string $key, ?string $site): string
    {
        return $type.'::'.$key.'::'.($site ?? '-');
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'site' => $this->site,
            'title' => $this->title,
            'url' => $this->editUrl,
            'field' => $this->field,
        ];
    }

    public static function fromArray(array $usage): self
    {
        return new self(
            type: $usage['type'],
            key: $usage['key'],
            site: $usage['site'] ?? null,
            title: $usage['title'] ?? '',
            editUrl: $usage['url'] ?? null,
            field: $usage['field'] ?? '',
        );
    }
}
