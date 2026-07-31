<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Git\Excludes;

/**
 * The whole worktree's sentinel: one file that says a bootstrap has finished
 * here.
 *
 * Per-step sentinels answer "has this step been done?". This answers the
 * different question `create` asks on the way in — "is there anything left to
 * do at all?" — and it is what separates re-entering a worktree from resuming
 * one. Without it, the only way to know would be to run the recipe and let
 * every step decide for itself, which means booting the container first, which
 * is precisely the round-trip `cd "$(worktree create 441)"` should not pay for
 * a worktree that has been ready since Tuesday.
 *
 * It is written *after* the steps, never before: a marker written ahead of the
 * work says a half-built worktree is finished, and the next run would believe
 * it.
 */
final readonly class Readiness
{
    /** The marker, in the worktree's own root. */
    public const string File = '.worktree-ready';

    public function __construct(
        private Output $output,
        private Excludes $excludes,
    ) {}

    /**
     * Whether the worktree at $path has been through a complete bootstrap.
     */
    public function isReady(string $path): bool
    {
        return is_file($path.'/'.self::File);
    }

    /**
     * Record that it has.
     *
     * Never fatal. A marker that could not be written costs the next run a
     * bootstrap it did not need — every step of which is guarded by a sentinel
     * or a condition of its own — and losing the worktree over the file that
     * says the worktree is finished would be the worse trade.
     */
    public function mark(string $path): void
    {
        if (@touch($path.'/'.self::File) === false) {
            $this->output->line(
                'warning: could not write '.self::File." in $path; "
                .'the next create will bootstrap this worktree again rather than re-entering it'
            );

            return;
        }

        $this->excludes->ensure($path, self::File);
    }
}
