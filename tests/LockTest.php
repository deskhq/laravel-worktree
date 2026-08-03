<?php

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Registry\Liveness;
use DeskHQ\LaravelWorktree\Registry\Locks;
use DeskHQ\LaravelWorktree\Registry\Owner;

beforeEach(function () {
    $this->home = temporaryDirectory('worktree-home');
    $this->diagnostics = fopen('php://memory', 'w+');
});

afterEach(function () {
    deleteDirectory($this->home);
});

it('takes a lock by creating a directory, and gives it back by removing it', function () {
    $lock = lockAt($this->home.'/registry.lock');

    $lock->acquire();

    expect($lock->isHeld())->toBeTrue()
        ->and($this->home.'/registry.lock')->toBeDirectory();

    $lock->release();

    expect($lock->isHeld())->toBeFalse()
        ->and($this->home.'/registry.lock')->not->toBeDirectory();
});

it('creates the lock directory it lives in', function () {
    $lock = lockAt($this->home.'/locks/wt-desk-441.lock');

    $lock->acquire();

    expect($this->home.'/locks/wt-desk-441.lock')->toBeDirectory();
});

it('waits for whoever has it, then says something the user can act on', function () {
    mkdir($this->home.'/registry.lock');

    $lock = lockAt($this->home.'/registry.lock', 2, 'could not acquire the registry lock; remove it and retry');

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
    $held = lockAt($this->home.'/registry.lock');
    $other = lockAt($this->home.'/registry.lock');

    $held->acquire();
    $other->release();

    expect($this->home.'/registry.lock')->toBeDirectory();

    $held->release();

    expect($this->home.'/registry.lock')->not->toBeDirectory();
});

it('gives the lock back however the work it wraps ends', function () {
    $lock = lockAt($this->home.'/registry.lock');

    expect($lock->hold(fn () => 'allocated'))->toBe('allocated')
        ->and($lock->isHeld())->toBeFalse();

    expect(fn () => $lock->hold(fn () => throw new WorktreeException('no free slot')))
        ->toThrow(WorktreeException::class, 'no free slot')
        ->and($lock->isHeld())->toBeFalse()
        ->and($this->home.'/registry.lock')->not->toBeDirectory();
});

it('leaves a lock that was already held held', function () {
    $lock = lockAt($this->home.'/registry.lock');

    $lock->acquire();
    $lock->hold(fn () => null);

    expect($lock->isHeld())->toBeTrue();
});

it('hands back one lock per path, so a run cannot end up waiting on itself', function () {
    $locks = locksIn($this->home);

    expect($locks->registry())->toBe($locks->registry())
        ->and($locks->worktree('wt-desk-441'))->toBe($locks->worktree('wt-desk-441'))
        ->and($locks->worktree('wt-desk-441'))->not->toBe($locks->worktree('wt-desk-512'));

    $locks->worktree('wt-desk-441')->acquire();
    $locks->worktree('wt-desk-441')->acquire();

    expect($locks->worktree('wt-desk-441')->isHeld())->toBeTrue();
});

it('releases both locks when the run ends, whatever it was holding', function () {
    $shutdown = new ShutdownHandler(new Output($this->diagnostics));
    $locks = new Locks($this->home, $shutdown, new Output($this->diagnostics));

    $locks->registry()->acquire();
    $locks->worktree('wt-desk-441')->acquire();

    $shutdown->release();

    expect($this->home.'/registry.lock')->not->toBeDirectory()
        ->and($this->home.'/locks/wt-desk-441.lock')->not->toBeDirectory();
});

it('keeps a key that is not a plain name inside the lock directory', function () {
    $locks = locksIn($this->home);

    expect($locks->worktree('../../escaped')->path())->toBe($this->home.'/locks/escaped.lock')
        ->and($locks->worktree('wt-shop-feat/checkout')->path())->toBe($this->home.'/locks/wt-shop-feat-checkout.lock');
});

