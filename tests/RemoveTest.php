<?php

use Symfony\Component\Process\Process;

/**
 * `remove`, end to end through the real binary, against a worktree a real
 * `create` made: real git, a real registry on disk, real locks, and the suite's
 * fake `docker`.
 *
 * The cases below are the contract: the machine goes back, the branch does not,
 * a registry entry is a convenience rather than a precondition, and a teardown
 * that left volumes behind says so with an exit code rather than a shrug
 * (the-desk#1095).
 */
beforeEach(function () {
    $this->root = temporaryDirectory('worktree-remove');
    $this->home = $this->root.'/home';
    $this->main = $this->root.'/desk';
    $this->worktree = $this->root.'/desk-worktrees/feat-checkout';
    $this->gate = $this->root.'/gate';
    $this->base = freePortBase(100);
    $this->docker = fakeDockerBinary($this->root);

    mkdir($this->main, 0755, true);

    runGit($this->main, 'init', '--quiet', '--initial-branch=main', '.');
    file_put_contents($this->main.'/compose.yaml', "services:\n  laravel.test:\n    image: laravel\n");
    runGit($this->main, 'add', '-A');
    runGit($this->main, 'commit', '--quiet', '-m', 'initial');
    file_put_contents($this->main.'/.env', "APP_NAME=Desk\nAPP_KEY=\n");

    configureRepository();
});

afterEach(function () {
    // Whatever a held run is still waiting on.
    touch($this->gate);

    deleteDirectory($this->root);
});

it('takes the project, the directory and the slot, and leaves the branch', function () {
    $this->docker = fakeDockerBinary($this->root, containers: ['c1'], volumes: ['wt-desk-feat-checkout_sail-pgsql']);

    worktreeCreate();

    $process = worktreeRemove();

    expect($process->getExitCode())->toBe(0)
        // Nothing machine-readable happens here: the exit code is the answer.
        ->and($process->getOutput())->toBe('')
        ->and(dockerCalls())
        ->toContain('compose -p wt-desk-feat-checkout down --volumes --remove-orphans')
        ->toContain('rm --force --volumes c1')
        ->toContain('volume rm --force wt-desk-feat-checkout_sail-pgsql')
        ->and($this->worktree)->not->toBeDirectory()
        ->and(registryNow())->toBe([])
        // The work is the point and the infrastructure is disposable, so the
        // one thing a person cannot get back is the one thing left alone.
        ->and(branchesOfMain())->toContain('feat/checkout')
        ->and($process->getErrorOutput())
        ->toContain('removed wt-desk-feat-checkout, and slot 0 is free again')
        ->toContain("the branch 'feat/checkout' is untouched");
});

/**
 * The registry is a convenience, not the source of truth. Refusing without an
 * entry left a worktree removed by hand — or one whose entry a *failed*
 * teardown had already deleted — with no supported way to reclaim its volumes
 * at all (the-desk#1095).
 */
it('works with no registry entry, deriving both facts from the name and saying so', function () {
    $this->docker = fakeDockerBinary($this->root, volumes: ['wt-desk-feat-checkout_sail-pgsql']);

    worktreeCreate();
    forgetTheRegistry();

    $process = worktreeRemove();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())
        ->toContain('nothing in the registry holds wt-desk-feat-checkout, so this run goes by the name it was given')
        ->toContain('the worktree at '.$this->worktree)
        // The project name is the derivable half that matters: it is what the
        // volumes carry, and reclaiming them is the whole point of proceeding.
        ->and(dockerCalls())
        ->toContain('compose -p wt-desk-feat-checkout down --volumes --remove-orphans')
        ->toContain('volume rm --force wt-desk-feat-checkout_sail-pgsql')
        ->and($this->worktree)->not->toBeDirectory()
        ->and(branchesOfMain())->toContain('feat/checkout');
});

/**
 * The other derivable half, when this configuration no longer derives it: a
 * `repo_slug` set since the worktree was created moves every name, and the
 * directory is still where it was made.
 */
it('globs the sibling worktrees directories for a directory the derived path no longer names', function () {
    worktreeCreate();
    forgetTheRegistry();

    configureRepository(['repo_slug' => 'the-desk']);

    $process = worktreeRemove();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())
        ->toContain('there is no worktree at '.$this->root.'/the-desk-worktrees/feat-checkout')
        ->toContain("git has one of this repository's at ".$this->worktree)
        ->and($this->worktree)->not->toBeDirectory()
        ->and(branchesOfMain())->toContain('feat/checkout');
});

