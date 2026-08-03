<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Support\Machine;

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
 *
 * ## The directory records who took it
 *
 * The shutdown handler covers `SIGINT` and `SIGTERM`, which is the common way a
 * run ends early and is the right design. It cannot cover `SIGKILL`, the OOM
 * killer, a panic, or a laptop that slept and was rebooted mid-bootstrap — and
 * each of those used to leave a directory owned by nothing, which the next run
 * waited the full ten minutes for before failing with a message that handed the
 * user a `rm -rf` into this package's own state directory.
 *
 * So the winner writes an {@see Owner} record immediately after the `mkdir`, and
 * a run that finds the directory already there judges it:
 *
 * | What the record says | What happens |
 * | --- | --- |
 * | the holder is running | waited on, exactly as before |
 * | the holder is provably gone | broken and taken, with a line saying so |
 * | there is no record, or it cannot be read | waited on, exactly as before |
 * | the holder is another machine's | waited on, exactly as before |
 *
 * The last two rows are the ones worth defending. A lock written by an earlier
 * version of this package has no record, and the safe reading of *I cannot tell
 * who holds this* is not *nobody does*: getting it wrong in that direction puts
 * two runs inside the same critical section in the same directory, which is the
 * failure the lock exists to prevent, while getting it wrong the other way costs
 * a wait — which is what happened anyway. `worktree unlock` is the way out of
 * both.
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
        private readonly Output $output,
        private readonly Machine $machine,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function exists(): bool
    {
        return is_dir($this->path);
    }

    /**
     * Who holds this lock, as the directory records it — null when it records
     * nobody, and null when there is no lock at all.
     */
    public function owner(): ?Owner
    {
        return Owner::read($this->path);
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
            if ($this->take()) {
                return;
            }

            // Once, on the first failure, and never again: who has it, whether
            // they are still there, and — when they are not — that this run is
            // taking it from them. Ten minutes of silence is indistinguishable
            // from a hang to the agent driving this non-interactively.
            if ($attempt === 0) {
                $this->confront();

                continue;
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

        @unlink($this->path.'/'.Owner::File);
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

    /**
     * Remove a lock this process does not hold, provided it is still the one
     * that was judged.
     *
     * Nothing inside this package calls it with a live holder — the acquire path
     * only reaches it for a lock whose owner is provably gone, and `worktree
     * unlock` is where a person overrides that deliberately.
     *
     * The `rename` is what makes it safe to call at all. Two runs that both
     * judged the same lock dead would otherwise both `rmdir` it, and the second
     * would be removing the *first one's fresh lock*; a rename takes the
     * directory out of play in a single operation, so exactly one of them wins
     * and the loser finds nothing to remove. What it moved is then checked
     * against what was judged, and put back if somebody claimed the path in
     * between.
     *
     * @param  Owner|null  $expected  The record the decision was made on, or null for a lock that recorded nobody.
     */
    public function breakOpen(?Owner $expected): bool
    {
        if (! is_dir($this->path)) {
            return false;
        }

        $aside = $this->path.'.'.bin2hex(random_bytes(6)).'.stale';

        if (! @rename($this->path, $aside)) {
            return false;
        }

        $moved = Owner::read($aside);

        if ($expected === null ? $moved !== null : ($moved === null || ! $moved->is($expected))) {
            // Somebody took the lock between the record being read and the
            // directory being moved, so what is aside is their live lock rather
            // than the dead one. Put it back and wait like everyone else.
            if (! @rename($aside, $this->path)) {
                // Only reachable if a *third* run created the lock in the
                // instant between the two renames. Said rather than swallowed:
                // the run whose lock was moved still believes it holds this
                // path, which is the one state this file exists to make
                // impossible, and nothing else on screen would say so.
                $this->output->error(
                    "a live lock was moved out of $this->path while a stale one was being broken, and could not be "
                    .'put back; whatever is working on this worktree is no longer holding the lock it thinks it is — '
                    ."let it finish, then remove $aside"
                );
            }

            return false;
        }

        @unlink($aside.'/'.Owner::File);
        @rmdir($aside);

        return true;
    }

    /**
     * Take it, and say so inside it.
     *
     * The record is written after `$held` is set, so a signal arriving between
     * the two still releases the directory: a lock held with no record is a lock
     * the next run waits for, and a directory nothing releases is the bug this
     * whole file is about.
     */
    private function take(): bool
    {
        if (! @mkdir($this->path, 0755)) {
            return false;
        }

        $this->held = true;

        Owner::thisRun($this->machine)?->writeInto($this->path);

        return true;
    }

    /**
     * Look at whoever has it, and either take it from them or say what is being
     * waited for.
     */
    private function confront(): void
    {
        $owner = $this->owner();

        if ($owner === null) {
            $this->output->line(
                "waiting for the lock at $this->path, which records no holder — a lock left by an older version of "
                ."this package reads this way, and so does one being taken this instant; 'worktree unlock' removes it"
            );

            return;
        }

        $liveness = $owner->liveness($this->machine);

        if ($liveness === Liveness::Gone && $this->breakOpen($owner)) {
            $this->output->line(
                "the lock at $this->path was taken by ".$owner->describe()
                .', which is not running any more; breaking it and taking it'
            );

            return;
        }

        $this->output->line(
            "waiting for the lock at $this->path, taken by ".$owner->describe()
            .($liveness === Liveness::Unknown
                ? ' — another machine, so nothing here can tell whether it is still running'
                : '')
        );
    }
}
