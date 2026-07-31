<?php

use Symfony\Component\Process\Process;

/**
 * `list`, end to end through the real binary: a real repository, a real registry
 * on disk, and the fake `docker` the suite runs against — because the two
 * things this command decides are which entries belong to this checkout and
 * which projects on the daemon nothing claims, and neither needs a daemon to be
 * observable.
 *
 * The stream split is the contract under all of it: the table is stdout, every
 * word about orphans is stderr, and `worktree list | wc -l` counts rows.
 */
beforeEach(function () {
    $this->root = temporaryDirectory('worktree-list');
    $this->home = $this->root.'/home';
    $this->main = $this->root.'/desk';
    $this->shop = $this->root.'/shop';
    $this->docker = fakeDockerBinary($this->root);

    mkdir($this->main, 0755, true);
    mkdir($this->home, 0755, true);

    runGit($this->main, 'init', '--quiet', '--initial-branch=main', '.');
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('prints one row per worktree on stdout, in slot order, and nothing else', function () {
    registryHolds([
        'wt-desk-feat-search' => slotEntry(3, 'feat-search', branch: 'feat/search'),
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
    ]);

    $process = worktreeList();

    expect($process->getExitCode())->toBe(0)
        ->and(columnsOf($process))->toBe([
            ['KEY', 'SLOT', 'APP', 'VITE', 'REVERB', 'DB', 'REDIS', 'BRANCH', 'PATH'],
            ['wt-desk-441-fix-login', '0', '20000', '20001', '20002', '20003', '20004', '441-fix-login', $this->root.'/desk-worktrees/441-fix-login'],
            ['wt-desk-feat-search', '3', '20030', '20031', '20032', '20033', '20034', 'feat/search', $this->root.'/desk-worktrees/feat-search'],
        ])
        // Aligned where `column` can align it, which is what makes the table
        // readable without making it any less parseable.
        ->and(rowsOf($process)[0])->toBe('KEY                    SLOT  APP    VITE   REVERB  DB     REDIS  BRANCH         PATH')
        ->and($process->getErrorOutput())->toBe('');
});

it('takes its port columns from the configuration rather than from a list of its own', function () {
    mkdir($this->main.'/config', 0755, true);
    file_put_contents(
        $this->main.'/config/worktree.php',
        '<?php return '.var_export(['ports' => ['app', 'meilisearch']], true).";\n",
    );

    registryHolds(['wt-desk-441-fix-login' => slotEntry(1, '441-fix-login', ports: ['app' => 20010, 'meilisearch' => 20011])]);

    expect(columnsOf(worktreeList())[0])->toBe(['KEY', 'SLOT', 'APP', 'MEILISEARCH', 'BRANCH', 'PATH']);
});

it('shows this repository by default and the whole machine when asked', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->shop),
    ]);

    expect(keysListed(worktreeList()))->toBe(['wt-desk-441-fix-login'])
        ->and(keysListed(worktreeList(['--all'])))->toBe(['wt-desk-441-fix-login', 'wt-shop-feat-checkout']);
});

it('says that nothing holds a slot rather than printing an empty table', function () {
    $process = worktreeList();

    expect($process->getExitCode())->toBe(0)
        // Not a header with no rows under it, and not an error: an empty
        // registry is the ordinary state of a repository nobody has started on.
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain("no worktree of desk holds a slot; create one with 'worktree create <slug>'");
});

it('emits the registry entries as one line of JSON, empty registry included', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreeList(['--json']);

    expect($process->getExitCode())->toBe(0)
        ->and(substr_count($process->getOutput(), "\n"))->toBe(1)
        ->and(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR))->toBe([[
            'project' => 'wt-desk-441-fix-login',
            'slot' => 0,
            'repo' => $this->main,
            'slug' => '441-fix-login',
            'branch' => '441-fix-login',
            'path' => $this->root.'/desk-worktrees/441-fix-login',
            'ports' => ['app' => 20000, 'vite' => 20001, 'reverb' => 20002, 'db' => 20003, 'redis' => 20004],
            'created_at' => '2026-01-01T00:00:00Z',
            'degraded' => [],
        ]]);

    registryHolds([]);

    // A script that asked for JSON is parsing this, and an empty array is an
    // answer it can parse; the diagnostic still goes where diagnostics go.
    expect(trim(worktreeList(['--json'])->getOutput()))->toBe('[]');
});

it('warns about orphaned projects on stderr, keeping stdout to the rows', function () {
    $this->docker = fakeDockerBinary($this->root, projects: [
        'wt-desk-441-fix-login' => ['containers' => 4, 'volumes' => 3],
        'wt-desk-feat-checkout' => ['volumes' => 3],
        'wt-desk-feat-search' => ['containers' => 1],
    ]);

    registryHolds(['wt-desk-feat-search' => slotEntry(0, 'feat-search')]);

    $process = worktreeList();

    expect($process->getExitCode())->toBe(0)
        // A header and the one worktree that does hold a slot: the warning is
        // nowhere near stdout, so `list | wc -l` still counts rows.
        ->and(rowsOf($process))->toHaveCount(2)
        ->and($process->getErrorOutput())
        ->toContain('2 projects of desk still on this daemon that no worktree claims:')
        ->toContain('wt-desk-441-fix-login  4 containers, 3 volumes')
        ->toContain('wt-desk-feat-checkout  0 containers, 3 volumes')
        ->toContain("'worktree reap' removes them")
        // Registered, so not an orphan, however much of the daemon it is using.
        ->not->toContain('wt-desk-feat-search');
});

