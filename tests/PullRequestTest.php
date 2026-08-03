<?php

use Symfony\Component\Process\Process;

/**
 * `create --pr`, end to end through the real binary (#59): real git, a real
 * registry, real locks — and a fake `gh` on `PATH`, because the developer's
 * login, network and forge must not decide what a case asserts.
 *
 * The fake checks the pull request out with git, exactly as the real one ends
 * up doing, so what these cases assert is the state of a real repository rather
 * than the argv of a stub: which branch the worktree is on, which commit that
 * branch carries, and what the registry says about both.
 */
beforeEach(function () {
    harness('worktree-pull-request');

    $this->main = mainCheckout($this->root.'/desk');
    $this->worktree = $this->root.'/desk-worktrees/441-fix-login';
    $this->base = freePortBase(100);

    // The pull request's head, as a clone that has fetched it holds it: a real
    // branch carrying a commit `main` does not, so a worktree that forked from
    // the base rather than checking the head out is caught by SHA and not only
    // by name.
    runGit($this->main, 'checkout', '--quiet', '-b', 'fix-login');
    file_put_contents($this->main.'/head-of-441.md', "the branch the pull request was opened from\n");
    runGit($this->main, 'add', 'head-of-441.md');
    runGit($this->main, 'commit', '--quiet', '-m', 'the pull request');
    runGit($this->main, 'checkout', '--quiet', 'main');

    configureRepository(['steps' => [countingStep()]]);
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('checks the head branch out, names the worktree as a numeric slug, and bootstraps it like any other', function () {
    configureRepository([
        'compose' => ['port_overrides' => ['laravel.test' => ['{{port.vite}}:5173']]],
        'steps' => [countingStep()],
    ]);

    stubPullRequestGh(['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login'], checkout: 'fix-login');

    $process = createPullRequest();

    expect($process)->toHaveSucceeded()
        // The path, alone, on stdout — the contract is the same one every
        // other create holds to.
        ->and($process->getOutput())->toBe($this->worktree."\n")
        // Named from the number and the title, exactly as `create 441` names an
        // issue: the same key, the same slug, the same directory.
        ->and(registered('wt-desk-441-fix-login'))->toMatchArray([
            'slot' => 0,
            'repo' => $this->main,
            'slug' => '441-fix-login',
            'branch' => 'fix-login',
            'path' => $this->worktree,
        ])
        // On the pull request's own branch, tracking it rather than forked from
        // it: the head's commit is here, and the base's is not what HEAD is.
        ->and(branchOf($this->worktree))->toBe('fix-login')
        ->and(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->main, 'fix-login'))
        ->and(gitRevision($this->worktree, 'HEAD'))->not->toBe(gitRevision($this->main, 'main'))
        ->and($this->worktree.'/head-of-441.md')->toBeFile()
        // And everything else a create does, unchanged: a slot, its ports, the
        // `.env`, the overlay, the recipe and the marker.
        ->and(file_get_contents($this->worktree.'/.env'))
        ->toContain('APP_PORT='.$this->base)
        ->toContain('COMPOSE_PROJECT_NAME=wt-desk-441-fix-login')
        ->and($this->worktree.'/compose.worktree.yaml')->toBeFile()
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1)
        ->and($this->worktree.'/.worktree-ready')->toBeFile()
        ->and(ghCalls())->toBe(['pr view 441 --json number,title,headRefName', 'pr checkout 441']);
});

/**
 * The other half of naming it as a numeric slug: everything that looks a
 * worktree up by number finds it, without being told which kind of thing 441
 * was — and `path` does it without `gh`, offline, as it does for an issue.
 */
it('is found afterwards by the number alone', function () {
    stubPullRequestGh(['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login'], checkout: 'fix-login');

    createPullRequest();

    $found = worktreePath(['441'], cwd: $this->main, env: ['PATH' => pathWithoutGh()]);

    expect($found)->toHaveSucceeded()
        ->and($found->getOutput())->toBe($this->worktree."\n");
});

/**
 * A fork's head is checked out under a name gh decides — `main` from somebody's
 * fork cannot be `main` here — so the branch is read back off the worktree
 * rather than predicted, and that is what the registry has to say.
 */
