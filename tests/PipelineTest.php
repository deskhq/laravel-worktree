<?php

use DeskHQ\LaravelWorktree\Bootstrap\Outcome;
use DeskHQ\LaravelWorktree\Bootstrap\Pipeline;
use DeskHQ\LaravelWorktree\Bootstrap\ProcessShell;
use DeskHQ\LaravelWorktree\Bootstrap\Shell;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\TimedOutException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Excludes;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * A shell that runs nothing and remembers everything.
 *
 * Ordering, skipping, sentinels and degradation are decisions this package
 * makes, and every one of them is observable without starting a container —
 * which is the point of the seam.
 */
final class RecordingShell implements Shell
{
    /** @var list<array{0: string, 1: string, 2: int|null}> */
    public array $ran = [];

    /**
     * @param  array<string, int>  $exitCodes  Command line => the exit code to answer with; anything unnamed succeeds.
     * @param  list<string>  $hangs  Command lines that run past their limit, as a real one would — see {@see ProcessRunner}.
     */
    public function __construct(
        private readonly array $exitCodes = [],
        private readonly array $hangs = [],
    ) {}

    public function run(string $commandLine, string $path, ?int $timeout): int
    {
        $this->ran[] = [$commandLine, $path, $timeout];

        if (in_array($commandLine, $this->hangs, true)) {
            throw new TimedOutException($timeout ?? 0, $commandLine);
        }

        return $this->exitCodes[$commandLine] ?? 0;
    }

    /** @return list<string> */
    public function commandLines(): array
    {
        return array_map(fn (array $call): string => $call[0], $this->ran);
    }

    /** @return list<int|null> */
    public function timeouts(): array
    {
        return array_map(fn (array $call): ?int => $call[2], $this->ran);
    }
}

beforeEach(function () {
    $this->path = temporaryRepository();
    file_put_contents($this->path.'/.env', "APP_NAME=Desk\nAPP_KEY=\n");
    $this->identity = new Identity('441', '441-fix-login', 'wt-desk-441-fix-login', '441-fix-login', $this->path);
    $this->diagnostics = fopen('php://memory', 'w+');
});

afterEach(function () {
    deleteDirectory($this->path);
});

it('runs the steps in the order they were declared, on the host and in the container', function () {
    $shell = new RecordingShell;

    runPipeline($shell, [
        ['label' => 'Making room', 'host' => 'mkdir -p resources/js/generated'],
        ['label' => 'Installing', 'sail' => 'composer install'],
        ['label' => 'Installing Chromium', 'sail_root' => 'apt-get install -y chromium'],
    ]);

    expect($shell->commandLines())->toBe([
        'mkdir -p resources/js/generated',
        './vendor/bin/sail composer install',
        "./vendor/bin/sail root-shell -c 'apt-get install -y chromium'",
    ])
        // Every step's working directory is the worktree — including the two
        // that end up inside the container, because the Sail that carries this
        // worktree's ports and Compose project is the one it owns.
        ->and(array_unique(array_map(fn (array $call): string => $call[1], $shell->ran)))->toBe([$this->path])
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain('[1/3] Making room')
        ->toContain('[3/3] Installing Chromium');
});

it('stops at a step that failed, and runs none of the ones after it', function () {
    $shell = new RecordingShell(['./vendor/bin/sail composer install' => 2]);

    expect(fn () => runPipeline($shell, [
        ['host' => 'true'],
        ['label' => 'Installing', 'sail' => 'composer install'],
        ['host' => 'npm ci'],
    ]))->toThrow(WorktreeException::class, 'Installing failed (exit 2)')
        ->and($shell->commandLines())->toBe(['true', './vendor/bin/sail composer install']);

    // And it says how to pick the bootstrap back up, by the name that was typed.
    expect(fn () => runPipeline(new RecordingShell(['true' => 1]), [['host' => 'true']]))
        ->toThrow(WorktreeException::class, "'worktree create 441' picks up where it left off");
});

it('runs every step under a limit, its own where it has one', function () {
    $shell = new RecordingShell;

    runPipeline($shell, [
        ['host' => 'npm ci'],
        ['host' => 'bin/worktree-playwright', 'timeout' => 300],
        // The one way to ask for what every step used to get.
        ['host' => 'bin/restore-the-snapshot', 'timeout' => null],
    ]);

    expect($shell->timeouts())->toBe([Schema::DefaultStepTimeout, 300, null]);
});

