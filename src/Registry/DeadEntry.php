<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Console\Manifest;
use DeskHQ\LaravelWorktree\Runtime\Orphan;

/**
 * A registry entry with nothing behind it: the slot is claimed, and the
 * directory the entry names is not there any more.
 *
 * The mirror image of {@see Orphan}: an orphan is a project on the daemon that
 * no entry claims, and this is an entry that claims a worktree the disk has
 * never heard of. Neither is reachable by the other's
 * command, which is why both are swept by `reap` and both are named by `list`.
 *
 * It is made by `rm -rf` on a worktree directory, by `git worktree remove` run
 * directly, by a whole clone being deleted — the registry is machine-global, so
 * those entries outlive the repository — and by a `remove` interrupted between
 * the directory going and the entry being deleted.
 */
final readonly class DeadEntry
{
    public function __construct(
        /** The entry itself, exactly as the registry holds it. */
        public Entry $entry,
        /**
         * Whether the *checkout* this entry hangs off is gone as well.
         *
         * The case a person has the least context for, and the one no
         * repository-scoped run could ever reach: with the clone deleted there
         * is no checkout left to run `reap` from. So these are reported by
         * every run, `--all` or not ({@see DeadEntries::of()}).
         */
        public bool $repoIsGone,
    ) {}

    public function key(): string
    {
        return $this->entry->key;
    }

    /**
     * What it is holding, in the words a person deciding whether to reclaim it
     * needs: `slot 3, /Users/…/the-desk-worktrees/feat-search`.
     *
     * The slot because that is the resource being held, and the path because
     * that is the claim being disbelieved — an unmounted network volume or an
     * external disk makes a live worktree look deleted, and the one defence
     * against acting on that is showing every path before anything is asked.
     */
    public function describe(): string
    {
        return 'slot '.$this->entry->slot.', '.$this->entry->path
            .($this->repoIsGone ? ', whose checkout '.$this->entry->repo.' has gone too' : '');
    }

    /**
     * The list under the heading, indented and aligned exactly as the orphan
     * manifest beside it is — the two are printed by one `reap`, in one
     * summary, and a reader should not have to tell them apart by their layout.
     *
     * @param  list<self>  $dead
     * @return list<string>
     */
    public static function manifest(array $dead): array
    {
        $items = [];

        foreach ($dead as $entry) {
            $items[$entry->key()] = $entry->describe();
        }

        return Manifest::lines($items);
    }

    /**
     * Whether a `reap` run from the checkout at $repo may reclaim this one.
     *
     * Its own repository's, plus every entry whose repository is gone: those
     * belong to nobody, and leaving them to a run from a checkout that no
     * longer exists means leaving them for good.
     */
    public function isReclaimableFrom(string $repo): bool
    {
        return $this->repoIsGone || $this->entry->belongsTo($repo);
    }
}