/*
|--------------------------------------------------------------------------
| Who holds it
|--------------------------------------------------------------------------
|
| The shutdown handler covers SIGINT and SIGTERM. It cannot cover SIGKILL, the
| OOM killer, or a laptop rebooted mid-bootstrap, and each of those used to
| leave a directory owned by nothing that the next run waited ten minutes for.
| So the directory records its holder, and a run that finds it there judges it —
| conservatively, because breaking a live lock is the failure the lock exists to
| prevent.
|
*/

it('records who took it, inside the lock it took', function () {
    $lock = lockAt($this->home.'/registry.lock');

    $lock->acquire();

    $owner = $lock->owner();

    expect($owner)->not->toBeNull()
        ->and($owner->pid)->toBe(getmypid())
        ->and($owner->host)->toBe(machine()->host())
        ->and($owner->startedAt)->toBe(machine()->startedAt((int) getmypid()))
        ->and($owner->takenAt)->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/')
        ->and($owner->liveness(machine()))->toBe(Liveness::Alive);
});

it('breaks a lock whose holder is not running, and says that it did', function () {
    lockTakenBy($this->home.'/locks/wt-desk-441.lock', ownerRecord(['pid' => deadPid()]));

    $lock = lockAt($this->home.'/locks/wt-desk-441.lock', 2, 'contended', $this->diagnostics);

    $lock->acquire();

    expect($lock->isHeld())->toBeTrue()
        // Taken, not merely emptied: the record inside it is this run's now.
        ->and($lock->owner()?->pid)->toBe(getmypid())
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain('was taken by pid ')
        ->toContain('which is not running any more; breaking it and taking it');
});

it('waits for a lock whose holder is running, exactly as it did before', function () {
    lockTakenBy($this->home.'/locks/wt-desk-441.lock', ownerRecord());

    $lock = lockAt($this->home.'/locks/wt-desk-441.lock', 2, 'still working on it', $this->diagnostics);

    expect(fn () => $lock->acquire())->toThrow(WorktreeException::class, 'still working on it')
        ->and($this->home.'/locks/wt-desk-441.lock')->toBeDirectory()
        // Named once, on the first failed attempt, rather than ten minutes of
        // silence a driving agent cannot tell from a hang.
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain('waiting for the lock at '.$this->home.'/locks/wt-desk-441.lock')
        ->toContain('worktree create 441');
});

it('does not read a pid that has been issued again as the original holder', function () {
    // This process's own pid, which is unquestionably running — recorded as
    // having started at a moment it did not. That is what a recycled pid looks
    // like, and `posix_kill($pid, 0)` alone cannot tell it from the real holder.
    lockTakenBy($this->home.'/locks/wt-desk-441.lock', ownerRecord(['started_at' => 'Sun Jan  1 00:00:00 2006']));

    $lock = lockAt($this->home.'/locks/wt-desk-441.lock', 2, 'contended', $this->diagnostics);

    $lock->acquire();

    expect($lock->isHeld())->toBeTrue()
        ->and(diagnosticsIn($this->diagnostics))->toContain('is not running any more');
})->skip(
    fn () => machine()->startedAt((int) getmypid()) === null,
    'this machine will not say when a process started, so a recycled pid cannot be told from the original holder',
);

it('waits for a lock with no owner record, rather than assuming nobody holds it', function () {
    // What a lock written by an earlier version of this package looks like: a
    // bare directory. "I cannot tell who holds this" is not "nobody does".
    mkdir($this->home.'/locks/wt-desk-441.lock', 0755, true);

    $lock = lockAt($this->home.'/locks/wt-desk-441.lock', 2, 'contended', $this->diagnostics);

    expect(fn () => $lock->acquire())->toThrow(WorktreeException::class, 'contended')
        ->and($this->home.'/locks/wt-desk-441.lock')->toBeDirectory()
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain('which records no holder')
        ->toContain("'worktree unlock' removes it");
});

