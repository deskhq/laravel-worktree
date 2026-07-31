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

    /**
     * Take the worktree at $path off this repository, and leave its branch
     * exactly where it is.
     *
     * That asymmetry is the whole of `remove`: the work is the point and the
     * directory is disposable, so git is asked to remove a working tree and
     * never a ref. `--force`, because a worktree being torn down is usually one
     * somebody stopped working in mid-change, and refusing over an uncommitted
     * file would leave a slot held by a directory nobody is using.
     *
     * A path git will not remove is reported and carried past rather than
     * thrown over. `remove` has to work on a worktree somebody already deleted
     * by hand — git handles that one itself — and on a directory that was never
     * a worktree at all, which is what a run reaching this without a registry
     * entry may well have derived (the-desk#1095). By the time it gets here the
     * containers and volumes are already gone, and failing now would strand the
     * registry entry that names them.
     *
     * The prune is what makes the name usable again: a record left behind for a
     * directory that has gone makes the next `git worktree add` refuse the path
     * as *missing but already registered*.
     */
    public function detach(string $path): void
    {
        $this->output->line("removing the worktree at $path");

        $removal = $this->runner->attempt(['git', 'worktree', 'remove', '--force', $path], $this->anchor->mainRoot);

        if (! $removal->succeeded()) {
            $this->output->line(
                "git would not remove a worktree at $path (exit $removal->exitCode); "
                .'what it said follows, and the rest of the removal carries on'
            );
            $this->output->write(rtrim($removal->output)."\n");
        }

        $this->runner->quiet(['git', 'worktree', 'prune'], $this->anchor->mainRoot);
    }

    /**
     * Whether git records a worktree of this repository at $path.
     *
     * What makes a guess about where a worktree lives safe to act on: two
     * checkouts side by side have sibling worktrees directories that look
     * identical from the filesystem, and git is the only thing that knows which
     * of them is ours.
     */
    public function registers(string $path): bool
    {
        return $this->record($path) !== null;
    }

    private function create(string $path, string $branch, ?string $base): void
    {
        $this->prune($path);

        if ($this->hasBranch($branch)) {
            $this->output->line("attaching existing branch $branch");

            $this->add(['worktree', 'add', $path, $branch], $path);

            return;
        }

        $ref = $this->baseRefs->resolve($base);

        $this->output->line("creating branch $branch from $ref");

        $this->add(['worktree', 'add', '-b', $branch, $path, $ref], $path);
    }

    /**
     * Clear git's own record of a worktree at $path whose directory has gone.
     *
     * Deleting a worktree directory by hand leaves that record behind, and
     * `git worktree add` then refuses the path — "missing but already
     * registered" — which is a run failing on the state a person creates by
     * tidying up. Pruning clears it, and the branch is untouched, so nothing
     * committed in there is lost.
     *
     * Asked of git before it is done, and only for this path, because `prune`
     * itself is repository-wide: a second `create` for a *different* worktree
     * is a few milliseconds inside `git worktree add` where its own record
     * exists and its directory does not, and pruning on every create would
     * eventually land in that window.
     */
    private function prune(string $path): void
    {
        if (! $this->isPrunable($path)) {
            return;
        }

        $this->output->line("git still has a worktree registered at $path, but there is nothing there; clearing that record");

        $this->runner->quiet(['git', 'worktree', 'prune'], $this->anchor->mainRoot);
    }

    /**
     * Whether git holds a record for $path that it considers prunable — the
     * one state this recovers from, named by git itself rather than guessed at
     * from the filesystem.
     */
    private function isPrunable(string $path): bool
    {
        $record = $this->record($path);

        return $record !== null
            && array_filter($record, fn (string $line): bool => str_starts_with($line, 'prunable')) !== [];
    }

    /**
     * What `git worktree list --porcelain` says about $path, line by line, or
     * null when it says nothing about it at all.
     *
     * @return list<string>|null
     */
    private function record(string $path): ?array
    {
        $result = $this->runner->capture(['git', 'worktree', 'list', '--porcelain'], $this->anchor->mainRoot);

        if (! $result->succeeded()) {
            return null;
        }

        foreach (explode("\n\n", trim($result->output)) as $record) {
            $lines = array_map(trim(...), explode("\n", trim($record)));

            if (in_array('worktree '.$path, $lines, true)) {
                return $lines;
            }
        }

        return null;
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
