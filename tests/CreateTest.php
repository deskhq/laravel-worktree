<?php

use Symfony\Component\Process\Process;

/**
 * `create`, end to end through the real binary: real git, a real registry, real
 * locks, real subprocesses — driven against the fake `docker` and the fake
 * `vendor/bin/sail` it installs, because none of what this command decides
 * needs a daemon to be observable.
 *
 * The cases below are the contract: the path alone on stdout, three entry
 * states, and what two runs do to each other.
 */
beforeEach(function () {
    $this->root = temporaryDirectory('worktree-create');
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

    // Written after the commit, because an application's `.env` is not tracked
    // — and a worktree that got one from git would keep it, generating nothing.
    file_put_contents($this->main.'/.env', "APP_NAME=Desk\nAPP_KEY=\n");

    configure();
});

afterEach(function () {
    // Whatever a killed run left running is still waiting on this.
    touch($this->gate);

    deleteDirectory($this->root);
});

it('prints the path, and nothing else, on a run that produced megabytes of subprocess output', function () {
    configure([
        'compose' => [
            'keep_services' => ['pgsql'],
            'port_overrides' => ['laravel.test' => ['{{port.vite}}:5173']],
        ],
        'steps' => [
            ['label' => 'Resolving', 'host' => PHP_BINARY.' '.packagePath('tests/Fixtures/noisy-step.php')],
            ['label' => 'Installing', 'sail' => 'composer install', 'sentinel' => '.worktree-installed'],
        ],
    ]);

    $process = create();

    expect($process->getExitCode())->toBe(0)
        // The whole of stdout, after five hundred lines a step wrote to its own.
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and($process->getErrorOutput())->toContain('- resolving package 499')
        // On its own branch, forked from the checkout's.
        // The branch keeps the slash the user typed; the directory cannot.
        ->and(branchOf($this->worktree))->toBe('feat/checkout')
        ->and(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->main, 'main'))
        // With its own environment, its own overlay, and the steps run in it.
        ->and(file_get_contents($this->worktree.'/.env'))
        ->toContain('APP_PORT='.$this->base)
        ->toContain('COMPOSE_PROJECT_NAME=wt-desk-feat-checkout')
        ->toContain('SAIL_FILES=compose.yaml:compose.worktree.yaml')
        ->and($this->worktree.'/compose.worktree.yaml')->toBeFile()
        ->and(recorded($this->worktree.'/sail.log'))->toBe(['up -d laravel.test', 'composer install'])
        // And the marker that makes the next run a re-entry, kept out of the
        // git status of the person about to work in there.
        ->and($this->worktree.'/.worktree-ready')->toBeFile()
        ->and(ignoredInGit($this->worktree, '.worktree-ready'))->toBeTrue()
        ->and(registered())->toMatchArray(['slot' => 0, 'path' => $this->worktree, 'branch' => 'feat/checkout']);
});

it('re-enters a ready worktree without a single Docker call', function () {
    configure(['steps' => [countingStep()]]);

    create();

    $docker = recorded($this->root.'/fake/log');

    $process = create();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and($process->getErrorOutput())->toContain('wt-desk-feat-checkout is ready; re-entering it')
        // Not one more call, and not one more step: moving between worktrees is
        // what this command is for, and it should cost a `git rev-parse`.
        ->and(recorded($this->root.'/fake/log'))->toBe($docker)
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1);
});

it('resumes a bootstrap that was killed mid-pipeline, on the same slot and the same path', function () {
    configure(['steps' => [
        countingStep(sentinel: '.worktree-counted'),
        gatedStep(),
    ]]);

    $interrupted = startCreate();
    waitForOutput($interrupted, '[2/2] Waiting', stderr: true);
    $interrupted->signal(SIGTERM);
    $interrupted->wait();

    $claimed = registered();

    expect($interrupted->getExitCode())->toBe(143)
        ->and($interrupted->getOutput())->toBe('')
        ->and($this->worktree.'/.worktree-ready')->not->toBeFile()
        // The locks go; the slot deliberately does not, because that entry is
        // what the next run resumes.
        ->and($this->home.'/locks/wt-desk-feat-checkout.lock')->not->toBeDirectory()
        ->and($this->home.'/registry.lock')->not->toBeDirectory()
        ->and($claimed['path'])->toBe($this->worktree);

    touch($this->gate);

    $resumed = create();

    expect($resumed->getExitCode())->toBe(0)
        ->and($resumed->getOutput())->toBe($this->worktree."\n")
        ->and(registered()['slot'])->toBe($claimed['slot'])
        // The step that finished before the kill is skipped by its sentinel.
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1)
        ->and($resumed->getErrorOutput())->toContain('.worktree-counted is already there')
        ->and($this->worktree.'/.worktree-ready')->toBeFile();
});

