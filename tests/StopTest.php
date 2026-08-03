<?php

/**
 * `stop`, end to end through the real binary, against worktrees a real `create`
 * made or a registry declares: real locks, a real registry on disk, and the
 * suite's fake `docker`, whose argv is the whole of what has to be asserted —
 * no daemon is needed to prove that one Compose invocation was made and that
 * nothing else was.
 *
 * The cases below are the contract: the containers stop, *nothing else moves*,
 * the bulk forms are scoped the way `list` is scoped, and the per-worktree lock
 * is the same one `create` holds.
 */
beforeEach(function () {
    harness('worktree-stop');

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
 * The whole point of the command, stated as what did *not* happen: a stopped
 * worktree is one you come back to, so the database, the edited `.env`, the
 * sentinels and the slot all have to still be there afterwards.
 */
it('stops the containers and leaves the volumes, the files, the entry and the slot', function () {
    $this->docker = fakeDockerBinary($this->root, containers: ['c1'], volumes: ['wt-desk-feat-checkout_sail-pgsql']);

    worktreeCreate();

    $registry = registryNow();

    $process = worktreeStop();

    expect($process)->toHaveSucceeded()
        // Nothing machine-readable happens here: the exit code is the answer.
        ->and($process->getOutput())->toBe('')
        ->and(stopCalls())->toBe(['compose -p wt-desk-feat-checkout stop'])
        // None of the verbs that destroy something. `stop` reclaims memory; the
        // disk is `remove`'s business and `reap`'s.
        ->and(implode("\n", dockerCalls()))
        ->not->toContain('down --volumes')
        ->not->toContain('volume rm')
        ->not->toContain('rm --force')
        ->and($this->worktree)->toBeDirectory()
        ->and($this->worktree.'/.worktree-ready')->toBeFile()
        ->and($this->worktree.'/.env')->toBeFile()
        // The slot above all: releasing it would let another worktree take
        // these ports, and `start` would have nowhere to come back to.
        ->and(registryNow())->toBe($registry)
        ->and($process->getErrorOutput())
        ->toContain('stopping the containers of wt-desk-feat-checkout')
        ->toContain('stopped 1 worktree: wt-desk-feat-checkout')
        ->toContain("'worktree start <slug>' brings one back");
});

it('stops every worktree of this repository under --all, and no other checkout\'s', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout'),
        'wt-shop-feat-login' => slotEntry(2, 'feat-login', repo: $this->root.'/shop'),
    ]);

    $process = worktreeStop(['--all']);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe('')
        // The registry is machine-global because host ports are; a listing is
        // not, and neither is this.
        ->and(stopCalls())->toBe([
            'compose -p wt-desk-441-fix-login stop',
            'compose -p wt-desk-feat-checkout stop',
        ])
        ->and($process->getErrorOutput())->toContain('stopped 2 worktrees: wt-desk-441-fix-login, wt-desk-feat-checkout');
});

/**
 * The one people will actually type: not "stop this one", but "I am working on
 * this one now".
 */
it('keeps the named worktree running under --all-except, and stops the rest', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout'),
    ]);

    $process = worktreeStop(['--all-except', 'feat/checkout']);

    expect($process)->toHaveSucceeded()
        ->and(stopCalls())->toBe(['compose -p wt-desk-441-fix-login stop'])
        ->and($process->getErrorOutput())->toContain('keeping wt-desk-feat-checkout running');
});

/**
 * A number is matched against the registry rather than derived, because `441`
 * became `441-fix-login` or `issue-441` depending on what `gh` said the day the
 * worktree was made — and the worktree being kept alive is the one thing this
 * form must not get wrong.
 */
it('resolves the kept worktree by issue number without asking gh', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout'),
    ]);

    $process = worktreeStop(['--all-except', '441']);

    expect($process)->toHaveSucceeded()
        ->and(stopCalls())->toBe(['compose -p wt-desk-feat-checkout stop'])
        ->and($process->getErrorOutput())->toContain('keeping wt-desk-441-fix-login running');
});

/**
 * A typo in the name of the worktree to protect would otherwise stop
 * everything, *including* the one it was typed to keep alive — the exact
 * opposite of what was asked.
 */
it('refuses --all-except a slug nothing holds, rather than stopping everything', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout'),
    ]);

    $process = worktreeStop(['--all-except', 'feat/chekcout']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())->toContain("no worktree of desk is registered as 'feat/chekcout'")
        ->and(stopCalls())->toBe([]);
});

