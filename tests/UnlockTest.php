<?php

use DeskHQ\LaravelWorktree\Registry\Lock;
use DeskHQ\LaravelWorktree\Registry\Owner;
use Symfony\Component\Process\Process;

/**
 * `unlock`, end to end through the real binary: a real repository, real lock
 * directories, and — where a case needs a holder that is genuinely running — a
 * real second process holding one.
 *
 * {@see Lock} already breaks a lock it can
 * prove is dead, so what is asserted here is the rest: the cases that proof will
 * not take, and above all what the command refuses. Removing a lock somebody is
 * inside puts two bootstraps in one directory, which is the failure the lock
 * exists to prevent.
 */
beforeEach(function () {
    harness('worktree-unlock');

    $this->main = $this->root.'/desk';
    $this->gate = $this->root.'/gate';
    $this->lock = $this->home.'/locks/wt-desk-feat-checkout.lock';

    mkdir($this->main, 0755, true);

    runGit($this->main, 'init', '--quiet', '--initial-branch=main', '.');
});

afterEach(function () {
    // Whatever is still holding a lock is let go before the directory it is
    // working in is taken out from under it.
    touch($this->gate);

    deleteDirectory($this->root);
});

it('removes a lock whose holder is gone, and names who took it', function () {
    lockTakenBy($this->lock, ownerRecord(['pid' => deadPid(), 'command' => 'worktree create feat/checkout']));

    $process = worktreeUnlock(['feat/checkout']);

    expect($process)->toHaveSucceeded()
        // A lock is a directory, and there is no answer here for a script to
        // read — only an exit code, like `remove`.
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('removed '.$this->lock)
        ->toContain('worktree create feat/checkout')
        ->toContain('which is not running any more')
        ->and($this->lock)->not->toBeDirectory();
});

