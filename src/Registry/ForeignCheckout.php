<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * The refusal every command makes when the key it was given is somebody else's.
 *
 * A key is a Compose project name, and Compose project names are what
 * containers and volumes are scoped by — so two checkouts holding the same one
 * would not merely share a registry entry, they would share containers. That is
 * what a second clone of a repository looks like when `repo_slug` was left to
 * default to the directory name and both clones have the same one.
 *
 * Five commands used to say that, in five copies of one sentence whose first
 * and last clauses were identical and whose middle was not: `start` would bring
 * up that checkout's containers, `stop` would stop them, `remove` would tear
 * them down, `path` would hand back a directory this repository does not own.
 * The middle clause is the real variation, so it is the parameter, and the
 * sentence around it is written here (#73).
 *
 * ```php
 * ForeignCheckout::because('starting it from here would bring up that checkout\'s containers')
 *     ->refuse($entry, $anchor->mainRoot);
 * ```
 */
final readonly class ForeignCheckout
{
    private function __construct(
        /** What acting on this entry from here would do to the other checkout. */
        private string $consequence,
        /** Where to do it instead. */
        private string $remedy,
    ) {}

    /**
     * The refusal a command makes, in that command's own words for what it was
     * about to do.
     *
     * $remedy is the one clause that is not quite the same everywhere: `path`
     * says *run this from there*, because what is in the wrong place is the
     * command being typed rather than anything it would have destroyed.
     */
    public static function because(string $consequence, string $remedy = 'run it from there'): self
    {
        return new self($consequence, $remedy);
    }

    /**
     * @throws WorktreeException when $entry belongs to a checkout other than $repo.
     */
    public function refuse(Entry $entry, string $repo): void
    {
        if ($entry->belongsTo($repo)) {
            return;
        }

        throw new WorktreeException(
            "'$entry->key' is registered to $entry->repo, not to ".rtrim($repo, '/').'; '
            .$this->consequence.' — '.$this->remedy.', '
            ."or set 'repo_slug' in ".Schema::File.' to tell the two apart'
        );
    }

    /**
     * The same collision, refused on the way *in* rather than on the way out.
     *
     * Deliberately not the sentence above. Everything else here is refusing to
     * act on a worktree that exists somewhere else, and names the place to go
     * and do it; this one is refusing to *make* a second worktree under a name
     * that is already taken, and there is nowhere to send anybody — the answer
     * is to stop the two checkouts colliding at all ({@see Allocator}).
     *
     * @throws WorktreeException when $entry belongs to a checkout other than $repo.
     */
    public static function refuseClaim(Entry $entry, string $repo): void
    {
        if ($entry->belongsTo($repo)) {
            return;
        }

        throw new WorktreeException(
            "'$entry->key' is already registered to $entry->repo, not ".rtrim($repo, '/').'; '
            ."two checkouts cannot share a project name — set 'repo_slug' in ".Schema::File.' to tell them apart'
        );
    }
}