it('widens both bulk forms to the machine with --all-repos', function () {
    registryHolds([
        'wt-desk-feat-checkout' => slotEntry(0, 'feat-checkout'),
        'wt-shop-feat-login' => slotEntry(1, 'feat-login', repo: $this->root.'/shop'),
    ]);

    $process = worktreeStop(['--all', '--all-repos']);

    expect($process)->toHaveSucceeded()
        ->and(stopCalls())->toBe([
            'compose -p wt-desk-feat-checkout stop',
            'compose -p wt-shop-feat-login stop',
        ]);
});

it('says so, and exits clean, when there is nothing holding a slot to stop', function () {
    $process = worktreeStop(['--all']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain('nothing to stop: no worktree of desk holds a slot')
        ->and(stopCalls())->toBe([]);
});

it('refuses a slug nothing holds, and points at list', function () {
    $process = worktreeStop(['feat/checkout']);

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("no worktree of desk is registered as 'feat/checkout'")
        ->toContain("'worktree list' shows the ones there are")
        ->and(stopCalls())->toBe([]);
});

/**
 * A key is a Compose project name, so an entry another checkout holds is
 * another checkout's containers — refused here as `remove` refuses it.
 */
it('refuses to stop a project registered to another checkout', function () {
    worktreeCreate();

    $registry = registryNow();
    $registry['wt-desk-feat-checkout']['repo'] = $this->root.'/shop';

    file_put_contents($this->home.'/registry.json', json_encode($registry));

    $process = worktreeStop();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('is registered to '.$this->root.'/shop, not to '.$this->main)
        ->and(stopCalls())->toBe([]);
});

it('exits non-zero, naming the project, when Compose would not stop it', function () {
    $this->docker = fakeDockerBinary($this->root, composeExitCode: 1);

    worktreeCreate();

    $process = worktreeStop();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('the Compose stop of wt-desk-feat-checkout exited 1')
        ->toContain('1 of them did not stop: wt-desk-feat-checkout')
        // And the entry is still there: a stop that failed changed nothing.
        ->and(registryNow())->toHaveKey('wt-desk-feat-checkout');
});

/**
 * The rule the orphan warning is already under: silence is not proof. A
 * `compose stop` against a daemon that is not there would exit cleanly, and a
 * clean exit would read as a worktree that gave its memory back.
 */
it('will not call a stop clean when there was no daemon to make it', function () {
    worktreeCreate();

    fakeDockerBinary($this->root, daemon: false);

    $process = worktreeStop();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('there is no Docker daemon answering on this machine, so nothing could be stopped for wt-desk-feat-checkout')
        ->and(stopCalls())->toBe([]);
});

it('makes a stop wait for a create of the same worktree rather than stopping it mid-bootstrap', function () {
    configureRepository(['steps' => [gatedStep()]]);

    $create = startWorktreeCreate();
    waitForOutput($create, '[1/1] Waiting', stderr: true);

    $stop = startWorktreeStop();

    usleep(1_500_000);

    // Sitting on the create's per-worktree lock: stopping a project halfway
    // through the bootstrap that is starting it is precisely the race the lock
    // exists to prevent.
    expect($stop->isRunning())->toBeTrue()
        ->and($stop->getErrorOutput())->not->toContain('stopping the containers');

    touch($this->gate);

    expect($create->wait())->toBe(0, worktreeFailure($create))
        ->and($stop->wait())->toBe(0, worktreeFailure($stop))
        ->and(stopCalls())->toBe(['compose -p wt-desk-feat-checkout stop'])
        // And the worktree the create built is untouched by the stop that
        // followed it.
        ->and($this->worktree.'/.worktree-ready')->toBeFile()
        ->and(registryNow())->toHaveKey('wt-desk-feat-checkout');
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreeStop($arguments);

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree stop <slug> | --all | --all-except <slug> [--all-repos]')
        ->and(stopCalls())->toBe([]);
})->with([
    'no name and no scope' => [[], 'name the worktree to stop'],
    'a name with nothing in it' => [[''], 'name the worktree to stop'],
    'both bulk forms at once' => [['--all', '--all-except'], '--all and --all-except are two different runs'],
    'a name alongside --all' => [['--all', 'feat/checkout'], "'stop --all-except feat/checkout' is the run that keeps that one going"],
    '--all-except with nothing to keep' => [['--all-except'], '--all-except takes the name of the worktree to keep running'],
    '--all-repos on its own' => [['--all-repos'], '--all-repos widens --all and --all-except'],
    'more than a name' => [['feat/checkout', 'extra'], 'stop takes one name; given feat/checkout extra'],
    'an option it does not have' => [['feat/checkout', '--force'], "unknown option '--force'"],
]);

/**
 * The Compose invocations a run made, which is the whole of what `stop` does to
 * the machine.
 *
 * @return list<string>
 */
function stopCalls(): array
{
    return array_values(array_filter(dockerCalls(), fn (string $call): bool => str_ends_with($call, ' stop')));
}
