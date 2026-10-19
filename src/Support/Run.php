<?php

namespace KeyAgency\AssetUsage\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The run of a long task (the index build, the compression analysis): which
 * one is current, how far it got and when it last showed a sign of life. Kept
 * in a small file of its own, so reporting progress never rewrites the index
 * or the analysis, and a cache clear during a deploy doesn't lose it.
 *
 * A run that stops being current (stopped from the CP, or replaced by a newer
 * one) notices on its next beat and ends without writing its results.
 */
final class Run
{
    /** Without a sign of life for this long, a run is taken to have died. */
    public const STALL_MINUTES = 15;

    /**
     * A run that beat this recently is busy, so a stop waits for it to save
     * what it has. A stopping run that stays quiet this long has ended.
     */
    public const ACTIVE_SECONDS = 30;

    /** How much of an error message the CP shows; the full one is in the log and in the terminal. */
    public const MESSAGE_LENGTH = 300;

    /** How often a run writes its progress at most. */
    private const BEAT_SECONDS = 1;

    private float $lastBeat = 0;

    public function __construct(private readonly string $name) {}

    public static function index(): self
    {
        return new self('index');
    }

    public static function analysis(): self
    {
        return new self('analysis');
    }

    public function path(): string
    {
        return storage_path("statamic/asset-usage/{$this->name}-run.json");
    }

