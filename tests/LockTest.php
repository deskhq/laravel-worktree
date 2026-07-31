<?php

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Registry\Lock;
use DeskHQ\LaravelWorktree\Registry\Locks;

beforeEach(function () {
    $this->home = temporaryDirectory('worktree-home');
});

afterEach(function () {
    deleteDirectory($this->home);
});

it('takes a lock by creating a directory, and gives it back by removing it', function () {
    $lock = new Lock($this->home.'/registry.lock', 2, 'contended');

    $lock->acquire();

    expect($lock->isHeld())->toBeTrue()
        ->and($this->home.'/registry.lock')->toBeDirectory();

    $lock->release();

    expect($lock->isHeld())->toBeFalse()
        ->and($this->home.'/registry.lock')->not->toBeDirectory();
});

it('creates the lock directory it lives in', function () {
    $lock = new Lock($this->home.'/locks/wt-desk-441.lock', 2, 'contended');

    $lock->acquire();

    expect($this->home.'/locks/wt-desk-441.lock')->toBeDirectory();
});

it('waits for whoever has it, then says something the user can act on', function () {
    mkdir($this->home.'/registry.lock');

    $lock = new Lock($this->home.'/registry.lock', 2, 'could not acquire the registry lock; remove it and retry');

    expect(fn () => $lock->acquire())
        ->toThrow(WorktreeException::class, 'could not acquire the registry lock; remove it and retry')
        ->and($lock->isHeld())->toBeFalse()
        // The waiting run must not have taken the lock away from the run it
        // gave up waiting for.
        ->and($this->home.'/registry.lock')->toBeDirectory();
});

it('never releases a lock it does not hold', function () {
    // Two `Lock` objects over one directory stand in for two processes: the
    // one that lost the race must not be able to rmdir the winner's lock, or
    // both would believe they were alone in the critical section.
    $held = new Lock($this->home.'/registry.lock', 2, 'contended');
    $other = new Lock($this->home.'/registry.lock', 2, 'contended');

    $held->acquire();
    $other->release();

    expect($this->home.'/registry.lock')->toBeDirectory();

    $held->release();

    expect($this->home.'/registry.lock')->not->toBeDirectory();
});

it('gives the lock back however the work it wraps ends', function () {
    $lock = new Lock($this->home.'/registry.lock', 2, 'contended');

    expect($lock->hold(fn () => 'allocated'))->toBe('allocated')
        ->and($lock->isHeld())->toBeFalse();

    expect(fn () => $lock->hold(fn () => throw new WorktreeException('no free slot')))
        ->toThrow(WorktreeException::class, 'no free slot')
        ->and($lock->isHeld())->toBeFalse()
        ->and($this->home.'/registry.lock')->not->toBeDirectory();
});

it('leaves a lock that was already held held', function () {
    $lock = new Lock($this->home.'/registry.lock', 2, 'contended');

    $lock->acquire();
    $lock->hold(fn () => null);

    expect($lock->isHeld())->toBeTrue();
});

it('hands back one lock per path, so a run cannot end up waiting on itself', function () {
    $locks = new Locks($this->home, new ShutdownHandler(new Output(fopen('php://memory', 'w+'))));

    expect($locks->registry())->toBe($locks->registry())
        ->and($locks->worktree('wt-desk-441'))->toBe($locks->worktree('wt-desk-441'))
        ->and($locks->worktree('wt-desk-441'))->not->toBe($locks->worktree('wt-desk-512'));

    $locks->worktree('wt-desk-441')->acquire();
    $locks->worktree('wt-desk-441')->acquire();

    expect($locks->worktree('wt-desk-441')->isHeld())->toBeTrue();
});

it('releases both locks when the run ends, whatever it was holding', function () {
    $shutdown = new ShutdownHandler(new Output(fopen('php://memory', 'w+')));
    $locks = new Locks($this->home, $shutdown);

    $locks->registry()->acquire();
    $locks->worktree('wt-desk-441')->acquire();

    $shutdown->release();

    expect($this->home.'/registry.lock')->not->toBeDirectory()
        ->and($this->home.'/locks/wt-desk-441.lock')->not->toBeDirectory();
});

it('keeps a key that is not a plain name inside the lock directory', function () {
    $locks = new Locks($this->home, new ShutdownHandler(new Output(fopen('php://memory', 'w+'))));

    expect($locks->worktree('../../escaped')->path())->toBe($this->home.'/locks/escaped.lock')
        ->and($locks->worktree('wt-shop-feat/checkout')->path())->toBe($this->home.'/locks/wt-shop-feat-checkout.lock');
});
