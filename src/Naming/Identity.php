<?php

namespace DeskHQ\LaravelWorktree\Naming;

/**
 * One worktree's names, all of them derived from a single argument.
 *
 * Everything downstream keys off these: the registry entry, the Compose project
 * and therefore the containers and volumes `reap` scopes by, the directory git
 * attaches, and the branch commits land on. They are computed once, at the top
 * of a run, so no two layers can disagree about what a worktree is called.
 */
final readonly class Identity
{
    public function __construct(
        /** What the user typed: `441`, `feat/checkout`. */
        public string $name,
        /** The slugified identity: `441-fix-login`, `feat-checkout`. */
        public string $slug,
        /** `wt-<repo-slug>-<slug>` — the registry key, and the Compose project name. */
        public string $key,
        /**
         * The branch the worktree is checked out on.
         *
         * Deliberately not the slug for a named worktree: somebody typing
         * `feat/checkout` means that branch, and slashes are legal in refs but
         * not in directory names or Compose project names.
         */
        public string $branch,
        /** The worktree's absolute path. */
        public string $path,
    ) {}
}
