<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * A `mkdir`-based lock, as the bash original made them.
 *
 * `mkdir` is the portable atomic test-and-set: `flock(1)` is absent on macOS,
 * which is where most of this package's runs happen, so the lock is a directory
 * whose creation either wins or does not.
 *
 * A lock is only released by the process that currently holds it — hence
 * {@see $held}. Without that, an explicit mid-flow release followed by the
 * shutdown handler firing would `rmdir` a lock another process had since
 * acquired, and two runs would be inside the same critical section believing
 * they were alone.
 */
final class Lock
{
    /**
     * How long to wait between attempts.
     */
    private const int Interval = 100_000;

    private bool $held = false;

    public function __construct(
        private readonly string $path,
        /** How many times to try before giving up; one attempt per 100ms. */
        private readonly int $attempts,
        /** What to tell the user when the wait runs out. */
        private readonly string $contended,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    /**
     * Take the lock, waiting for whoever has it.
     *
     * Already holding it is not an error: `create` takes its worktree lock on
     * the way in and the allocator takes it again, and neither should have to
     * know about the other.
     *
     * @throws WorktreeException when the wait runs out.
     */
    public function acquire(): void
    {
        if ($this->held) {
            return;
        }

        $parent = dirname($this->path);

        if (! is_dir($parent) && ! @mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new WorktreeException("could not create the lock directory $parent");
        }

        for ($attempt = 0; $attempt < $this->attempts; $attempt++) {
            if (@mkdir($this->path, 0755)) {
                $this->held = true;

                return;
            }

            usleep(self::Interval);
        }

        throw new WorktreeException($this->contended);
    }

    /**
     * Give it up, if this process has it. Safe to call any number of times,
     * which is what lets the shutdown handler call it unconditionally.
     */
    public function release(): void
    {
        if (! $this->held) {
            return;
        }

        $this->held = false;

        @rmdir($this->path);
    }

    /**
     * Run $work holding the lock, and give it back however $work ends.
     *
     * A lock this process already held is left held: whoever took it decides
     * when it goes.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function hold(callable $work): mixed
    {
        $alreadyHeld = $this->held;

        $this->acquire();

        try {
            return $work();
        } finally {
            if (! $alreadyHeld) {
                $this->release();
            }
        }
    }
}
