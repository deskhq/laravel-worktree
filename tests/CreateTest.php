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
    harness('worktree-create');

    $this->main = mainCheckout($this->root.'/desk');
    $this->worktree = $this->root.'/desk-worktrees/feat-checkout';
    $this->gate = $this->root.'/gate';
    $this->base = freePortBase(100);

    configureRepository();
});

afterEach(function () {
    // Whatever a killed run left running is still waiting on this.
    touch($this->gate);

    deleteDirectory($this->root);
});

it('prints the path, and nothing else, on a run that produced megabytes of subprocess output', function () {
    configureRepository([
        'compose' => [
            'keep_services' => ['pgsql'],
            'port_overrides' => ['laravel.test' => ['{{port.vite}}:5173']],
        ],
        'steps' => [
            ['label' => 'Resolving', 'host' => PHP_BINARY.' '.packagePath('tests/Fixtures/noisy-step.php')],
            ['label' => 'Installing', 'sail' => 'composer install', 'sentinel' => '.worktree-installed'],
        ],
    ]);

    $process = worktreeCreate();

    expect($process)->toHaveSucceeded()
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
    configureRepository(['steps' => [countingStep()]]);

    worktreeCreate();

    $docker = recorded($this->root.'/fake/log');

    $process = worktreeCreate();

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and($process->getErrorOutput())->toContain('wt-desk-feat-checkout is ready; re-entering it')
        // Not one more call, and not one more step: moving between worktrees is
        // what this command is for, and it should cost a `git rev-parse`.
        ->and(recorded($this->root.'/fake/log'))->toBe($docker)
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1);
});

it('resumes a bootstrap that was killed mid-pipeline, on the same slot and the same path', function () {
    configureRepository(['steps' => [
        countingStep(sentinel: '.worktree-counted'),
        gatedStep(),
    ]]);

    $interrupted = startWorktreeCreate();
    waitForOutput($interrupted, '[2/2] Waiting', stderr: true);
    $interrupted->signal(SIGTERM);
    $interrupted->wait();

    $claimed = registered();

    expect($interrupted)->toHaveExited(143)
        ->and($interrupted->getOutput())->toBe('')
        ->and($this->worktree.'/.worktree-ready')->not->toBeFile()
        // The locks go; the slot deliberately does not, because that entry is
        // what the next run resumes.
        ->and($this->home.'/locks/wt-desk-feat-checkout.lock')->not->toBeDirectory()
        ->and($this->home.'/registry.lock')->not->toBeDirectory()
        ->and($claimed['path'])->toBe($this->worktree);

    touch($this->gate);

    $resumed = worktreeCreate();

    expect($resumed)->toHaveSucceeded()
        ->and($resumed->getOutput())->toBe($this->worktree."\n")
        ->and(registered()['slot'])->toBe($claimed['slot'])
        // The step that finished before the kill is skipped by its sentinel.
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1)
        ->and($resumed->getErrorOutput())->toContain('.worktree-counted is already there')
        ->and($this->worktree.'/.worktree-ready')->toBeFile();
});

it('makes a second create for the same worktree wait, and then re-enter it', function () {
    configureRepository(['steps' => [countingStep(), gatedStep()]]);

    $first = startWorktreeCreate();
    waitForOutput($first, '[2/2] Waiting', stderr: true);

    $second = startWorktreeCreate();

    usleep(1_500_000);

    // Sitting on the first run's per-worktree lock: nothing of its own is
    // running git, Composer, Sail or npm in that directory alongside it.
    expect($second->getOutput())->toBe('')
        ->and($second->isRunning())->toBeTrue();

    touch($this->gate);

    expect($first->wait())->toBe(0, worktreeFailure($first))
        ->and($second->wait())->toBe(0, worktreeFailure($second))
        ->and($second->getOutput())->toBe($this->worktree."\n")
        ->and($second->getErrorOutput())->toContain('is ready; re-entering it')
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1);
});

it('gives two worktrees created at once distinct slots and distinct ports', function () {
    configureRepository(['steps' => [gatedStep()]]);

    $first = startWorktreeCreate(['feat/checkout', '--json']);
    $second = startWorktreeCreate(['feat/search', '--json']);

    waitForOutput($first, '[1/1] Waiting', stderr: true);
    waitForOutput($second, '[1/1] Waiting', stderr: true);

    touch($this->gate);

    expect($first->wait())->toBe(0, worktreeFailure($first))
        ->and($second->wait())->toBe(0, worktreeFailure($second));

    $entries = [emitted($first), emitted($second)];

    expect($entries[0]['slot'])->not->toBe($entries[1]['slot'])
        ->and(array_intersect(array_values($entries[0]['ports']), array_values($entries[1]['ports'])))->toBe([])
        ->and($entries[0]['path'])->toBe($this->worktree)
        ->and($entries[1]['path'])->toBe($this->root.'/desk-worktrees/feat-search');
});