/**
 * Step 6 of the order, and the reason the rest of it exists: the git side has
 * genuinely finished either way, so the slot really is free — what failed is
 * the disk, and saying nothing about it is how volumes survive until the disk
 * fills with nothing left pointing at them.
 */
it('exits non-zero over what survived the teardown, and frees the slot regardless', function () {
    $this->docker = fakeDockerBinary(
        $this->root,
        volumes: ['wt-desk-feat-checkout_sail-pgsql'],
        refuses: ['wt-desk-feat-checkout_sail-pgsql'],
    );

    worktreeCreate();

    $process = worktreeRemove();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('wt-desk-feat-checkout survived teardown')
        ->toContain('1 volume (wt-desk-feat-checkout_sail-pgsql)')
        ->toContain("'worktree reap' is the way back to them")
        ->and($this->worktree)->not->toBeDirectory()
        ->and(registryNow())->toBe([]);

    // And the slot is not merely unclaimed but usable: the next create takes it.
    expect(worktreeCreate()->getExitCode())->toBe(0)
        ->and(registryNow()['wt-desk-feat-checkout']['slot'])->toBe(0);
});

/**
 * The same non-zero exit for the case that proves nothing rather than failing:
 * with no daemon to ask, the sweep removes nothing and a re-query agrees, and
 * calling that a clean teardown is the shape of the-desk#1095.
 */
it('will not call a removal clean when there was no daemon to confirm it', function () {
    worktreeCreate();

    fakeDockerBinary($this->root, volumes: ['wt-desk-feat-checkout_sail-pgsql'], daemon: false);

    $process = worktreeRemove();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())
        ->toContain('could not confirm that wt-desk-feat-checkout is gone')
        ->toContain("'worktree reap' is the way back to them")
        // The git side finished all the same, so the slot is genuinely free.
        ->and($this->worktree)->not->toBeDirectory()
        ->and(registryNow())->toBe([])
        ->and(branchesOfMain())->toContain('feat/checkout');
});

it('does not fail over a worktree directory somebody already deleted by hand', function () {
    worktreeCreate();

    deleteDirectory($this->worktree);

    $process = worktreeRemove();

    expect($process->getExitCode())->toBe(0)
        ->and(registryNow())->toBe([])
        ->and(branchesOfMain())->toContain('feat/checkout')
        // git's record went with it, or the next `git worktree add` would be
        // refused the path as missing but already registered.
        ->and(worktreesGitKnows())->toBe([$this->main]);
});

it('makes a remove wait for a create of the same worktree rather than interleaving with it', function () {
    configureRepository(['steps' => [waitingStep()]]);

    $create = startWorktreeCreate();
    waitForOutput($create, '[1/1] Waiting', stderr: true);

    $remove = startWorktreeRemove();

    usleep(1_500_000);

    // Sitting on the create's per-worktree lock: it cannot tear down containers
    // the create is still starting, and the create cannot slot a replacement
    // entry in behind a teardown that has already run.
    expect($remove->isRunning())->toBeTrue()
        ->and($remove->getErrorOutput())->not->toContain('tearing down');

    touch($this->gate);

    expect($create->wait())->toBe(0)
        ->and($remove->wait())->toBe(0)
        ->and(registryNow())->toBe([])
        ->and($this->worktree)->not->toBeDirectory();
});

/**
 * A key is a Compose project name, so an entry another checkout holds is
 * another checkout's containers and volumes — the collision the allocator
 * refuses on the way in, refused here rather than destroyed.
 */
it('refuses to remove a project registered to another checkout', function () {
    worktreeCreate();

    $registry = registryNow();
    $registry['wt-desk-feat-checkout']['repo'] = $this->root.'/shop';

    file_put_contents($this->home.'/registry.json', json_encode($registry));

    $process = worktreeRemove();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())
        ->toContain('is registered to '.$this->root.'/shop, not to '.$this->main)
        // And nothing was torn down on the way to refusing.
        ->and($this->worktree)->toBeDirectory()
        ->and(registryNow())->toHaveKey('wt-desk-feat-checkout');
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreeRemove($arguments);

    expect($process->getExitCode())->toBe(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree remove <slug>');
})->with([
    'no name at all' => [[], 'name the worktree to remove'],
    'a name with nothing in it' => [[''], 'name the worktree to remove'],
    'more than a name' => [['feat/checkout', 'extra'], 'remove takes one name; given feat/checkout extra'],
    'an option it does not have' => [['feat/checkout', '--force'], "this command takes no options, and '--force' is one"],
]);

