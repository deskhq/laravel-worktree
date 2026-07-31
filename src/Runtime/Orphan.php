<?php

namespace DeskHQ\LaravelWorktree\Runtime;

/**
 * One Compose project this package created and no longer accounts for.
 *
 * A worktree torn down by hand, a teardown interrupted partway, or a registry
 * file somebody lost: whichever it was, what is left is containers and volumes
 * carrying a `wt-` project label that no registry entry claims. `list` names
 * them on stderr and `reap` destroys them, and both say the same sentence about
 * each one because both build it from here.
 */
final readonly class Orphan
{
    public function __construct(
        /** The Compose project name — `wt-<repo-slug>-<slug>`. */
        public string $project,
        /** How many containers still carry its label. */
        public int $containers,
        /** How many volumes still carry its label. */
        public int $volumes,
    ) {}

    /**
     * What it is holding, in the words a person deciding whether to reap needs:
     * `4 containers, 3 volumes`.
     */
    public function describe(): string
    {
        return self::count($this->containers, 'container').', '.self::count($this->volumes, 'volume');
    }

    private static function count(int $total, string $noun): string
    {
        return $total.' '.$noun.($total === 1 ? '' : 's');
    }
}
