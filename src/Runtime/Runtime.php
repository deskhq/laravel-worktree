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
 * Five methods, where D3 named three. {@see self::shell()} is the first extra:
 * what a `sail:` step has to be told before it will behave — `SAIL_SKIP_CHECKS`,
 * and why — is the runtime's knowledge, not the pipeline's, and handing the
 * pipeline a shell is what keeps it out of there. A runtime with no containers
 * hands back a plain host shell and nothing else changes.
 *
 * {@see self::stop()} is the second, and it is the half of the pair
 * {@see self::teardown()} is not: teardown is how a worktree stops existing,
 * stop is how it stops *costing* anything. Between them is the state the
 * package had no word for — the idle worktree whose memory somebody wants back
 * and whose branch they intend to work on tomorrow (#56).
 */
interface Runtime
{
    /**
     * Make the worktree runnable: whatever it takes to get from an attached
     * directory to something the bootstrap steps can run against.
     *
     * $halted and $remedy are the two clauses a failure ends with, and they
     * exist because the same boot is asked for by two commands with different
     * answers to "what now?". A bootstrap that could not bring the app service
     * up is resumed by `create`; a stopped worktree that would not come back is
     * retried by `start`, and telling somebody to run `create` there would send
     * them to a command that re-enters a ready worktree without booting it.
     *
     * @param  string  $environmentFile  The worktree's own `.env`, which is where the runtime's settings live.
     * @param  string|null  $halted  What did not finish, in the words of the command that asked; null is a bootstrap.
     * @param  string|null  $remedy  What picks it up once the failure is fixed; null is `worktree create <name>`.
     *
     * @throws WorktreeException when the worktree cannot be brought up.
     */
    public function boot(Identity $worktree, string $environmentFile, ?string $halted = null, ?string $remedy = null): void;

    /**
     * Stop a project's containers, and destroy nothing.
     *
     * The counterpart to {@see boot()}, and deliberately not a small
     * {@see teardown()}: the volumes stay, so the database survives; the
     * containers stay, so coming back is a start rather than a build. What the
     * caller has already decided is that this worktree is idle, not finished.
     *
     * A project name rather than an {@see Identity}, for {@see teardown()}'s
     * reason: a bulk stop works from registry entries, which carry a key and a
     * path and no branch anybody needs here. $directory is the worktree when
     * there still is one, and is only ever an optimisation.
     *
     * Never throws. False is "nothing was stopped", said on the run's
     * diagnostics as it happened, and the caller turns it into an exit code.
     */
    public function stop(string $project, ?string $directory = null): bool;

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