/**
 * The repository's `config/worktree.php`, as these cases need it.
 *
 * @param  array<string, mixed>  $config
 */
function configureRepository(array $config = []): void
{
    $config = array_replace([
        'slots' => 5,
        // A window this machine has free right now, so a real service on the
        // developer's laptop cannot decide which slot the test gets.
        'port_base' => test()->base,
        'env' => [
            'APP_PORT' => '{{port.app}}',
            'COMPOSE_PROJECT_NAME' => '{{project}}',
        ],
        'steps' => [],
    ], $config);

    is_dir(test()->main.'/config') || mkdir(test()->main.'/config', 0755, true);

    file_put_contents(test()->main.'/config/worktree.php', '<?php return '.var_export($config, true).";\n");
}

/**
 * A step that sits in the pipeline until the example lets it go — a stand-in
 * for the minutes of Composer, npm and image pulls a `remove` would otherwise
 * be free to run straight through the middle of.
 *
 * @return array<string, string>
 */
function waitingStep(): array
{
    return ['label' => 'Waiting', 'host' => 'until [ -f '.test()->gate.' ]; do sleep 0.1; done'];
}

/**
 * @param  list<string>  $arguments
 */
function worktreeRemove(array $arguments = ['feat/checkout']): Process
{
    $process = startWorktreeRemove($arguments);
    $process->wait();

    return $process;
}

/**
 * @param  list<string>  $arguments
 */
function startWorktreeRemove(array $arguments = ['feat/checkout']): Process
{
    return startWorktreeBinary('remove', $arguments);
}

/**
 * The worktree these cases remove, made the way a person makes one.
 *
 * @param  list<string>  $arguments
 */
function worktreeCreate(array $arguments = ['feat/checkout']): Process
{
    $process = startWorktreeCreate($arguments);
    $process->wait();

    return $process;
}

/**
 * @param  list<string>  $arguments
 */
function startWorktreeCreate(array $arguments = ['feat/checkout']): Process
{
    return startWorktreeBinary('create', $arguments);
}

/**
 * @param  list<string>  $arguments
 */
function startWorktreeBinary(string $command, array $arguments): Process
{
    $process = new Process(
        [PHP_BINARY, packagePath('bin/worktree'), $command, ...$arguments],
        test()->main,
        [
            'WORKTREE_HOME' => test()->home,
            'SAIL_DOCKER_BINARY' => test()->docker,
            // See CreateTest: Testbench exports APP_ENV=testing, and both
            // `bin/sail` and Laravel then prefer `.env.testing`.
            'APP_ENV' => false,
        ],
    );

    $process->setTimeout(120);
    $process->start();

    return $process;
}

/**
 * The registry as this machine now holds it.
 *
 * @return array<string, mixed>
 */
function registryNow(): array
{
    $registry = test()->home.'/registry.json';

    return is_file($registry)
        ? json_decode((string) file_get_contents($registry), true, flags: JSON_THROW_ON_ERROR)
        : [];
}

/**
 * The registry file this machine lost, or that a failed teardown emptied.
 */
function forgetTheRegistry(): void
{
    file_put_contents(test()->home.'/registry.json', "{}\n");
}

/**
 * @return list<string>
 */
function branchesOfMain(): array
{
    $branches = explode("\n", runGit(test()->main, 'branch', '--format=%(refname:short)')->getOutput());

    return array_values(array_filter(array_map(trim(...), $branches), fn (string $branch): bool => $branch !== ''));
}

/**
 * The directories git still records as worktrees of the main checkout.
 *
 * @return list<string>
 */
function worktreesGitKnows(): array
{
    $listing = explode("\n", runGit(test()->main, 'worktree', 'list', '--porcelain')->getOutput());
    $paths = [];

    foreach ($listing as $line) {
        if (str_starts_with(trim($line), 'worktree ')) {
            $paths[] = substr(trim($line), strlen('worktree '));
        }
    }

    return $paths;
}

/**
 * @return list<string>
 */
function dockerCalls(): array
{
    return recorded(test()->root.'/fake/log');
}