it('scopes the warning the way it scopes the table, and never past the wt- marker', function () {
    $this->docker = fakeDockerBinary($this->root, projects: [
        'wt-desk-441-fix-login' => ['volumes' => 1],
        'wt-shop-feat-checkout' => ['volumes' => 1],
        // Somebody else's Compose project, on the same daemon, carrying no
        // marker: out of scope under every flag this command has.
        'app-441' => ['containers' => 2, 'volumes' => 2],
    ]);

    expect(worktreeList()->getErrorOutput())
        ->toContain('1 project of desk')
        ->toContain('wt-desk-441-fix-login')
        ->not->toContain('wt-shop-feat-checkout')
        ->and(worktreeList(['--all'])->getErrorOutput())
        ->toContain('2 projects on this machine')
        ->toContain('wt-shop-feat-checkout')
        ->toContain("'worktree reap --all' removes them")
        ->not->toContain('app-441');
});

it('lists what the registry holds with the Docker daemon stopped, and only loses the warning', function () {
    $this->docker = fakeDockerBinary($this->root, daemon: false, projects: [
        'wt-desk-feat-checkout' => ['volumes' => 3],
    ]);

    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreeList();

    expect($process->getExitCode())->toBe(0)
        ->and(keysListed($process))->toBe(['wt-desk-441-fix-login'])
        // Nothing could be asked, so nothing is claimed: an unreachable daemon
        // is not evidence that this machine is clean.
        ->and($process->getErrorOutput())->not->toContain('no worktree claims');
});

it('falls back to tab-separated rows on a machine with no column', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreeList(env: ['PATH' => pathWithoutColumn()]);

    expect($process->getExitCode())->toBe(0)
        ->and(rowsOf($process)[0])->toBe("KEY\tSLOT\tAPP\tVITE\tREVERB\tDB\tREDIS\tBRANCH\tPATH")
        // Same fields, same order: `awk -F'\t'` reads what a person would have.
        ->and(columnsOf($process)[1][0])->toBe('wt-desk-441-fix-login');
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreeList($arguments);

    expect($process->getExitCode())->toBe(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree list [--all] [--json]');
})->with([
    'an argument it has no use for' => [['441'], 'list takes no arguments, only options; given 441'],
    'an option it does not have' => [['--everything'], "unknown option '--everything'; this command takes --all, --json"],
]);

/**
 * A finished `list`, run from the main checkout against the test's own registry.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 */
function worktreeList(array $arguments = [], array $env = []): Process
{
    $process = new Process(
        [PHP_BINARY, packagePath('bin/worktree'), 'list', ...$arguments],
        test()->main,
        [
            'WORKTREE_HOME' => test()->home,
            'SAIL_DOCKER_BINARY' => test()->docker,
            // See CreateTest: Testbench exports APP_ENV=testing, and this suite
            // is about the ordinary `.env` rather than `.env.testing`.
            'APP_ENV' => false,
            ...$env,
        ],
    );

    $process->setTimeout(60);
    $process->run();

    return $process;
}

/**
 * The registry as this machine holds it.
 *
 * Written rather than created by `create`: what `list` reads is a file, and a
 * case that had to bootstrap five worktrees to show five rows would be testing
 * `create` again.
 *
 * @param  array<string, array<string, mixed>>  $entries
 */
function registryHolds(array $entries): void
{
    file_put_contents(
        test()->home.'/registry.json',
        json_encode($entries === [] ? new stdClass : $entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
}

/**
 * One entry, as an earlier `create` would have written it.
 *
 * @param  array<string, int>|null  $ports
 * @return array<string, mixed>
 */
function slotEntry(int $slot, string $slug, ?string $repo = null, ?string $branch = null, ?array $ports = null): array
{
    $repo ??= test()->main;

    if ($ports === null) {
        $ports = [];

        foreach (['app', 'vite', 'reverb', 'db', 'redis'] as $index => $name) {
            $ports[$name] = 20000 + $slot * 10 + $index;
        }
    }

    return [
        'slot' => $slot,
        'repo' => $repo,
        'slug' => $slug,
        'branch' => $branch ?? $slug,
        'path' => $repo.'-worktrees/'.$slug,
        'ports' => $ports,
        'created_at' => '2026-01-01T00:00:00Z',
    ];
}

/**
 * @return list<string> Everything the run put on stdout, line by line.
 */
function rowsOf(Process $process): array
{
    $lines = explode("\n", $process->getOutput());

    return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
}

/**
 * The table as fields, so a case asserts on what a row says rather than on how
 * wide `column` made it.
 *
 * @return list<list<string>>
 */
function columnsOf(Process $process): array
{
    return array_map(
        fn (string $row): array => preg_split('/\t|\s{2,}/', $row) ?: [],
        rowsOf($process),
    );
}

/**
 * @return list<string> The keys the table listed, header excluded.
 */
function keysListed(Process $process): array
{
    return array_map(fn (array $columns): string => $columns[0], array_slice(columnsOf($process), 1));
}

/**
 * A `PATH` carrying git and nothing else — the machine `column` is not
 * installed on, without pretending git is not either.
 */
function pathWithoutColumn(): string
{
    $bin = test()->root.'/path';

    mkdir($bin, 0755, true);
    symlink(trim((new Process(['which', 'git']))->mustRun()->getOutput()), $bin.'/git');

    return $bin;
}
