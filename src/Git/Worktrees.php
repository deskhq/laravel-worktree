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
 * A pull request is the third way in and the one that is not a fork of
 * anything — its head branch is checked out as it stands, through gh, because a
 * head that lives in somebody's fork is not a ref this clone can name
 * ({@see attachPullRequest()}).
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

        $on = $this->headOf($path);

        if ($on !== $branch) {
            throw new WorktreeException(
                "worktree $path is on '".($on === '' ? '<unknown>' : $on)."', expected '$branch' — "
                .'refusing to continue, commits would land on the wrong branch'
            );
        }
    }

    /**
     * Ensure $path is a worktree checked out on the head of pull request
     * $number, and hand back the branch it actually landed on.
     *
     * The head is **checked out, not branched from**: a review wants the
     * author's branch, tracking whatever it tracks, so that pushing a fix back
     * is an ordinary push rather than a cherry-pick (#59). $headRef is what
     * GitHub calls that branch, and it is used for the one thing that can be
     * known in advance — that git will refuse a branch already checked out in
     * another worktree, which deserves a message from this package rather than
     * from git.
     *
     * The checkout itself is `gh pr checkout`, run inside the new worktree,
     * because a pull request from a **fork** has a head in a repository this
     * clone may have no remote for and gh already knows how to fetch it. What
     * gh does not do is use $headRef when it would collide with the default
     * branch — a fork's `main` is checked out as `<owner>/main` — so the branch
     * is *read back* rather than assumed, and that is what the caller records.
     *
     * The last two lines are {@see attach()}'s, for the same reason: whatever
     * happened, the worktree is on a branch, or the run stops here.
     *
     * @return string The branch the worktree is on.
     *
     * @throws WorktreeException when the worktree cannot be created or checked out, or ends up on no branch at all.
     */
    public function attachPullRequest(string $path, string $number, string $headRef): string
    {
        $made = ! is_dir($path);

        if ($made) {
            $this->checkOut($path, $number, $headRef);
        }

        $on = $this->headOf($path);

        // Detached is the shape a checkout that half-worked leaves behind, and
        // it is the one state this cannot carry on from: every name downstream
        // — the entry's branch, `list`, `remove`'s reassurance that the work
        // survives the directory — assumes a branch is holding the commits.
        if ($on === '' || $on === 'HEAD') {
            // Taken off again when this run is what made it, so the next one is
            // an ordinary create rather than a meeting with the same refusal.
            if ($made) {
                $this->detach($path);
            }

            throw new WorktreeException(
                "worktree $path is on no branch (git says '".($on === '' ? '<unknown>' : $on)."') after checking out "
                ."pull request $number — refusing to continue, work done in there would be on nothing but a detached HEAD"
            );
        }

        return $on;
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
     * The directory for a pull request: made detached at this checkout's own
     * `HEAD`, and then handed to gh to put the pull request in.
     *
     * Detached deliberately. `git worktree add` puts the directory on
     * *something*, and every branch it could be given here is one that commits
     * would land on if the checkout below failed and nobody read the screen —
     * so it is given none, for the few seconds until gh replaces it.
     *
     * And a gh that fails leaves no directory behind: a worktree on a detached
     * `HEAD` is the one state {@see attachPullRequest()} cannot resume from, so
     * the next run makes it again rather than meeting it. The registry entry
     * survives, exactly as it does after any other interrupted bootstrap.
     */
    private function checkOut(string $path, string $number, string $headRef): void
    {
        $this->prune($path);
        $this->refuseBranchInUse($number, $headRef);

        $this->output->line("creating a worktree at $path for pull request $number, on its head branch $headRef");

        $this->add(['worktree', 'add', '--detach', $path], $path);

        $exitCode = $this->runner->run(['gh', 'pr', 'checkout', $number], $path);

        if ($exitCode === 0) {
            return;
        }

        $this->detach($path);

        throw new WorktreeException(
            "gh could not check pull request $number out into $path (it exited $exitCode); "
            .'the empty worktree it was going into has been taken off the repository again'
        );
    }

    /**
     * Refuse a head branch this repository already has checked out somewhere.
     *
     * git refuses it as well — a branch lives in one working tree at a time —
     * but it refuses partway through a fetch, in its own words, about a
     * directory nobody named. Refusing here happens before anything is created
     * and says which worktree is holding the branch.
     *
     * The default branch is the one name this must *not* refuse, and the reason
     * is the same rename that makes the branch unpredictable: a pull request
     * opened from a fork's `main` has `main` as its head ref, which this
     * repository has checked out and always will — so gh checks it out as
     * `<owner>/main` instead, and there is no collision to report. A pull
     * request from *this* repository can never have the default branch as its
     * head, so nothing real is skipped.
     */
    private function refuseBranchInUse(string $number, string $headRef): void
    {
        if ($headRef === $this->baseRefs->defaultBranch()) {
            return;
        }

        $holder = $this->worktreeOn($headRef);

        if ($holder === null) {
            return;
        }

        throw new WorktreeException(
            "pull request $number is opened from '$headRef', and this repository already has that branch checked out "
            ."at $holder — git will not put one branch in two worktrees; work in that one, or take it away first"
        );
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
        foreach ($this->records() as $lines) {
            if (in_array('worktree '.$path, $lines, true)) {
                return $lines;
            }
        }

        return null;
    }

    /**
     * The worktree of this repository that is on $branch, or null when none is.
     *
     * The main checkout counts, and has to: it is a working tree like any
     * other, and a branch checked out there is just as unavailable to a new
     * worktree as one held by a sibling.
     */
    private function worktreeOn(string $branch): ?string
    {
        foreach ($this->records() as $lines) {
            if (! in_array('branch refs/heads/'.$branch, $lines, true)) {
                continue;
            }

            foreach ($lines as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    return substr($line, strlen('worktree '));
                }
            }
        }

        return null;
    }

    /**
     * Every record `git worktree list --porcelain` prints, each as its own
     * lines — the one question git answers about all of its working trees at
     * once, asked once and read two ways.
     *
     * @return list<list<string>>
     */
    private function records(): array
    {
        $result = $this->runner->capture(['git', 'worktree', 'list', '--porcelain'], $this->anchor->mainRoot);

        if (! $result->succeeded()) {
            return [];
        }

        return array_map(
            fn (string $record): array => array_map(trim(...), explode("\n", trim($record))),
            explode("\n\n", trim($result->output)),
        );
    }

    /**
     * The branch $path is on: `HEAD` when it is on none, and `''` when git
     * could not be asked at all.
     */
    private function headOf(string $path): string
    {
        $head = $this->runner->capture(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $path);

        return $head->succeeded() ? $head->trimmedOutput() : '';
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
