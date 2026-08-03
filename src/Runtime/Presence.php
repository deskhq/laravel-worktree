<?php

namespace DeskHQ\LaravelWorktree\Runtime;

/**
 * What one Compose project has on this daemon, and how much of it is up.
 *
 * The census {@see Docker::containersByProject()} answers with, per project.
 * Two numbers rather than one, because the questions in front of somebody with
 * five worktrees open are not the same question:
 *
 * - **none at all** is a project that was never booted, or one a teardown
 *   finished with — either way there is nothing of it on the machine;
 * - **some of them up** is a boot that stopped partway, or a service that died
 *   under its siblings, which reads identically to healthy in a count alone;
 * - **all of them up** is the only one of the three that means what people
 *   assume a row in the table means.
 *
 * Counted rather than named: what the table says is which state a worktree is
 * in, and the container ids behind that are `docker ps`'s business. `reap` asks
 * for the ids, by project, when it is about to remove them.
 */
final readonly class Presence
{
    public function __construct(
        /** How many containers carry this project's label, exited ones included. */
        public int $containers,
        /** How many of those are up. */
        public int $running,
    ) {}

    /**
     * A project the daemon has never heard of — which is a different answer from
     * a daemon that could not be asked ({@see Orphans::containers()}).
     */
    public static function none(): self
    {
        return new self(0, 0);
    }

    /**
     * The same census with one more container counted into it.
     */
    public function with(bool $running): self
    {
        return new self($this->containers + 1, $this->running + ($running ? 1 : 0));
    }
}