it('stops at a step that ran past its limit, and says which limit that was', function () {
    $shell = new RecordingShell(hangs: ['npm ci']);

    expect(fn () => runPipeline($shell, [
        ['label' => 'Installing node modules', 'host' => 'npm ci', 'timeout' => 900],
        ['host' => 'npm run build'],
    ]))
        // Named, with its limit, and distinct from a non-zero exit: a step that
        // never returned and a step that returned 137 are the same outcome and
        // a different problem.
        ->toThrow(WorktreeException::class, 'Installing node modules timed out after 900s')
        ->and(fn () => runPipeline($shell, [['host' => 'npm ci', 'timeout' => 900]]))
        ->toThrow(WorktreeException::class, "'worktree create 441' picks up where it left off")
        ->and($shell->commandLines())->toBe(['npm ci', 'npm ci']);
});

it('degrades a step that ran past its limit exactly as it degrades one that failed', function () {
    $shell = new RecordingShell(hangs: ['bin/worktree-playwright '.$this->path]);

    $outcome = runPipeline($shell, [
        ['label' => 'Browsers', 'host' => 'bin/worktree-playwright {{path}}', 'timeout' => 300,
            'allow_failure' => true, 'degrade' => 'Playwright is not fully installed'],
        ['label' => 'Building assets', 'host' => 'npm run build'],
    ]);

    $outcome->announce(new Output($this->diagnostics));

    expect($outcome->names())->toBe(['Browsers'])
        ->and($shell->commandLines())->toHaveCount(2)
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain('warning: Browsers timed out after 300s, and this step is allowed to fail')
        ->toContain('Playwright is not fully installed');
});

it('skips a sentinel-guarded step, and touches the sentinel only when it worked', function () {
    $shell = new RecordingShell;

    runPipeline($shell, [['label' => 'Seeding', 'sail' => 'artisan migrate:fresh --seed --force', 'sentinel' => '.worktree-seeded']]);

    expect($this->path.'/.worktree-seeded')->toBeFile()
        // A file this package writes into someone's worktree should not show up
        // in their `git status`.
        ->and(ignoredInGit($this->path, '.worktree-seeded'))->toBeTrue();

    runPipeline($shell, [['label' => 'Seeding', 'sail' => 'artisan migrate:fresh --seed --force', 'sentinel' => '.worktree-seeded']]);

    expect($shell->commandLines())->toBe(['./vendor/bin/sail artisan migrate:fresh --seed --force'])
        ->and(diagnosticsIn($this->diagnostics))->toContain('Seeding — skipped, .worktree-seeded is already there');
});

it('leaves no sentinel behind for a step that failed', function () {
    $shell = new RecordingShell(['./vendor/bin/sail composer install' => 1]);

    runPipeline($shell, [['sail' => 'composer install', 'sentinel' => '.worktree-installed', 'allow_failure' => true]]);

    expect($this->path.'/.worktree-installed')->not->toBeFile();
});

it('carries on past a step allowed to fail, and says so as the last thing on screen', function () {
    $shell = new RecordingShell(['bin/worktree-playwright '.$this->path => 1]);

    $outcome = runPipeline($shell, [
        ['label' => 'Browsers', 'host' => 'bin/worktree-playwright {{path}}', 'allow_failure' => true,
            'degrade' => 'Playwright is not fully installed; tests/Browser will not run here'],
        ['label' => 'Building assets', 'host' => 'npm run build'],
    ]);

    $outcome->announce(new Output($this->diagnostics));

    $diagnostics = diagnosticsIn($this->diagnostics);

    expect($shell->commandLines())->toHaveCount(2)
        ->and($outcome->names())->toBe(['Browsers'])
        ->and($outcome->isComplete())->toBeFalse()
        // Last, and after the build it would otherwise have scrolled away
        // behind (the-desk#1005).
        ->and(trim($diagnostics))->toEndWith('Re-entering this worktree retries just those; nothing else runs again.')
        ->and($diagnostics)->toContain('Playwright is not fully installed')
        ->and(strpos($diagnostics, 'Playwright is not fully installed; tests/Browser'))
        ->toBeGreaterThan(strpos($diagnostics, '[2/2] Building assets'));
});

