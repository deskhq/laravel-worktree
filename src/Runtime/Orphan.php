<?php

namespace DeskHQ\LaravelWorktree\Runtime;

use DeskHQ\LaravelWorktree\Console\Manifest;

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

    /**
     * The list under the heading, indented and aligned:
     *
     * ```
     *   wt-the-desk-441-fix-login  4 containers, 3 volumes
     *   wt-the-desk-feat-checkout  0 containers, 3 volumes
     * ```
     *
     * Built here rather than by either caller, so what `list` warns about and
     * what `reap` asks permission to destroy are the same lines — a manifest
     * that reads differently from the warning that sent you to it is a manifest
     * somebody has to reconcile by eye before answering `y`. The layout is
     * {@see Manifest}'s, shared with the dead registry entries `reap` reports
     * beneath these.
     *
     * @param  list<self>  $orphans
     * @return list<string>
     */
    public static function manifest(array $orphans): array
    {
        $items = [];

        foreach ($orphans as $orphan) {
            $items[$orphan->project] = $orphan->describe();
        }

        return Manifest::lines($items);
    }

    private static function count(int $total, string $noun): string
    {
        return $total.' '.$noun.($total === 1 ? '' : 's');
    }
}
