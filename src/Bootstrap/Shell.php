<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

use DeskHQ\LaravelWorktree\Exceptions\TimedOutException;

/**
 * Where a step's command line actually goes.
 *
 * One method, so the pipeline can be driven against something that runs
 * nothing: ordering, skipping, sentinels and degradation are all decisions this
 * package makes, and a test that has to start Docker to observe them is a test
 * nobody runs. {@see ProcessShell} is the implementation the binary uses.
 */
interface Shell
{
    /**
     * Run $commandLine with $path as its working directory, and hand back its
     * exit code. Everything it writes belongs in the run's diagnostics.
     *
     * $timeout is declared rather than defaulted, because a shell with no
     * opinion about how long a step may run for is how a hung `npm ci` came to
     * hold a worktree's lock indefinitely. `null` still means "no ceiling" —
     * but it has to be said.
     *
     * @param  int|null  $timeout  Seconds the step may run for; null is no ceiling.
     *
     * @throws TimedOutException when it ran past $timeout, having been killed.
     */
    public function run(string $commandLine, string $path, ?int $timeout): int;
}
