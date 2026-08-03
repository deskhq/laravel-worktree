<?php

namespace DeskHQ\LaravelWorktree\Exceptions;

use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * A subprocess that ran past the limit its caller gave it, and was killed.
 *
 * Thrown by {@see ProcessRunner} and caught by whoever asked for the limit,
 * because only they can say what timed out in terms the reader has: the
 * pipeline names the step, the runtime names the worktree it could not start.
 * It is a {@see WorktreeException} so that a caller which does *not* catch it
 * still fails the way every other operational failure does — with a message,
 * rather than a stack trace.
 */
final class TimedOutException extends WorktreeException
{
    public function __construct(
        /** The limit it ran past, in seconds. */
        public readonly int $seconds,
        /** What was running, as the runner was given it. */
        public readonly string $commandLine,
    ) {
        parent::__construct("'$commandLine' was still running after {$seconds}s, and was killed");
    }
}
