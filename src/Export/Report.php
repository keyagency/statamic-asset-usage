<?php

namespace KeyAgency\AssetUsage\Export;

/** Everything the PDF shows, worked out up front so the view only lays it out. */
final class Report
{
    /**
     * @param  array<int, array{label: string, value: string}>  $filters  only the ones that narrow the list down
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $createdAt,
        public readonly array $filters,
        public readonly string $sort,
        public readonly int $total,
        public readonly string $totalSize,
        public readonly array $rows,
        public readonly bool $showsContainer,
        public readonly bool $showsSavings,
        public readonly string $emptyText,
        public readonly string $overviewUrl,
        public readonly string $filename,
        /** How many rows the whole PDF lists, over all its parts. */
        public readonly int $listed = 0,
        /** A later part of the PDF, which goes on with the table and has no header. */
        public readonly bool $continued = false,
    ) {}

    /** More assets match than the PDF lists. */
    public function truncated(): bool
    {
        return $this->total > $this->listed;
    }
}
