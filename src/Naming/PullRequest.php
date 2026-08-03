<?php

namespace DeskHQ\LaravelWorktree\Naming;

use DeskHQ\LaravelWorktree\Git\Worktrees;

/**
 * What `create --pr` needs to know about a pull request, and nothing else.
 *
 * Two facts, and they are wanted for different reasons. The **slug** names the
 * worktree, and it is built exactly as a numeric issue's is — the number, and
 * whatever title the tracker folded onto it — so that `path 441`, `remove 441`
 * and the completion all find it without being told which kind of thing 441 was
 * ({@see Identities::locate()}).
 *
 * The **head ref** is the branch the pull request was opened from, and it is
 * what makes this feature different from every other create: the worktree is
 * checked out on a branch that already exists somewhere rather than forked from
 * a base. It is what the checkout is expected to land on — expected rather than
 * guaranteed, because a fork's head is checked out under a name gh decides
 * ({@see Worktrees::attachPullRequest()}).
 */
final readonly class PullRequest
{
    public function __construct(
        /** The number the user typed: `441`. */
        public string $number,
        /** `441-fix-login`, named as a numeric issue is. */
        public string $slug,
        /** The branch the pull request was opened from, as GitHub names it. */
        public string $headRef,
    ) {}
}
