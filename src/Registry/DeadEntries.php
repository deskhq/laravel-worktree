<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Console\CreateCommand;

/**
 * The entries this machine is holding slots for that have nothing behind them.
 *
 * One scan, three callers, deliberately — the same discipline the orphan scan
 * is under: `list` marks what it finds, `reap` reclaims it, and the allocator
 * points at `reap` when it runs out of slots with some of them held this way.
 * A column that says an entry is dead and a sweep that then declines to touch
 * it is a column nobody trusts twice.
 *
 * ## What counts as gone
 *
 * The directory the entry names is not a directory. That is the whole test, and
 * the restraint in it is deliberate:
 *
 * - **A path that exists but is no longer a git worktree keeps its slot.**
 *   There is something on disk there, possibly somebody's uncommitted work, and
 *   reclaiming tears the project down. `worktree remove <slug>` is the answer
 *   for it, and already works on a directory that was never a worktree at all.
 * - **An unmounted network volume or a sleeping external disk makes a live
 *   worktree look deleted.** Nothing here acts on its own answer: `list` marks
 *   the row, and `reap` shows every path it is about to act on and waits for a
 *   confirmation. That is the reason this is a sweep behind a gate rather than
 *   anything automatic during an unrelated command.
 *
 * `create` reaches the same conclusion for the one key it was given and repairs
 * it in place ({@see CreateCommand}); this is what sweeps the rest, which
 * nothing did before (#53).
 */
final readonly class DeadEntries
{
    /** What `list` shows for an entry whose worktree is gone. */
    public const string Gone = 'gone';

    /** ...and for one that is where it says it is. */
    public const string Live = 'ok';

    public function __construct(private Registry $registry) {}

    public static function for(Registry $registry): self
    {
        return new self($registry);
    }

    /**
     * Whether nothing is behind $entry any more.
     */
    public static function isDead(Entry $entry): bool
    {
        return ! is_dir($entry->path);
    }

    /**
     * The token `list` prints for $entry, from the same test the sweep makes.
     */
    public static function status(Entry $entry): string
    {
        return self::isDead($entry) ? self::Gone : self::Live;
    }

    /**
     * Every dead entry a run from the checkout at $repo may reclaim, in slot
     * order — or every one on the machine when $repo is null, which is what
     * `--all` asks for.
     *
     * A scoped run still gets the entries whose repository is gone, because no
     * scoped run could ever get them otherwise ({@see DeadEntry::$repoIsGone}).
     *
     * @return list<DeadEntry>
     */
    public function of(?string $repo): array
    {
        $dead = [];

        foreach ($this->registry->all() as $entry) {
            if (! self::isDead($entry)) {
                continue;
            }

            $candidate = new DeadEntry($entry, ! is_dir($entry->repo));

            if ($repo === null || $candidate->isReclaimableFrom(rtrim($repo, '/'))) {
                $dead[] = $candidate;
            }
        }

        return $dead;
    }
}
