<?php

namespace DeskHQ\LaravelWorktree\Git;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * What a new worktree forks from, resolved to a ref git cannot second-guess.
 *
 * `git worktree add -b <new> <path> <base>` looks safe but **DWIMs**: when
 * `<base>` matches no local branch and exactly one remote-tracking branch, git
 * creates a local `<base>` tracking the remote and checks *that* out, silently
 * dropping `-b`. The worktree ends up on the shared release line and every
 * commit made in it lands there (the-desk#619). Naming the remote-tracking ref
 * explicitly disables the guess, so the base is resolved before git sees it.
 *
 * The remote copy also **wins over** a local branch of the same name: a local
 * `develop` that is merely behind origin forks the worktree from a stale
 * baseline, and the omission surfaces later as a conflict or a missing commit
 * rather than as an error (the-desk#639). The local branch is used only when
 * the base exists on no remote at all.
 *
 * Both failures are silent and data-loss-shaped, which is why ambiguity is
 * refused here rather than guessed: a base carried by two remotes is a question
 * only the caller can answer.
 */
final readonly class BaseRefs
{
    public function __construct(
        private ProcessRunner $runner,
        private Anchor $anchor,
    ) {}

    /**
     * The unambiguous ref $base names, in this order:
     *
     * 1. `HEAD` / `@` — resolved locally and returned as-is.
     * 2. exactly one `refs/remotes/*` copy — that one, freshly fetched.
     * 3. more than one — a refusal naming the candidates.
     * 4. anything `rev-parse` resolves to a commit: a tag, a SHA, an
     *    already-qualified `origin/develop`, or a local-only branch.
     *
     * A null $base means this repository's own default branch, which is what
     * `base_branch` and `WORKTREE_BASE` leave it as when they say nothing.
     *
     * @throws WorktreeException when $base is ambiguous, or names nothing this clone can reach.
     */
    public function resolve(?string $base = null): string
    {
        $base = $base === null || $base === '' ? $this->defaultBranch() : $base;

        // HEAD and @ name the current checkout — but refs/remotes/*/HEAD exists
        // in any clone, so the remote lookup below would silently redirect them
        // to origin/HEAD.
        if ($base === 'HEAD' || $base === '@') {
            if (! $this->isCommit($base)) {
                throw new WorktreeException("base '$base' does not resolve to a commit");
            }

            return $base;
        }

        $this->fetch($base);

        $tracking = $this->remoteTracking($base);

        if (count($tracking) === 1) {
            return $tracking[0];
        }

        if (count($tracking) > 1) {
            throw new WorktreeException(
                "base '$base' is ambiguous — it exists on several remotes (".implode(', ', $tracking).'); '
                .'pass the remote-tracking ref explicitly'
            );
        }

        if (! $this->isCommit($base)) {
            throw new WorktreeException("base '$base' does not exist locally or on any remote (fetch it first?)");
        }

        return $base;
    }

    /**
     * This repository's own default branch — what `base_branch` means when it
     * is left null, in place of the hardcoded `master` of the original.
     *
     * `refs/remotes/origin/HEAD` is what a clone records the upstream's default
     * branch as. A repository without one — no origin, or a clone whose
     * origin/HEAD was never set — falls back to the branch currently checked
     * out, and a detached checkout falls back to `HEAD` itself, which
     * {@see resolve()} understands.
     */
    public function defaultBranch(): string
    {
        $head = $this->git(['symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD']);

        // The short form is `origin/master`; the branch name is what callers
        // hand back to resolve(), so that its remote copy is refreshed and wins
        // over a stale local one exactly as any other base does.
        if ($head !== null && str_starts_with($head, 'origin/')) {
            return substr($head, strlen('origin/'));
        }

        return $this->git(['rev-parse', '--abbrev-ref', 'HEAD']) ?? 'HEAD';
    }

    /**
     * Refresh $base from every remote, so the ref resolution above sees the
     * remote's current tip rather than whatever the last fetch left behind.
     *
     * Failures are **non-fatal**: offline, an unreachable remote, or a remote
     * that simply does not carry this branch are all ordinary, and resolution
     * then falls back to the refs already in the clone. Their output is
     * discarded for the same reason — `couldn't find remote ref epic` is the
     * question being answered, not a diagnostic.
     */
    private function fetch(string $base): void
    {
        foreach ($this->remotes() as $remote) {
            $this->runner->quiet(['git', 'fetch', '--quiet', $remote, $base], $this->anchor->mainRoot);
        }
    }

    /**
     * @return list<string>
     */
    private function remotes(): array
    {
        return $this->lines($this->git(['remote']));
    }

    /**
     * @return list<string> Every `refs/remotes/<remote>/<base>` this clone holds, in short form.
     */
    private function remoteTracking(string $base): array
    {
        return $this->lines($this->git(['for-each-ref', '--format=%(refname:short)', "refs/remotes/*/$base"]));
    }

    private function isCommit(string $ref): bool
    {
        return $this->git(['rev-parse', '--verify', '--quiet', $ref.'^{commit}']) !== null;
    }

    /**
     * The trimmed stdout of a git command run in the main working tree, or null
     * when it failed or answered nothing.
     *
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ?string
    {
        $result = $this->runner->capture(['git', ...$arguments], $this->anchor->mainRoot);

        if (! $result->succeeded() || $result->trimmedOutput() === '') {
            return null;
        }

        return $result->trimmedOutput();
    }

    /**
     * @return list<string>
     */
    private function lines(?string $output): array
    {
        if ($output === null) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode("\n", $output)), fn (string $line) => $line !== ''));
    }
}
