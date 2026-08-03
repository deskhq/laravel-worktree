<?php

/**
 * `start`, end to end through the real binary: a worktree a real `create` made,
 * stopped, and brought back — against the fake `docker` and the fake
 * `vendor/bin/sail` that `create` installs, because none of what this command
 * decides needs a daemon to be observable.
 *
 * The cases below are the contract: one boot and no pipeline, a fresh overlay
 * before it, and a refusal for anything that was never bootstrapped in the
 * first place.
 */
beforeEach(function () {
    harness('worktree-start');

    $this->main = mainCheckout($this->root.'/desk');
    $this->worktree = $this->root.'/desk-worktrees/feat-checkout';
    $this->gate = $this->root.'/gate';
    $this->base = freePortBase(100);

    configureRepository();
});

afterEach(function () {
    // Whatever a held run is still waiting on.
    touch($this->gate);

    deleteDirectory($this->root);
});

/**
 * A stopped worktree has already been through the recipe: its `vendor/` is
 * where it left it, and its database is in a volume nothing removed. So the
 * step that would run again is the assertion — it must not.
 */
it('brings the app service back up, and runs no bootstrap step doing it', function () {
    configureRepository(['steps' => [
        // No sentinel, deliberately: a step that would be skipped anyway proves
        // nothing about whether the pipeline ran.
        ['label' => 'Installing', 'sail' => 'composer install'],
    ]]);

    worktreeCreate();

    $process = worktreeStart();

    expect($process)->toHaveSucceeded()
        // Nothing machine-readable here either: `create` prints the path, this
        // prints an exit code.
        ->and($process->getOutput())->toBe('')
        ->and(recorded($this->worktree.'/sail.log'))
        ->toBe(['up -d laravel.test', 'composer install', 'up -d laravel.test'])
        ->and($process->getErrorOutput())
        ->toContain('started wt-desk-feat-checkout at '.$this->worktree)
        ->toContain('nothing was bootstrapped, and its slot never moved');
});

it('brings back a worktree that stop left standing, with its entry and slot unmoved', function () {
    worktreeCreate();

    $registry = registryNow();

    expect(worktreeStop())->toHaveSucceeded();

    $process = worktreeStart();

    expect($process)->toHaveSucceeded()
        ->and(recorded($this->worktree.'/sail.log'))->toBe(['up -d laravel.test', 'up -d laravel.test'])
        ->and(registryNow())->toBe($registry);
});

/**
 * The overlay carries nothing but this configuration — it says so in its own
 * first line — and it can be out of date by the time a stopped worktree comes
 * back. Regenerating it is what `create` does on every run.
 */
it('writes the Compose overlay again before booting, so a changed configuration takes effect', function () {
    configureRepository(['compose' => ['keep_services' => ['pgsql']]]);

    worktreeCreate();

    expect(file_get_contents($this->worktree.'/compose.worktree.yaml'))->not->toContain('redis');

    configureRepository(['compose' => ['keep_services' => ['pgsql', 'redis']]]);

    $process = worktreeStart();

    expect($process)->toHaveSucceeded()
        ->and(file_get_contents($this->worktree.'/compose.worktree.yaml'))->toContain('redis')
        // Written before the boot, or the boot would have started the services
        // the old file named.
        ->and($process->getErrorOutput())->toContain('generated compose.worktree.yaml');
});

/**
 * `start` is not a second, weaker `create`: booting a worktree whose bootstrap
 * never finished leaves somebody looking at an application with no dependencies
 * installed and no key generated.
 */
it('refuses a worktree that never finished a bootstrap, and names create', function () {
    worktreeCreate();

    unlink($this->worktree.'/.worktree-ready');

    $process = worktreeStart();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('wt-desk-feat-checkout has never finished a bootstrap')
        ->toContain("'worktree create feat-checkout' resumes it")
        // And nothing was booted on the way to refusing.
        ->and(recorded($this->worktree.'/sail.log'))->toBe(['up -d laravel.test']);
});

it('refuses an entry whose worktree directory is gone, and names create', function () {
    worktreeCreate();

    deleteDirectory($this->worktree);

    $process = worktreeStart();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('there is no worktree at '.$this->worktree.' any more')
        ->toContain("'worktree create feat-checkout' makes it again")
        ->toContain("the branch 'feat/checkout' is still in the repository")
        // The slot is untouched: reclaiming one is `reap`'s job, not this one's.
        ->and(registryNow())->toHaveKey('wt-desk-feat-checkout');
});

it('refuses a slug nothing holds, and names both create and list', function () {
    $process = worktreeStart(['feat/checkout']);

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("no worktree of desk is registered as 'feat/checkout'")
        ->toContain("'worktree create feat/checkout' makes one");
});

/**
 * The remedy a failed boot names has to be the one that would actually fix it.
 * `create` re-enters a ready worktree *without* booting it, so sending somebody
 * there after a failed start would send them to a command that does nothing
 * about the problem.
 */
it('reports a boot that would not come up in the words of start, not of create', function () {
    worktreeCreate();

    writeFakeSail($this->worktree.'/vendor/bin/sail', 1);

    $process = worktreeStart();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain("'./vendor/bin/sail up -d laravel.test' failed (exit 1)")
        ->toContain("the worktree is still stopped — fix it, then 'worktree start feat/checkout' brings it back up")
        ->not->toContain('picks up where it left off');
});

it('refuses to start a project registered to another checkout', function () {
    worktreeCreate();

    $registry = registryNow();
    $registry['wt-desk-feat-checkout']['repo'] = $this->root.'/shop';

    file_put_contents($this->home.'/registry.json', json_encode($registry));

    $process = worktreeStart();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('is registered to '.$this->root.'/shop, not to '.$this->main)
        ->and(recorded($this->worktree.'/sail.log'))->toBe(['up -d laravel.test']);
});

it('makes a start wait for a create of the same worktree rather than booting under it', function () {
    configureRepository(['steps' => [gatedStep()]]);

    $create = startWorktreeCreate();
    waitForOutput($create, '[1/1] Waiting', stderr: true);

    $start = startWorktreeStart();

    usleep(1_500_000);

    expect($start->isRunning())->toBeTrue()
        ->and($start->getErrorOutput())->not->toContain('started wt-desk-feat-checkout');

    touch($this->gate);

    expect($create->wait())->toBe(0, worktreeFailure($create))
        ->and($start->wait())->toBe(0, worktreeFailure($start))
        ->and(recorded($this->worktree.'/sail.log'))->toBe(['up -d laravel.test', 'up -d laravel.test']);
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreeStart($arguments);

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree start <slug>');
})->with([
    'no name at all' => [[], 'name the worktree to start'],
    'a name with nothing in it' => [[''], 'name the worktree to start'],
    'more than a name' => [['feat/checkout', 'extra'], 'start takes one name; given feat/checkout extra'],
    'an option it does not have' => [['feat/checkout', '--all'], "this command takes no options, and '--all' is one"],
]);
