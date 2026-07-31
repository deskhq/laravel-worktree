<?php

namespace DeskHQ\LaravelWorktree\Runtime;

use DeskHQ\LaravelWorktree\Bootstrap\Shell;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Identity;

/**
 * What brings a worktree up, and what takes it down again (D3).
 *
 * {@see SailRuntime} is the only implementation there is, and the seam exists
 * so that the second one can be a class rather than a fork. Without containers
 * there are no host ports to collide over, nothing to tear down and nothing to
 * reap — so a non-Sail mode is a genuinely different product, and half-building
 * it inside the Sail one would leave both worse. It is deferred behind this
 * interface instead.
 *
 * Four methods, where D3 named three. The fourth is {@see self::shell()}: what a
 * `sail:` step has to be told before it will behave — `SAIL_SKIP_CHECKS`, and
 * why — is the runtime's knowledge, not the pipeline's, and handing the
 * pipeline a shell is what keeps it out of there. A runtime with no containers
 * hands back a plain host shell and nothing else changes.
 */
interface Runtime
{
    /**
     * Make the worktree runnable: whatever it takes to get from an attached
     * directory to something the bootstrap steps can run against.
     *
     * @param  string  $environmentFile  The worktree's own `.env`, which is where the runtime's settings live.
     *
     * @throws WorktreeException when the worktree cannot be brought up.
     */
    public function boot(Identity $worktree, string $environmentFile): void;

    /**
     * Take a project off this machine, and report what is left.
     *
     * A project name rather than an {@see Identity}, because `reap` has nothing
     * else: an orphan is a label on a daemon, and the worktree it belonged to
     * may have no entry, no branch and no directory left anywhere. $directory is
     * the worktree when there still is one, and is only ever an optimisation —
     * see {@see SailRuntime::teardown()} for what Compose does with it.
     *
     * Never throws over a resource it could not remove: the survivors are the
     * answer, and the caller — `remove`, `reap` — is what turns them into an
     * exit code.
     */
    public function teardown(string $project, ?string $directory = null): TeardownResult;

    /**
     * Whether worktrees under this runtime need a slot's worth of host ports.
     *
     * The question every caller asks before it reaches for the allocator: with
     * nothing published on the host there is nothing to offset, and a registry
     * of slots for it would be bookkeeping about nothing.
     */
    public function allocatesPorts(): bool;

    /**
     * The shell the bootstrap steps run through.
     */
    public function shell(): Shell;
}