it('makes a second create for the same worktree wait, and then re-enter it', function () {
    configure(['steps' => [countingStep(), gatedStep()]]);

    $first = startCreate();
    waitForOutput($first, '[2/2] Waiting', stderr: true);

    $second = startCreate();

    usleep(1_500_000);

    // Sitting on the first run's per-worktree lock: nothing of its own is
    // running git, Composer, Sail or npm in that directory alongside it.
    expect($second->getOutput())->toBe('')
        ->and($second->isRunning())->toBeTrue();

    touch($this->gate);

    expect($first->wait())->toBe(0)
        ->and($second->wait())->toBe(0)
        ->and($second->getOutput())->toBe($this->worktree."\n")
        ->and($second->getErrorOutput())->toContain('is ready; re-entering it')
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1);
});

it('gives two worktrees created at once distinct slots and distinct ports', function () {
    configure(['steps' => [gatedStep()]]);

    $first = startCreate(['feat/checkout', '--json']);
    $second = startCreate(['feat/search', '--json']);

    waitForOutput($first, '[1/1] Waiting', stderr: true);
    waitForOutput($second, '[1/1] Waiting', stderr: true);

    touch($this->gate);

    expect($first->wait())->toBe(0)
        ->and($second->wait())->toBe(0);

    $entries = [emitted($first), emitted($second)];

    expect($entries[0]['slot'])->not->toBe($entries[1]['slot'])
        ->and(array_intersect(array_values($entries[0]['ports']), array_values($entries[1]['ports'])))->toBe([])
        ->and($entries[0]['path'])->toBe($this->worktree)
        ->and($entries[1]['path'])->toBe($this->root.'/desk-worktrees/feat-search');
});

it('runs the whole recipe again on a ready worktree when it is refreshed', function () {
    configure(['steps' => [
        countingStep(),
        ['label' => 'Installing', 'sail' => 'composer install', 'sentinel' => '.worktree-installed'],
    ]]);

    create();

    $process = create(['feat/checkout', '--refresh']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and($process->getErrorOutput())->not->toContain('is ready; re-entering it')
        // The recipe runs, sentinels and all: `--refresh` re-runs the pipeline,
        // it does not throw away what a step recorded as done once and for all
        // — dropping those would re-run `migrate:fresh --seed` over real data.
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(2)
        ->and(recorded($this->worktree.'/sail.log'))->toBe(['up -d laravel.test', 'composer install', 'up -d laravel.test']);
});

it('re-creates a registry entry whose worktree somebody deleted by hand', function () {
    configure(['steps' => [countingStep()]]);

    create();

    $claimed = registered();

    deleteDirectory($this->worktree);

    $process = create();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and($process->getErrorOutput())
        ->toContain('but there is no directory there any more')
        // git's own record of the worktree went too, or `git worktree add`
        // would have refused the path as missing but already registered.
        ->toContain('clearing that record')
        ->and($this->worktree.'/.worktree-ready')->toBeFile()
        ->and(branchOf($this->worktree))->toBe('feat/checkout')
        ->and(registered())->toMatchArray(['slot' => $claimed['slot'], 'path' => $this->worktree]);
});

it('prints a degrade notice after the path, records the step, and retries only that step', function () {
    configure(['steps' => [
        countingStep(),
        ['label' => 'Browsers', 'host' => 'test -f {{path}}/browsers', 'allow_failure' => true,
            'degrade' => 'Playwright is not fully installed; tests/Browser will not run here'],
    ]]);

    $process = create();
    $diagnostics = $process->getErrorOutput();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and(trim($diagnostics))->toEndWith('Re-entering this worktree retries just those; nothing else runs again.')
        ->and($diagnostics)->toContain('Playwright is not fully installed')
        // Recorded, so the next run knows what to try again (and nothing else).
        ->and(registered()['degraded'])->toBe(['Browsers']);

    touch($this->worktree.'/browsers');

    $retried = create();

    expect($retried->getExitCode())->toBe(0)
        ->and($retried->getErrorOutput())->toContain('retrying 1 step that degraded on an earlier run')
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1)
        ->and(registered())->not->toHaveKey('degraded');
});

