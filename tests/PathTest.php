<?php

use DeskHQ\LaravelWorktree\Registry\Owner;

/**
 * `path`, end to end through the real binary: the read-only way to ask where a
 * worktree is.
 *
 * Everything here is about what this command *does not* do. `create` answers the
 * same question, and the README taught everyone to use it for that — but it
 * takes the per-worktree lock, retries degraded steps, verifies `HEAD`, and
 * bootstraps a whole worktree for a slug it has never seen. A typo in a script
 * should cost an exit code, not five minutes of Docker and a slot.
 *
 * So the cases below run against a registry written by hand, a `gh` that records
 * being called, a lock somebody else is holding, and a `docker` that is not this
 * machine's — because the only way to assert "it asked nothing and changed
 * nothing" is to put all four in its way and look at them afterwards.
 */
beforeEach(function () {
    harness('worktree-path');

    $this->main = mainCheckout($this->root.'/desk');
    $this->shop = $this->root.'/shop';

    // A `gh` at the front of `PATH` that logs before it answers: the numeric
    // case is resolved out of the registry, and this is what proves it.
    $this->gh = recordingGh($this->root);
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('prints the absolute path alone on stdout, and nothing else anywhere', function () {
    registryHolds(['wt-desk-feat-checkout' => slotEntry(0, 'feat-checkout', branch: 'feat/checkout')]);

    $process = worktreePath(['feat/checkout']);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe($this->root.'/desk-worktrees/feat-checkout'."\n")
        ->and($process->getErrorOutput())->toBe('');
});

it('exits 1 with a message on stderr, and nothing on stdout, for a slug nothing holds', function (array $arguments) {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreePath($arguments);

    expect($process)->toHaveExited(1)
        // The whole point of the command: a typo'd slug in a script is an exit
        // code, where `create` would have bootstrapped a worktree for it.
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('is registered as')
        ->toContain('worktree list')
        ->and(dockerCalls())->toBe([]);
})->with([
    'a number nothing was created for' => [['442']],
    'a branch nothing was created for' => [['feat/checkout']],
    'a number that is nearly one' => [['44']],
]);

/**
 * `441` becomes `441-fix-login` or `issue-441` depending on what `gh` said the
 * day the worktree was made, and neither is recoverable from the number — but
 * both are written down. So the number is matched against the slugs the registry
 * has, which is the same answer without the round-trip.
 */
it('resolves a numeric slug out of the registry, without asking gh anything', function (string $slug) {
    registryHolds(['wt-desk-'.$slug => slotEntry(0, $slug)]);

    $process = worktreePath(['441'], env: ['PATH' => $this->gh.':'.getenv('PATH')]);

    expect($process)->toHaveSucceeded()
        ->and(trim($process->getOutput()))->toBe($this->root.'/desk-worktrees/'.$slug)
        ->and(is_file($this->root.'/gh.log'))->toBeFalse();
})->with([
    'named from the issue title' => ['441-fix-login'],
    'named without gh in the first place' => ['issue-441'],
    'named nothing but the number' => ['441'],
]);

/**
 * The control for the case above. "No `gh.log`" is also what a `PATH` the run
 * never read looks like, so one command that *does* resolve a number the way
 * `create` does is run against the same stub: `unlock` names the slug and then
 * removes nothing, which makes it the cheapest question in this package.
 */
it('puts that gh somewhere a run which does resolve a number would find it', function () {
    $process = worktree(['unlock', '441'], env: ['PATH' => $this->gh.':'.getenv('PATH')]);

    expect($process)->toHaveSucceeded()
        ->and((string) @file_get_contents($this->root.'/gh.log'))->toContain('issue view 441 --json title');
});

it('names both worktrees rather than guessing when a number matches two of them', function () {
    // What a retitled issue leaves behind: one `create 441` before the rename
    // and one after. No single run of `create` produces it, and a lookup that
    // picked one would send a `cd` somewhere arbitrary.
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-441-fix-the-login-form' => slotEntry(1, '441-fix-the-login-form'),
    ]);

    $process = worktreePath(['441']);

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("'441' names more than one worktree of desk")
        ->toContain('wt-desk-441-fix-login')
        ->toContain('wt-desk-441-fix-the-login-form');
});