it('removes a lock that records no holder at all', function () {
    // What an earlier version of this package left behind: a bare directory.
    // The acquire path waits for one of these forever, which is the whole
    // reason this command exists.
    mkdir($this->lock, 0755, true);

    $process = worktreeUnlock(['feat/checkout']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('removed '.$this->lock)
        ->toContain('which recorded no holder')
        ->and($this->lock)->not->toBeDirectory();
});

it('refuses a lock whose holder is still running, and says what to type instead', function () {
    $holder = startsHolding('wt-desk-feat-checkout');

    $process = worktreeUnlock(['feat/checkout']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain($this->lock.' is held by pid '.$holder->getPid())
        ->toContain('and that process is still running')
        ->toContain('pass --force to remove it anyway')
        // Untouched, which is the point: the run that is inside that directory
        // is still inside it.
        ->and($this->lock)->toBeDirectory()
        ->and(Owner::read($this->lock)?->pid)->toBe($holder->getPid());
});

it('removes a lock whose holder is still running when it is told to', function () {
    $holder = startsHolding('wt-desk-feat-checkout');

    $process = worktreeUnlock(['feat/checkout', '--force']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('removed '.$this->lock)
        ->toContain('pid '.$holder->getPid())
        ->toContain('which this run was told to remove regardless')
        ->and($this->lock)->not->toBeDirectory();
});

it('reads one holder the same way whichever timezone each run is in', function () {
    // A start time read out of `ps` is a *rendered* wall-clock date, so an
    // unpinned locale or timezone hands two runs two different strings for one
    // process — which reads as a recycled pid, and takes a lock somebody is
    // inside. Linux answers this out of /proc in ticks and never notices;
    // macOS, where most of this package's runs happen, is where it bites.
    $holder = startsHolding('wt-desk-feat-checkout', 'Europe/Paris');

    $process = worktree(['unlock', 'feat/checkout'], env: ['TZ' => 'Asia/Tokyo', 'LC_ALL' => 'en_US.UTF-8']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('pid '.$holder->getPid())
        ->toContain('and that process is still running')
        ->and($this->lock)->toBeDirectory();
});

it('refuses a lock another machine took, which is what a shared home looks like', function () {
    lockTakenBy($this->lock, ownerRecord(['pid' => deadPid(), 'host' => 'somebody-elses-laptop.local']));

    $process = worktreeUnlock(['feat/checkout']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('somebody-elses-laptop.local')
        ->toContain('nothing here can tell whether it is still running')
        ->and($this->lock)->toBeDirectory();
});

it('says so when there is no lock to remove', function () {
    $process = worktreeUnlock(['feat/checkout']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain('nothing holds '.$this->lock);
});

it('takes every lock on the machine under --all, whichever checkout left it', function () {
    // Machine-wide rather than repository-wide, because the locks are: they
    // hang off WORKTREE_HOME, and a --all that skipped another clone's stale
    // lock would leave behind the thing it was run to clear.
    lockTakenBy($this->home.'/registry.lock', ownerRecord(['pid' => deadPid()]));
    lockTakenBy($this->lock, ownerRecord(['pid' => deadPid()]));
    lockTakenBy($this->home.'/locks/wt-shop-441-fix-login.lock', ownerRecord(['pid' => deadPid()]));

    $process = worktreeUnlock(['--all']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('removed '.$this->home.'/registry.lock')
        ->toContain('removed '.$this->home.'/locks/wt-shop-441-fix-login.lock')
        ->and($this->home.'/registry.lock')->not->toBeDirectory()
        ->and($this->lock)->not->toBeDirectory()
        ->and($this->home.'/locks/wt-shop-441-fix-login.lock')->not->toBeDirectory();
});

it('under --all, leaves the live ones alone and exits non-zero having said which', function () {
    lockTakenBy($this->home.'/registry.lock', ownerRecord(['pid' => deadPid()]));

    $holder = startsHolding('wt-desk-feat-checkout');

    $process = worktreeUnlock(['--all']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('removed '.$this->home.'/registry.lock')
        ->toContain('pid '.$holder->getPid())
        ->toContain('1 of them was left alone, and 1 removed; --force removes it regardless')
        ->and($this->lock)->toBeDirectory();
});

it('exits non-zero when a lock it was asked to clear is still there afterwards', function () {
    mkdir($this->lock, 0755, true);

    // A lock directory that cannot be moved out of the way stands in for one
    // that changed under the removal: either way nothing went, and reporting
    // success for a lock that is still there is what would send somebody back
    // to `rm -rf`.
    chmod(dirname($this->lock), 0555);

    $process = worktreeUnlock(['feat/checkout']);

    chmod(dirname($this->lock), 0755);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())->toContain($this->lock.' is still there')
        ->and($this->lock)->toBeDirectory();
})->skip(
    fn () => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root writes into a read-only directory regardless',
);

it('says so when no lock is held at all', function () {
    $process = worktreeUnlock(['--all']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain('no lock is held on this machine');
});

it('refuses to be called without saying which lock', function (array $arguments, string $said) {
    $process = worktreeUnlock($arguments);

    expect($process)->toHaveExited(64)
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree unlock <slug> [--all] [--force]');
})->with([
    'nothing at all' => [[], 'name the worktree whose lock to remove'],
    'an empty name' => [[''], 'name the worktree whose lock to remove'],
    'a name and --all' => [['feat/checkout', '--all'], '--all unlocks every lock on this machine, so it takes no name'],
    'two names' => [['feat/checkout', '441'], 'unlock takes one name'],
    'an option it does not have' => [['feat/checkout', '--dry-run'], "unknown option '--dry-run'"],
]);

it('is listed in the usage text like every other command', function () {
    expect(worktree(['help'])->getErrorOutput())
        ->toContain('unlock <slug> [--all] [--force]')
        ->toContain('Remove a lock a run never gave back, naming who took it.');
});

/**
 * @param  list<string>  $arguments
 */
function worktreeUnlock(array $arguments): Process
{
    return worktree(['unlock', ...$arguments]);
}

/**
 * A second process holding $key's lock for real — a running pid, its own owner
 * record, and no intention of giving it back until the gate says so.
 */
function startsHolding(string $key, ?string $timezone = null): Process
{
    $process = new Process([
        PHP_BINARY,
        packagePath('tests/Fixtures/takes-a-worktree-lock.php'),
        test()->home,
        $key,
        test()->gate,
    ], null, $timezone === null ? null : ['TZ' => $timezone, 'LC_ALL' => 'C']);

    $process->setTimeout(60);
    $process->start();
    $process->waitUntil(fn (string $type, string $chunk) => str_contains($chunk, 'lock held'));

    expect(test()->home.'/locks/'.$key.'.lock')->toBeDirectory();

    return $process;
}