it('records the branch a fork pull request actually landed on', function () {
    stubPullRequestGh(
        ['number' => 441, 'title' => 'Fix login', 'headRefName' => 'main'],
        checkout: 'octocat/main',
        from: 'fix-login',
    );

    $process = createPullRequest();

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain("pull request 441 is checked out on 'octocat/main' rather than 'main'")
        // Asserted after the attachment, like every other create: the worktree
        // is on a branch, and the registry names that one.
        ->and(branchOf($this->worktree))->toBe('octocat/main')
        ->and(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->main, 'fix-login'))
        ->and(registered('wt-desk-441-fix-login'))->toMatchArray(['branch' => 'octocat/main'])
        ->and($this->worktree.'/.worktree-ready')->toBeFile();
});

it('refuses to leave a worktree on a detached HEAD when the checkout did not take', function () {
    // gh answering successfully without moving HEAD is the shape of a checkout
    // that half-worked; the worktree it was going into is on nothing.
    stubPullRequestGh(['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login'], checkout: null, checkoutExitCode: 0);

    $process = createPullRequest();

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('is on no branch')
        ->toContain('refusing to continue')
        // And taken off again, so the next run is an ordinary create rather
        // than a second meeting with the same refusal.
        ->and($this->worktree)->not->toBeDirectory();
});

/**
 * `gh` is optional everywhere else in this package and is not optional here:
 * the branch a pull request was opened from is a fact only the forge holds, so
 * this flag alone fails, and says which tool it needed.
 */
it('fails with the requirement named when gh is not on PATH, having created nothing', function () {
    $process = createPullRequest(path: pathWithoutGh());

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('gh could not answer for pull request 441')
        ->toContain('needs the GitHub CLI')
        ->toContain("run 'gh auth login'")
        // Nothing claimed, nothing attached: the refusal is before all of it.
        ->and($this->home.'/registry.json')->not->toBeFile()
        ->and($this->worktree)->not->toBeDirectory();
});

it('is not put off by a pull request that is closed or merged', function (string $state) {
    // Nothing here asks about state: running the thing that was merged is most
    // of what looking at a pull request afterwards is for.
    stubPullRequestGh(
        ['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login', 'state' => $state],
        checkout: 'fix-login',
    );

    expect(createPullRequest())->toHaveSucceeded()
        ->and(branchOf($this->worktree))->toBe('fix-login');
})->with(['closed' => ['CLOSED'], 'merged' => ['MERGED']]);

it('says which worktree is holding a head branch, rather than leaving git to', function () {
    // git refuses this itself — a branch lives in one working tree at a time —
    // but it refuses partway through a fetch, about a directory nobody named.
    runGit($this->main, 'worktree', 'add', '--quiet', $this->root.'/elsewhere', 'fix-login');

    stubPullRequestGh(['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login'], checkout: 'fix-login');

    $process = createPullRequest();

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("pull request 441 is opened from 'fix-login', and this repository already has that branch checked out at ".$this->root.'/elsewhere')
        ->toContain('git will not put one branch in two worktrees')
        // Refused before gh was asked to check anything out, and before a
        // directory existed to be cleaned up by hand.
        ->and(ghCalls())->toBe(['pr view 441 --json number,title,headRefName'])
        ->and($this->worktree)->not->toBeDirectory();
});

/**
 * The branch belongs to somebody else and it moves. A create that fetched and
 * fast-forwarded on the way in would eventually meet a worktree with local
 * commits in it, and resetting that would be far worse than leaving it stale.
 */
it('re-enters without moving the head branch, or asking gh anything at all', function () {
    stubPullRequestGh(['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login'], checkout: 'fix-login');

    createPullRequest();

    $entered = gitRevision($this->worktree, 'HEAD');

    $again = createPullRequest();

    expect($again)->toHaveSucceeded()
        ->and($again->getOutput())->toBe($this->worktree."\n")
        ->and($again->getErrorOutput())->toContain('is ready; re-entering it')
        ->and(gitRevision($this->worktree, 'HEAD'))->toBe($entered)
        ->and(branchOf($this->worktree))->toBe('fix-login')
        // One lookup for the name, and no second checkout: nothing was fetched,
        // merged or reset under the worktree.
        ->and(ghCalls())->toBe([
            'pr view 441 --json number,title,headRefName',
            'pr checkout 441',
            'pr view 441 --json number,title,headRefName',
        ])
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1);
});

