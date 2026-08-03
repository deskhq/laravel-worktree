<?php

namespace DeskHQ\LaravelWorktree\Naming;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * The one place in this package where `gh` is a dependency rather than an
 * enrichment (#59).
 *
 * Everywhere else — `create 441`, and the `gh` row of `doctor` — an absent,
 * logged-out or offline `gh` is an ordinary answer and the worktree is named
 * `issue-441` ({@see Issues}). There is no such answer here: a pull request's
 * head branch is a fact only the forge holds, and `create --pr 441` has nothing
 * to check out without it. So this asks loudly — the failure is reported with
 * what `gh` said about it, through {@see ProcessRunner::capture()} rather than
 * the {@see ProcessRunner::consult()} an optional lookup uses — and says which
 * tool the run needed, because a flag that quietly needed a second binary is a
 * flag people meet as a puzzle.
 *
 * The one thing it deliberately does not ask about is **state**. A closed or a
 * merged pull request is a perfectly ordinary thing to want a worktree for —
 * running the thing that was merged is most of what review is — so `gh pr view`
 * is asked for a head ref and a title, and never for permission.
 */
final readonly class PullRequests
{
    public function __construct(
        private ProcessRunner $runner,
        private Output $output,
        private Anchor $anchor,
    ) {}

    /**
     * The pull request numbered $number, as `gh` describes it.
     *
     * @throws WorktreeException when `gh` is not here, cannot answer, or answers without naming a head branch.
     */
    public function view(string $number): PullRequest
    {
        $result = $this->runner->capture(
            ['gh', 'pr', 'view', $number, '--json', 'number,title,headRefName'],
            $this->anchor->mainRoot,
        );

        if (! $result->succeeded()) {
            throw new WorktreeException(
                "gh could not answer for pull request $number (it exited $result->exitCode); "
                ."'create --pr' is the one thing in this package that needs the GitHub CLI, because the branch a "
                .'pull request was opened from is a fact only the forge holds — install gh, run '
                ."'gh auth login', and try again"
            );
        }

        $reported = json_decode($result->trimmedOutput(), true);
        $reported = is_array($reported) ? $reported : [];

        $headRef = $reported['headRefName'] ?? null;

        if (! is_string($headRef) || trim($headRef) === '') {
            throw new WorktreeException(
                "gh answered for pull request $number without naming its head branch, and that branch is the whole "
                ."of what 'create --pr' checks out; check that '$number' is a pull request of this repository"
            );
        }

        return new PullRequest($number, $this->slug($number, $reported['title'] ?? null), trim($headRef));
    }

    /**
     * The worktree's name: the number and the title, exactly as a numeric issue
     * is named, so that everything which looks a worktree up by number keeps
     * working whichever of the two made it.
     *
     * A pull request with no usable title is not the missing-`gh` case — the
     * head ref is in hand and the run goes on — so the number alone names it,
     * which is still a slug the number matches ({@see Identities::locate()}).
     */
    private function slug(string $number, mixed $title): string
    {
        if (! is_string($title) || trim($title) === '') {
            $this->output->line("gh gave pull request $number no title; the worktree will be $number");

            return Slug::of($number);
        }

        return Slug::of($number.'-'.$title);
    }
}
