<?php

namespace KeyAgency\AssetUsage\Compression;

/**
 * What compressing one image produced, or why it couldn't be done. The
 * compressed bytes are only carried along in memory; `toArray()` is what gets
 * stored.
 */
final class CompressionResult
{
    public const OK = 'ok';

    /** Decoding would not fit in PHP's memory limit (GD only). */
    public const TOO_LARGE = 'too_large';

    /** The format or the driver can't handle this image. */
    public const UNSUPPORTED = 'unsupported';

    public const ERROR = 'error';

    /**
     * The file is the one this addon made. Saving it again would shave off
     * another percent or so each time while losing a little quality, so it is
     * not offered again, whatever the settings.
     */
    public const COMPRESSED = 'compressed';

    public function __construct(
        public readonly string $status,
        public readonly int $beforeBytes,
        public readonly ?int $afterBytes = null,
        public readonly ?int $beforeWidth = null,
        public readonly ?int $beforeHeight = null,
        public readonly ?int $afterWidth = null,
        public readonly ?int $afterHeight = null,
        public readonly ?int $beforeDpi = null,
        public readonly ?int $afterDpi = null,
        public readonly bool $iccLost = false,
        public readonly ?string $reason = null,
        public readonly ?string $bytes = null,
    ) {}

    public static function failed(string $status, int $beforeBytes, string $reason, ?int $width = null, ?int $height = null): self
    {
        return new self($status, $beforeBytes, beforeWidth: $width, beforeHeight: $height, reason: $reason);
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    /** Percentage saved; negative when the file would grow. */
    public function savings(): ?float
    {
        if (! $this->ok() || $this->beforeBytes === 0) {
            return null;
        }

        return round(($this->beforeBytes - $this->afterBytes) / $this->beforeBytes * 100, 1);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'before_bytes' => $this->beforeBytes,
            'after_bytes' => $this->afterBytes,
            'before_width' => $this->beforeWidth,
            'before_height' => $this->beforeHeight,
            'after_width' => $this->afterWidth,
            'after_height' => $this->afterHeight,
            'before_dpi' => $this->beforeDpi,
            'after_dpi' => $this->afterDpi,
            'savings' => $this->savings(),
            'icc_lost' => $this->iccLost,
        ];
    }
}