it('retries the steps that degraded on the last run, and nothing else', function () {
    $shell = new RecordingShell;

    $outcome = runPipeline($shell, [
        ['label' => 'Installing', 'sail' => 'composer install'],
        ['label' => 'Browsers', 'host' => 'bin/worktree-playwright', 'allow_failure' => true],
    ], only: ['Browsers']);

    expect($shell->commandLines())->toBe(['bin/worktree-playwright'])
        ->and($outcome->isComplete())->toBeTrue()
        ->and(diagnosticsIn($this->diagnostics))->toContain('retrying 1 step that degraded on an earlier run');
});

it('says when a step recorded as degraded is no longer in the recipe', function () {
    $shell = new RecordingShell;

    $outcome = runPipeline($shell, [['label' => 'Installing', 'sail' => 'composer install']], only: ['Browsers']);

    expect($shell->ran)->toBe([])
        ->and($outcome->isComplete())->toBeTrue()
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain("'Browsers' degraded on an earlier run, but config/worktree.php no longer has a step by that name");
});

it('asks a condition whether the step has anything to do', function (array $step, bool $expected) {
    mkdir($this->path.'/node_modules');

    $shell = new RecordingShell;

    runPipeline($shell, [$step + ['host' => 'work']]);

    expect($shell->ran)->toHaveCount($expected ? 1 : 0);
})->with([
    'missing, and it is not' => [['when' => 'missing:node_modules'], false],
    'missing, and it is' => [['when' => 'missing:vendor'], true],
    'exists, and it does' => [['when' => 'exists:node_modules'], true],
    'exists, and it does not' => [['when' => 'exists:vendor'], false],
    // The original greps `^APP_KEY=$`: the key is there, and blank. That is
    // what a freshly copied .env looks like, and `env_missing` would never fire.
    'env_empty, on a blank key' => [['when' => 'env_empty:APP_KEY'], true],
    'env_empty, on a key with a value' => [['when' => 'env_empty:APP_NAME'], false],
    'env_empty, on a key the file never mentions' => [['when' => 'env_empty:APP_SECRET'], true],
]);

it('asks the sentinel before the condition, because they mean different things', function () {
    touch($this->path.'/.worktree-seeded');

    $shell = new RecordingShell;

    runPipeline($shell, [['label' => 'Seeding', 'sail' => 'artisan db:seed', 'sentinel' => '.worktree-seeded', 'when' => 'missing:vendor']]);

    expect($shell->ran)->toBe([])
        ->and(diagnosticsIn($this->diagnostics))->toContain('.worktree-seeded is already there');
});

it('resolves the placeholders a step names', function () {
    $shell = new RecordingShell;

    runPipeline($shell, [['host' => 'bin/seed {{project}} {{slug}} {{branch}} {{path}} {{port.app}} {{uid}}:{{gid}}']]);

    $expected = sprintf(
        'bin/seed wt-desk-441-fix-login 441-fix-login 441-fix-login %s 20000 %d:%d',
        $this->path,
        posix_getuid(),
        posix_getgid(),
    );

    expect($shell->commandLines())->toBe([$expected]);
});

it('names a placeholder it cannot resolve, and runs nothing at all', function () {
    $shell = new RecordingShell;

    expect(fn () => runPipeline($shell, [
        ['host' => 'true'],
        ['label' => 'Reverb', 'host' => 'bin/reverb {{port.soketi}}'],
    ]))->toThrow(
        WorktreeException::class,
        'config/worktree.php: steps.1.host uses {{port.soketi}}, which is not a port this configuration declares',
    )
        // The whole recipe is resolved before any of it runs, so a typo in the
        // last step does not surface after eleven minutes of Composer.
        ->and($shell->ran)->toBe([]);
});

it('names an unknown placeholder alongside the ones there are', function () {
    expect(fn () => runPipeline(new RecordingShell, [['host' => 'echo {{worktree}}']]))
        ->toThrow(WorktreeException::class, '{{project}}, {{slug}}, {{branch}}, {{path}}, {{uid}}, {{gid}} and {{port.<name>}}');
});

it('refuses to bootstrap a worktree that is not there', function () {
    $identity = new Identity('441', '441-fix-login', 'wt-desk-441-fix-login', '441-fix-login', $this->path.'/nowhere');

    expect(fn () => pipeline(new RecordingShell)->run($identity, slotPorts(), [['host' => 'true']], $this->path.'/.env'))
        ->toThrow(WorktreeException::class, 'there is no worktree at '.$this->path.'/nowhere yet');
});