    /** Read fresh every time: the run is told to stop through this file. */
    public function data(): array
    {
        $decoded = File::exists($this->path()) ? json_decode((string) File::get($this->path()), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Starts a run, which replaces any other: the one before it stops on its
     * next beat. Forgets the failure of an earlier run.
     *
     * @param  int|null  $total  how many steps the run has, when known
     * @param  bool  $estimated  whether that total is a guess
     */
    public function start(?int $total = null, bool $estimated = false): string
    {
        $id = (string) Str::uuid();

        $this->locked(fn () => $this->put([
            'id' => $id,
            'queued_at' => now()->toIso8601String(),
            'beat_at' => null,
            'done' => 0,
            'total' => $total,
            'estimated' => $estimated,
        ]));

        return $id;
    }

    public function isCurrent(string $id): bool
    {
        return ($this->data()['id'] ?? null) === $id;
    }

    /**
     * Writes how far the run got, at most once a second unless forced.
     *
     * @return bool false once the run is no longer current or is asked to stop, which tells it to stop
     */
    public function beat(string $id, ?int $done = null, ?int $total = null, bool $force = false): bool
    {
        if (! $force && microtime(true) - $this->lastBeat < self::BEAT_SECONDS) {
            return true;
        }

        $this->lastBeat = microtime(true);

        return $this->update($id, fn (array $data) => array_merge($data, array_filter([
            'done' => $done,
            'total' => $total,
        ], fn ($value) => $value !== null)));
    }

    /**
     * Adds to the count, for a run that several workers share.
     *
     * @return bool false once the run is no longer current
     */
    public function advance(string $id, int $by): bool
    {
        return $this->update($id, fn (array $data) => array_merge($data, ['done' => ($data['done'] ?? 0) + $by]));
    }

    /** The run is done; nothing to report anymore. Without an id, whichever run is current. */
    public function finish(?string $id = null): void
    {
        $this->locked(function () use ($id) {
            if ($id === null || $this->isCurrent($id)) {
                File::delete($this->path());
            }
        });
    }

    /**
     * Stops the current run. A busy one is asked to: it notices on its next
     * beat, saves what it has and ends itself, so the page reloads only once
     * that is done. One that isn't busy (still queued, or quiet) ends here.
     *
     * @return bool whether it ended here, in which case the caller puts the state back
     */
    public function stop(): bool
    {
        $ended = true;

        $this->locked(function () use (&$ended) {
            $data = $this->data();
            $beat = $data['beat_at'] ?? null;

            if (isset($data['id']) && $beat !== null && Carbon::parse($beat)->gt(now()->subSeconds(self::ACTIVE_SECONDS))) {
                $ended = false;
                $this->put(array_merge($data, ['stopping_at' => now()->toIso8601String()]));

                return;
            }

            File::delete($this->path());
        });

        return $ended;
    }

    /**
     * Whether a run is over: replaced by a newer one, or asked to stop. One
     * that was asked puts the state back and ends here, after it has saved
     * what it did so far.
     */
    public function isOver(string $id, callable $putBack): bool
    {
        if ($this->isStopping($id)) {
            $putBack();
            $this->finish($id);

            return true;
        }

        return ! $this->isCurrent($id);
    }

    /** Asked to stop, which the run itself still has to act on. */
    public function isStopping(string $id): bool
    {
        $data = $this->data();

        return ($data['id'] ?? null) === $id && isset($data['stopping_at']);
    }

    /**
     * Ends the run and keeps why, so the CP can say so. Shortened: an error
     * from a database or a storage service can carry SQL and server paths,
     * and the start says what went wrong.
     */
    public function fail(string $id, ?string $message = null): void
    {
        $message = $message === null ? null : Str::limit($message, self::MESSAGE_LENGTH);

        $this->locked(function () use ($id, $message) {
            if ($this->isCurrent($id)) {
                $this->put(['failure' => ['reason' => 'error', 'message' => $message, 'at' => now()->toIso8601String()]]);
            }
        });
    }

    /** A current run that showed a sign of life recently enough, or was queued recently enough. */
    public function isRunning(): bool
    {
        $data = $this->data();

        return isset($data['id']) && ! $this->stalled($data);
    }

    /**
     * How far the current run is: done and total (null while unknown), and
     * whether the total is a guess. Null without a running run.
     */
    public function progress(): ?array
    {
        if (! $this->isRunning()) {
            return null;
        }

        $data = $this->data();

        return [
            'done' => (int) ($data['done'] ?? 0),
            'total' => isset($data['total']) ? (int) $data['total'] : null,
            'estimated' => (bool) ($data['estimated'] ?? false),
            'stopping' => isset($data['stopping_at']),
        ];
    }

    /**
     * Why the last run didn't finish: 'error' with the message when it had
     * one, 'not_started' when no worker picked it up, 'stalled' when it went
     * quiet halfway. Null when nothing went wrong.
     *
     * @param  bool  $flagged  whether the store still says it is running, which without a run
     *                         means it was left behind by a run that died, or by an older version
     */
    public function failure(bool $flagged = false): ?array
    {
        $data = $this->data();

        if (isset($data['failure'])) {
            return $data['failure'];
        }

        if (! isset($data['id'])) {
            return $flagged ? ['reason' => 'stalled', 'message' => null, 'at' => null] : null;
        }

        // A run that was asked to stop and went quiet did what it was asked.
        if (isset($data['stopping_at']) || ! $this->stalled($data)) {
            return null;
        }

        return [
            'reason' => $data['beat_at'] === null ? 'not_started' : 'stalled',
            'message' => null,
            'at' => $data['beat_at'] ?? $data['queued_at'],
        ];
    }

    private function stalled(array $data): bool
    {
        if (isset($data['stopping_at'])) {
            return Carbon::parse($data['stopping_at'])->lt(now()->subSeconds(self::ACTIVE_SECONDS));
        }

        $last = $data['beat_at'] ?? $data['queued_at'] ?? null;

        return $last === null || Carbon::parse($last)->lt(now()->subMinutes(self::STALL_MINUTES));
    }

    /** @return bool whether the run was still current and not asked to stop */
    private function update(string $id, callable $change): bool
    {
        $current = false;

        $this->locked(function () use ($id, $change, &$current) {
            $data = $this->data();

            if (($data['id'] ?? null) !== $id || isset($data['stopping_at'])) {
                return;
            }

            $current = true;

            $this->put(array_merge($change($this->data()), ['beat_at' => now()->toIso8601String()]));
        });

        return $current;
    }

    /** Via a temp file and a rename, so a reader never sees half a document. */
    private function put(array $data): void
    {
        File::ensureDirectoryExists(dirname($this->path()));

        $temp = $this->path().'.'.bin2hex(random_bytes(4));

        File::put($temp, json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        File::move($temp, $this->path());
    }

    private function locked(callable $callback): void
    {
        Cache::lock("asset-usage-run-{$this->name}", 10)->block(5, $callback);
    }
}