it('refuses to work in a pull request worktree somebody has switched branches in', function () {
    stubPullRequestGh(['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login'], checkout: 'fix-login');

    createPullRequest();

    runGit($this->worktree, 'checkout', '--quiet', '-b', 'something-else');

    $process = createPullRequest();

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("is on 'something-else', expected 'fix-login'")
        ->toContain('commits would land on the wrong branch');
});

it('takes a pull request number, and says so when it is given something else', function () {
    stubPullRequestGh(['number' => 441, 'title' => 'Fix login', 'headRefName' => 'fix-login'], checkout: 'fix-login');

    $process = createPullRequest(['--pr', 'feat/checkout']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())->toContain("'--pr' takes a pull request number, and 'feat/checkout' is not one")
        ->and(ghCalls())->toBe([]);
});

/**
 * A run of `create --pr`, with the stub `gh` on its `PATH`.
 *
 * @param  list<string>  $arguments
 */
function createPullRequest(array $arguments = ['--pr', '441'], ?string $path = null): Process
{
    $process = startWorktree(['create', ...$arguments], env: ['PATH' => $path ?? test()->path]);

    $process->wait();

    return $process;
}

/**
 * A `gh` at the front of `PATH` that answers for one pull request and checks it
 * out with git, which is what the real one does once it has fetched.
 *
 * @param  array<string, mixed>  $view  What `gh pr view --json` answers with.
 * @param  string|null  $checkout  The branch it lands on; null leaves the worktree exactly as it found it.
 * @param  string  $from  The ref that branch is made from, when it is not in the repository already.
 */
function stubPullRequestGh(
    array $view,
    ?string $checkout = null,
    string $from = 'HEAD',
    int $viewExitCode = 0,
    int $checkoutExitCode = 0,
): void {
    $bin = test()->root.'/bin';

    is_dir($bin) || mkdir($bin, 0755, true);

    $json = (string) json_encode($view, JSON_UNESCAPED_SLASHES);
    $log = test()->root.'/gh.log';

    // Whatever the real one had to do to get there, it ends in a branch and a
    // checkout — or, when a case is about a checkout that did not take, in
    // neither.
    $checkedOut = $checkout === null
        ? "printf 'failed to run git\\n' >&2"
        : "if git rev-parse --verify --quiet 'refs/heads/$checkout' > /dev/null; then\n"
          ."        git checkout --quiet '$checkout'\n"
          ."    else\n"
          ."        git checkout --quiet -b '$checkout' '$from'\n"
          .'    fi';

    file_put_contents($bin.'/gh', <<<SH
        #!/bin/sh
        printf '%s\\n' "\$*" >> '$log'

        case "\$1 \$2" in
            'pr view')
                printf '%s\\n' '$json'
                exit $viewExitCode
                ;;
            'pr checkout')
                $checkedOut
                exit $checkoutExitCode
                ;;
        esac

        exit 1
        SH);

    chmod($bin.'/gh', 0755);

    test()->path = $bin.':'.getenv('PATH');
}

/**
 * Everything the stub `gh` was asked, in order.
 *
 * @return list<string>
 */
function ghCalls(): array
{
    return recorded(test()->root.'/gh.log');
}

/**
 * This machine's `PATH`, with every directory carrying a `gh` taken off it.
 *
 * The machine of somebody who never installed the GitHub CLI, rather than one
 * with nothing on its `PATH` at all: the run still needs `git`, and a case that
 * removed everything would be asserting about a failure it caused itself.
 */
function pathWithoutGh(): string
{
    $directories = array_filter(
        explode(':', (string) getenv('PATH')),
        fn (string $directory): bool => $directory !== '' && ! is_executable($directory.'/gh'),
    );

    return implode(':', $directories);
}