it('runs the whole recipe again on a ready worktree when it is refreshed', function () {
    configureRepository(['steps' => [
        countingStep(),
        ['label' => 'Installing', 'sail' => 'composer install', 'sentinel' => '.worktree-installed'],
    ]]);

    worktreeCreate();

    $process = worktreeCreate(['feat/checkout', '--refresh']);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and($process->getErrorOutput())->not->toContain('is ready; re-entering it')
        // The recipe runs, sentinels and all: `--refresh` re-runs the pipeline,
        // it does not throw away what a step recorded as done once and for all
        // — dropping those would re-run `migrate:fresh --seed` over real data.
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(2)
        ->and(recorded($this->worktree.'/sail.log'))->toBe(['up -d laravel.test', 'composer install', 'up -d laravel.test']);
});

it('re-creates a registry entry whose worktree somebody deleted by hand', function () {
    configureRepository(['steps' => [countingStep()]]);

    worktreeCreate();

    $claimed = registered();

    deleteDirectory($this->worktree);

    $process = worktreeCreate();

    expect($process)->toHaveSucceeded()
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
    configureRepository(['steps' => [
        countingStep(),
        ['label' => 'Browsers', 'host' => 'test -f {{path}}/browsers', 'allow_failure' => true,
            'degrade' => 'Playwright is not fully installed; tests/Browser will not run here'],
    ]]);

    $process = worktreeCreate();
    $diagnostics = $process->getErrorOutput();

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe($this->worktree."\n")
        ->and(trim($diagnostics))->toEndWith('Re-entering this worktree retries just those; nothing else runs again.')
        ->and($diagnostics)->toContain('Playwright is not fully installed')
        // Recorded, so the next run knows what to try again (and nothing else).
        ->and(registered()['degraded'])->toBe(['Browsers']);

    touch($this->worktree.'/browsers');

    $retried = worktreeCreate();

    expect($retried)->toHaveSucceeded()
        ->and($retried->getErrorOutput())->toContain('retrying 1 step that degraded on an earlier run')
        ->and(recorded($this->worktree.'/runs.log'))->toHaveCount(1)
        ->and(registered())->not->toHaveKey('degraded');
});

it('emits the whole registry entry instead of the path when asked for JSON', function () {
    $entry = emitted(worktreeCreate(['feat/checkout', '--json']));

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
        ->and(substr_count(worktreeCreate(['feat/checkout', '--json'])->getOutput(), "\n"))->toBe(1);
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreeCreate($arguments);

    expect($process)->toHaveExited(64)
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

/**
 * The pre-flight, from the outside: the whole point of it is that it costs
 * nothing and happens before anything is claimed, generated or attached — a
 * refusal after `git worktree add` would leave a directory and a slot behind to
 * be cleaned up by hand.
 */
it('refuses a create whose started services publish a host port nothing offsets', function () {
    file_put_contents($this->main.'/compose.yaml', <<<'YAML'
        services:
            laravel.test:
                ports:
                    - '${APP_PORT:-80}:80'
                depends_on:
                    - redis
            redis:
                ports:
                    - '${FORWARD_REDIS_PORT:-6379}:6379'
        YAML);

    $process = worktreeCreate();

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("redis publishes '\${FORWARD_REDIS_PORT:-6379}:6379'")
        ->toContain('started because laravel.test depends on it, and it is the app service')
        ->toContain("add 'FORWARD_REDIS_PORT' => '{{port.redis}}' to 'env'")
        // Nothing claimed, nothing attached, nothing generated.
        ->and($this->home.'/registry.json')->not->toBeFile()
        ->and($this->worktree)->not->toBeDirectory();

    configureRepository(['env' => [
        'APP_PORT' => '{{port.app}}',
        'COMPOSE_PROJECT_NAME' => '{{project}}',
        'FORWARD_REDIS_PORT' => '{{port.redis}}',
    ]]);

    expect(worktreeCreate())->toHaveSucceeded();
});

it('refuses to work in a worktree somebody has switched branches in', function () {
    worktreeCreate();

    runGit($this->worktree, 'checkout', '--quiet', '-b', 'something-else');

    $process = worktreeCreate();

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain("is on 'something-else', expected 'feat/checkout'")
        ->toContain('commits would land on the wrong branch');
});

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
