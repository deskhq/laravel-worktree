<?php

namespace DeskHQ\LaravelWorktree\Git;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * Putting a directory on a branch, and proving that it is.
 *
 * Creation is the easy half: attach the branch when it already exists, and fork
 * it from a base {@see BaseRefs} has already made unambiguous when it does not.
 *
 * The half that matters is the last three lines. Whatever git was asked to do,
 * `HEAD` is re-read in the new worktree and the run is abandoned unless it is
 * the expected branch. That is the backstop for any future DWIM — the-desk#619
 * was exactly this failure, and it was invisible until commits had already
 * landed on the release branch — and it costs one `rev-parse`. It runs on
 * re-entry too, so a worktree someone switched branches in by hand is caught
 * before a bootstrap step touches it.
 */
final readonly class Worktrees
{
    public function __construct(
        private ProcessRunner $runner,
        private Output $output,
        private Anchor $anchor,
        private BaseRefs $baseRefs,
    ) {}

    /**
     * Ensure $path is a worktree on $branch, forked from $base when the branch
     * is new, and refuse to continue unless it really is on that branch.
     *
     * A null $base means the repository's own default branch. An existing
     * $path is left exactly as it is — this is how re-entering a worktree
     * resumes rather than rebuilds — but it is verified like a fresh one.
     *
     * @throws WorktreeException when the worktree cannot be created, or is not on $branch.
     */
    public function attach(string $path, string $branch, ?string $base = null): void
    {
        if (! is_dir($path)) {
            $this->create($path, $branch, $base);
        }

        $head = $this->runner->capture(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $path);
        $on = $head->succeeded() ? $head->trimmedOutput() : '';

        if ($on !== $branch) {
            throw new WorktreeException(
                "worktree $path is on '".($on === '' ? '<unknown>' : $on)."', expected '$branch' — "
                .'refusing to continue, commits would land on the wrong branch'
            );
        }
    }

    private function create(string $path, string $branch, ?string $base): void
    {
        if ($this->hasBranch($branch)) {
            $this->output->line("attaching existing branch $branch");

            $this->add(['worktree', 'add', $path, $branch], $path);

            return;
        }

        $ref = $this->baseRefs->resolve($base);

        $this->output->line("creating branch $branch from $ref");

        $this->add(['worktree', 'add', '-b', $branch, $path, $ref], $path);
    }

    private function hasBranch(string $branch): bool
    {
        return $this->runner
            ->capture(['git', 'show-ref', '--verify', '--quiet', "refs/heads/$branch"], $this->anchor->mainRoot)
            ->succeeded();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function add(array $arguments, string $path): void
    {
        $exitCode = $this->runner->run(['git', ...$arguments], $this->anchor->mainRoot);

        if ($exitCode !== 0) {
            throw new WorktreeException("could not create the worktree at $path (git exited $exitCode)");
        }
    }
}
