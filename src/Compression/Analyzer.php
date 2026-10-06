<?php

namespace KeyAgency\AssetUsage\Compression;

use KeyAgency\AssetUsage\Usage\Containers;
use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * Test-compresses assets and records what it would save. The compressed bytes
 * are thrown away; only the numbers are stored.
 */
class Analyzer
{
    public function __construct(
        private readonly Compressor $compressor,
        private readonly AnalysisStore $store,
        private readonly Backups $backups = new Backups,
    ) {}

    public static function make(): self
    {
        return new self(Compressor::make(), new AnalysisStore, new Backups);
    }

    public function compressor(): Compressor
    {
        return $this->compressor;
    }

    public function store(): AnalysisStore
    {
        return $this->store;
    }

    /**
     * Identifies the file as it is now. Size and modification time change on
     * every replace, including ours.
     */
    public static function version(Asset $asset): string
    {
        return $asset->size().'-'.$asset->lastModified()->timestamp;
    }

    public static function applies(Asset $asset): bool
    {
        return Compressor::supports($asset->extension())
            && Containers::includes($asset->container()->handle());
    }

    public function fresh(Asset $asset): ?array
    {
        return $this->store->fresh($asset->id(), self::version($asset), $this->compressor->fingerprint());
    }

    /** Enough of a file for its dimensions; the header sits at the very start. */
    private const HEADER_BYTES = 262144;

    public function compress(Asset $asset): CompressionResult
    {
        if (! self::applies($asset)) {
            return CompressionResult::failed(CompressionResult::UNSUPPORTED, (int) $asset->size(), 'Not an image this addon compresses.');
        }

        /*
         * Checked before the whole file is read, because reading something too
         * big for the memory limit is a fatal error nothing can catch.
         */
        [$width, $height] = $this->dimensions($asset);

        if (! $this->compressor->fitsInMemory($width, $height, (int) $asset->size())) {
            return CompressionResult::failed(CompressionResult::TOO_LARGE, (int) $asset->size(), 'Not enough memory to compress this image.', $width, $height);
        }

        if ($this->backups->producedCurrentFile($asset, self::version($asset), $this->compressor->fingerprint())) {
            return CompressionResult::failed(CompressionResult::COMPRESSED, (int) $asset->size(), 'Already compressed with the current settings.');
        }

        try {
            $bytes = (string) $asset->contents();
        } catch (Throwable $e) {
            return CompressionResult::failed(CompressionResult::ERROR, 0, $e->getMessage());
        }

        return $this->compressor->compress($bytes, $asset->extension());
    }

    /** @return array{0: ?int, 1: ?int} */
    private function dimensions(Asset $asset): array
    {
        try {
            $stream = $asset->stream();
            $header = (string) stream_get_contents($stream, self::HEADER_BYTES);
            fclose($stream);
        } catch (Throwable) {
            return [null, null];
        }

        $info = @getimagesizefromstring($header);

        return $info ? [$info[0], $info[1]] : [null, null];
    }

    public function record(Asset $asset, CompressionResult $result): array
    {
        return $result->toArray() + [
            'version' => self::version($asset),
            'settings' => $this->compressor->fingerprint(),
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Analyse and store in one write. Assets that already have a current record
     * are skipped unless forced.
     *
     * @param  iterable<Asset>  $assets
     * @param  callable|null  $progress  called after each asset, skipped ones included
     */
    public function analyze(iterable $assets, bool $force = false, ?string $batch = null, ?callable $progress = null): int
    {
        $records = [];

        foreach ($assets as $asset) {
            if (self::applies($asset) && ($force || ! $this->fresh($asset))) {
                $records[$asset->id()] = $this->record($asset, $this->compress($asset));
            }

            $progress && $progress($asset);
        }

        if ($records || $batch !== null) {
            $this->store->store($records, $batch);
        }

        return count($records);
    }
}