it('derives a named slug exactly as create does, and looks nothing up for it either', function () {
    registryHolds(['wt-desk-feat-checkout' => slotEntry(2, 'feat-checkout', branch: 'feat/checkout')]);

    // `feat/checkout` and `feat-checkout` slugify onto one key, one directory
    // and one project — and a path lookup has no branch to put commits on, so
    // both name the worktree that is there.
    foreach (['feat/checkout', 'feat-checkout', 'FEAT/Checkout'] as $argument) {
        expect(trim(worktreePath([$argument])->getOutput()))->toBe($this->root.'/desk-worktrees/feat-checkout');
    }
});

it('takes no lock, makes no Docker call, and writes nothing', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    // Held by this process, which is unquestionably running: a run that reached
    // for this lock would wait ten minutes and then fail.
    lockTakenBy($this->home.'/locks/wt-desk-441-fix-login.lock', ownerRecord());

    $before = directorySnapshot($this->home);

    $process = worktreePath(['441'], env: ['PATH' => $this->gh.':'.getenv('PATH')]);

    expect($process)->toHaveSucceeded()
        ->and(trim($process->getOutput()))->toBe($this->root.'/desk-worktrees/441-fix-login')
        // The registry, the lock and its owner record, all exactly as they were.
        ->and(directorySnapshot($this->home))->toBe($before)
        ->and(is_file($this->home.'/locks/wt-desk-441-fix-login.lock/'.Owner::File))->toBeTrue()
        ->and(dockerCalls())->toBe([])
        ->and(is_file($this->root.'/gh.log'))->toBeFalse();
});

it('answers the same from the main checkout and from inside a worktree', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $attached = $this->root.'/desk-worktrees/441-fix-login';

    runGit($this->main, 'worktree', 'add', '--quiet', '-b', '441-fix-login', $attached);

    expect(trim(worktreePath(['441'], cwd: $this->main)->getOutput()))->toBe($attached)
        // Same registry, same anchor, same answer: `--git-common-dir` points at
        // the main checkout from in here too.
        ->and(trim(worktreePath(['441'], cwd: $attached)->getOutput()))->toBe($attached);
});

it('emits the registry entry with --json, in the shape create --json prints', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreePath(['441', '--json']);

    expect($process)->toHaveSucceeded()
        ->and(substr_count($process->getOutput(), "\n"))->toBe(1)
        ->and(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'project' => 'wt-desk-441-fix-login',
            'slot' => 0,
            'repo' => $this->main,
            'slug' => '441-fix-login',
            'branch' => '441-fix-login',
            'path' => $this->root.'/desk-worktrees/441-fix-login',
            'ports' => ['app' => 20000, 'vite' => 20001, 'reverb' => 20002, 'db' => 20003, 'redis' => 20004],
            'created_at' => '2026-01-01T00:00:00Z',
            'degraded' => [],
        ]);
});

/**
 * A key is a Compose project name, so an entry another checkout claims names
 * that checkout's worktree — and handing its path back would `cd` somebody into
 * a directory this repository does not own.
 */
it('refuses a key registered to another checkout, rather than pointing at it', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', repo: $this->shop)]);

    $process = worktreePath(['441']);

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("'wt-desk-441-fix-login' is registered to ".$this->shop)
        ->toContain("set 'repo_slug' in config/worktree.php");
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreePath($arguments);

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree path <slug> [--json]');
})->with([
    'no name at all' => [[], 'name the worktree to look up: an issue number, or a branch name'],
    'an empty name' => [[''], 'name the worktree to look up: an issue number, or a branch name'],
    'a second name' => [['441', 'main'], 'path takes one name; given 441 main'],
    'an option it does not have' => [['441', '--refresh'], "unknown option '--refresh'; this command takes --json"],
]);

/**
 * A `gh` at the front of `PATH` that records being called before it answers, so
 * a case can assert the lookup never happened. It answers the way a real one
 * would, which is what makes the assertion about `path` rather than about a
 * broken stub.
 *
 * @return string The directory to put at the front of `PATH`.
 */
function recordingGh(string $root): string
{
    $bin = $root.'/bin';

    mkdir($bin, 0755, true);
    file_put_contents($bin.'/gh', "#!/bin/sh\necho \"\$@\" >> ".escapeshellarg($root.'/gh.log')."\necho '{\"title\":\"Fix login\"}'\n");
    chmod($bin.'/gh', 0755);

    return $bin;
}