it('emits the whole registry entry instead of the path when asked for JSON', function () {
    $entry = emitted(create(['feat/checkout', '--json']));

    expect($entry)->toMatchArray([
        'project' => 'wt-desk-feat-checkout',
        'slot' => 0,
        'repo' => $this->main,
        'slug' => 'feat-checkout',
        // The ref the user typed, kept verbatim: slashes are legal there and
        // in neither a directory name nor a Compose project name.
        'branch' => 'feat/checkout',
        'path' => $this->worktree,
        'degraded' => [],
    ])
        ->and($entry['ports']['app'])->toBe($this->base)
        // One line, so it composes with `jq` and with `read` alike.
        ->and(substr_count(create(['feat/checkout', '--json'])->getOutput(), "\n"))->toBe(1);
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = create($arguments);

    expect($process->getExitCode())->toBe(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree create <slug> [base] [--refresh] [--json]');
})->with([
    'no name at all' => [[], 'name the worktree'],
    'a name with nothing in it' => [[''], 'name the worktree'],
    'an option it does not have' => [['feat/checkout', '--refesh'], "unknown option '--refesh'; this command takes --refresh, --json"],
    'more than a name and a base' => [['feat/checkout', 'main', 'extra'], 'create takes a name and, at most, a base to fork from'],
]);

it('refuses to work in a worktree somebody has switched branches in', function () {
    create();

    runGit($this->worktree, 'checkout', '--quiet', '-b', 'something-else');

    $process = create();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("is on 'something-else', expected 'feat/checkout'")
        ->toContain('commits would land on the wrong branch');
});

/**
 * The repository's `config/worktree.php`, as this example needs it.
 *
 * Written rather than published, because what a repository configures is what
 * `create` has to wire together — the ports its `.env` gets, the overlay its
 * services need, and the recipe it runs.
 *
 * @param  array<string, mixed>  $config
 */
function configure(array $config = []): void
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
 * A step that leaves one line behind every time it runs, which is how these
 * cases tell a re-entry from a bootstrap.
 *
 * @return array<string, string|bool>
 */
function countingStep(?string $sentinel = null): array
{
    $step = ['label' => 'Counting', 'host' => 'echo ran >> {{path}}/runs.log'];

    return $sentinel === null ? $step : $step + ['sentinel' => $sentinel];
}

/**
 * A step that sits in the middle of the pipeline until the example lets it go —
 * a stand-in for the minutes of Composer, npm and image pulls that a second run
 * has to wait out, or that a signal arrives in the middle of.
 *
 * @return array<string, string>
 */
function gatedStep(): array
{
    return ['label' => 'Waiting', 'host' => 'until [ -f '.test()->gate.' ]; do sleep 0.1; done'];
}

/**
 * A finished `create`.
 *
 * @param  list<string>  $arguments
 */
function create(array $arguments = ['feat/checkout']): Process
{
    $process = startCreate($arguments);
    $process->wait();

    return $process;
}

/**
 * A started one, for the cases that look at a run while it is still working.
 *
 * @param  list<string>  $arguments
 */
function startCreate(array $arguments = ['feat/checkout']): Process
{
    $process = new Process(
        [PHP_BINARY, packagePath('bin/worktree'), 'create', ...$arguments],
        test()->main,
        [
            'WORKTREE_HOME' => test()->home,
            'SAIL_DOCKER_BINARY' => test()->docker,
            // Unset, because this suite runs under Testbench, which exports
            // APP_ENV=testing — and both `bin/sail` and Laravel then read
            // `.env.testing` in preference to `.env`. Which file that lands in
            // is EnvFileTest's question; these cases are about the ordinary one.
            'APP_ENV' => false,
        ],
    );

    // Generous, because the runs below are deliberately held mid-pipeline and a
    // loaded machine should not turn that into a failure. The binary itself
    // never times a bootstrap out: Composer, npm and image pulls take minutes.
    $process->setTimeout(120);
    $process->start();

    return $process;
}

/**
 * What `--json` put on stdout.
 *
 * @return array<string, mixed>
 */
function emitted(Process $process): array
{
    return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * One worktree's entry, as the registry on disk holds it.
 *
 * @return array<string, mixed>
 */
function registered(string $key = 'wt-desk-feat-checkout'): array
{
    $registry = json_decode((string) file_get_contents(test()->home.'/registry.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($registry)->toHaveKey($key);

    return $registry[$key];
}

function branchOf(string $path): string
{
    return trim(runGit($path, 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput());
}