it('does nothing, loudly or otherwise, for a repository that configures no steps', function () {
    $shell = new RecordingShell;

    expect(runPipeline($shell, [])->isComplete())->toBeTrue()
        ->and($shell->ran)->toBe([])
        ->and(diagnosticsIn($this->diagnostics))->toBe('');
});

/**
 * The one case that starts real subprocesses: a `host` step has to run on the
 * host, in the worktree, and that is not observable through a fake.
 */
it('runs a host step on the host, with the worktree as its working directory', function () {
    $output = new Output($this->diagnostics);
    $runner = new ProcessRunner($output);
    $pipeline = new Pipeline($output, new ProcessShell($runner), new Excludes($runner, $output));

    $outcome = $pipeline->run($this->identity, slotPorts(), [
        ['label' => 'Where am I', 'host' => 'pwd > where.txt'],
        ['label' => 'Who am I', 'host' => 'test "$(id -u)" = "{{uid}}"'],
    ], $this->path.'/.env');

    expect($outcome->isComplete())->toBeTrue()
        ->and(trim((string) file_get_contents($this->path.'/where.txt')))->toBe($this->path);
});

/**
 * The other case that starts a real subprocess, and the reason the limit is
 * worth having at all: a step that would not have come back.
 *
 * No Docker anywhere in it — a `sleep` is every hung `npm ci` this is for, and
 * the assertion is not that the run gave up but that the work stopped. The
 * touch after the sleep is what a process that outlived its kill would still
 * get to do.
 */
it('kills a host step that ran past its limit, and nothing it was doing outlives it', function () {
    $output = new Output($this->diagnostics);
    $runner = new ProcessRunner($output);
    $pipeline = new Pipeline($output, new ProcessShell($runner), new Excludes($runner, $output));

    $started = microtime(true);

    expect(fn () => $pipeline->run($this->identity, slotPorts(), [
        ['label' => 'Sleeping', 'host' => 'sleep 3 && touch survived.txt', 'timeout' => 1],
    ], $this->path.'/.env'))
        ->toThrow(WorktreeException::class, 'Sleeping timed out after 1s; the bootstrap stopped there')
        // Killed at its limit rather than waited out.
        ->and(microtime(true) - $started)->toBeLessThan(3.0);

    // Past the moment the step would have reached its second command, had
    // anything of it still been running to reach it.
    usleep((int) max(0, 4_000_000 - (microtime(true) - $started) * 1_000_000));

    expect($this->path.'/survived.txt')->not->toBeFile();
});

it('lets a failing host step decide the run, exit code and all', function () {
    $output = new Output($this->diagnostics);
    $runner = new ProcessRunner($output);
    $pipeline = new Pipeline($output, new ProcessShell($runner), new Excludes($runner, $output));

    expect(fn () => $pipeline->run($this->identity, slotPorts(), [['label' => 'Failing', 'host' => 'exit 7']], $this->path.'/.env'))
        ->toThrow(WorktreeException::class, 'Failing failed (exit 7)');
});

/**
 * The pipeline as a test drives it: a fake shell, and the diagnostics of the
 * current example.
 *
 * The recipe goes through {@see Schema} on the way in rather than being handed
 * over as written, because that is the only shape the pipeline is ever given in
 * a real run — and it is where a step that named no `timeout` gets one.
 *
 * @param  list<array<string, string|bool|int|null>>  $steps
 * @param  list<string>|null  $only
 */
function runPipeline(RecordingShell $shell, array $steps, ?array $only = null): Outcome
{
    return pipeline($shell)->run(test()->identity, slotPorts(), normalisedSteps($steps), test()->path.'/.env', $only);
}

/**
 * @param  list<array<string, string|bool|int|null>>  $steps
 * @param  array<string, mixed>  $config  Anything else the repository configures — `step_timeout`, usually.
 * @return list<array<string, string|bool|int|null>>
 */
function normalisedSteps(array $steps, array $config = []): array
{
    return Schema::normalise(['steps' => $steps] + $config)['steps'];
}

function pipeline(RecordingShell $shell): Pipeline
{
    $output = new Output(test()->diagnostics);

    return new Pipeline($output, $shell, new Excludes(new ProcessRunner($output), $output));
}