it('waits for a lock it cannot read the record of', function (string $contents) {
    mkdir($this->home.'/locks/wt-desk-441.lock', 0755, true);
    file_put_contents($this->home.'/locks/wt-desk-441.lock/owner.json', $contents);

    $lock = lockAt($this->home.'/locks/wt-desk-441.lock', 2, 'contended');

    expect(fn () => $lock->acquire())->toThrow(WorktreeException::class, 'contended')
        ->and($this->home.'/locks/wt-desk-441.lock')->toBeDirectory();
})->with([
    'nothing at all' => [''],
    'a record cut off mid-write' => ['{"pid": 4242, "host": "stu'],
    'a record with no pid' => ['{"host": "studio.local", "taken_at": "2026-08-01T09:12:44Z"}'],
    'a pid that is not a number' => ['{"pid": "4242", "host": "studio.local", "taken_at": "2026-08-01T09:12:44Z"}'],
]);

it('waits for a lock another machine took, whatever its pid table says here', function () {
    // A `WORKTREE_HOME` on a network share. The pid is one this machine does
    // not have, and on this machine that means nothing at all.
    lockTakenBy($this->home.'/locks/wt-desk-441.lock', ownerRecord([
        'pid' => deadPid(),
        'host' => 'somebody-elses-laptop.local',
    ]));

    $lock = lockAt($this->home.'/locks/wt-desk-441.lock', 2, 'contended', $this->diagnostics);

    expect(fn () => $lock->acquire())->toThrow(WorktreeException::class, 'contended')
        ->and($this->home.'/locks/wt-desk-441.lock')->toBeDirectory()
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain('somebody-elses-laptop.local')
        ->toContain('another machine, so nothing here can tell whether it is still running');
});

it('will not break a lock whose record changed under it', function () {
    // Two runs judging the same dead lock at once: the second finds the first's
    // fresh lock where the dead one was, and must leave it alone rather than
    // rmdir a directory somebody is inside.
    $path = $this->home.'/locks/wt-desk-441.lock';

    lockTakenBy($path, ownerRecord(['pid' => deadPid()]));

    $judged = Owner::read($path);

    lockTakenBy($path, ownerRecord(['taken_at' => '2026-08-03T11:00:00Z']));

    expect(lockAt($path)->breakOpen($judged))->toBeFalse()
        ->and($path)->toBeDirectory()
        ->and(Owner::read($path)?->takenAt)->toBe('2026-08-03T11:00:00Z');
});

it('will not break a lock that has grown a record since it was read as having none', function () {
    $path = $this->home.'/locks/wt-desk-441.lock';

    lockTakenBy($path, ownerRecord());

    expect(lockAt($path)->breakOpen(null))->toBeFalse()
        ->and($path)->toBeDirectory();
});

it('lists every lock on the machine, whichever checkout took it', function () {
    $locks = locksIn($this->home);

    $locks->registry()->acquire();
    $locks->worktree('wt-desk-441')->acquire();
    $locks->worktree('wt-shop-feat-checkout')->acquire();

    expect(array_map(fn ($lock) => $lock->path(), locksIn($this->home)->taken()))
        ->toBe([
            $this->home.'/registry.lock',
            $this->home.'/locks/wt-desk-441.lock',
            $this->home.'/locks/wt-shop-feat-checkout.lock',
        ]);
});

it('lists nothing when no lock is held', function () {
    expect(locksIn($this->home)->taken())->toBe([]);
});

it('gives back a lock with a half-written record in it, rather than one nothing can rmdir', function () {
    // The window between `Owner::writeInto()` writing its temporary file and
    // renaming it into place. A `release()` that only unlinked `owner.json`
    // would leave the directory there for ever — a lock outliving the run that
    // held it, which is the failure this whole file is about.
    $lock = lockAt($this->home.'/registry.lock');

    $lock->acquire();

    file_put_contents($this->home.'/registry.lock/.owner-abcdef.tmp', '{"pid": 4242');

    $lock->release();

    expect($this->home.'/registry.lock')->not->toBeDirectory();
});
