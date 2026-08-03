<?php

use DeskHQ\LaravelWorktree\Console\Confirmation;
use DeskHQ\LaravelWorktree\Console\Output;

/**
 * `reap`, end to end through the real binary: a real repository, a real registry
 * on disk, real locks, and the suite's fake `docker`.
 *
 * This command force-deletes volumes and has no undo, so most of what is
 * asserted here is what it refuses to touch — a project with no `wt-` marker,
 * another repository's projects without `--all`, and anything a worktree
 * claimed between the scan and the deletion. The rest is the gate: `--dry-run`
 * reaches no destructive Docker call at all, and a run with no terminal to
 * confirm on exits non-zero having destroyed nothing.
 *
 * The sweep has two halves and they meet in one summary and one confirmation:
 * the projects nothing claims, and the registry entries with nothing behind
 * them. The second half is asserted against the registry file the run leaves —
 * a slot given back, or deliberately not.
 */
beforeEach(function () {
    harness('worktree-reap');

    $this->main = $this->root.'/desk';
    $this->shop = $this->root.'/shop';

    mkdir($this->main, 0755, true);

    runGit($this->main, 'init', '--quiet', '--initial-branch=main', '.');
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('destroys the projects nothing claims, one Compose teardown and one label sweep each', function () {
    daemonHolds([
        'wt-desk-441-fix-login' => ['containers' => ['441-app'], 'volumes' => ['441-pgsql', '441-redis']],
        'wt-desk-feat-checkout' => ['volumes' => ['checkout-pgsql']],
    ]);

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveSucceeded()
        // Nothing machine-readable happens here either: the exit code is the
        // answer, and the manifest is a diagnostic like every other one.
        ->and($process->getOutput())->toBe('')
        ->and(dockerCalls())
        ->toContain('compose -p wt-desk-441-fix-login down --volumes --remove-orphans')
        ->toContain('rm --force --volumes 441-app')
        ->toContain('volume rm --force 441-pgsql')
        ->toContain('volume rm --force 441-redis')
        ->toContain('compose -p wt-desk-feat-checkout down --volumes --remove-orphans')
        ->toContain('volume rm --force checkout-pgsql')
        ->and($process->getErrorOutput())
        ->toContain('found 2 orphaned projects for desk:')
        ->toContain('wt-desk-441-fix-login  1 container, 2 volumes')
        ->toContain('wt-desk-feat-checkout  0 containers, 1 volume')
        ->toContain('reaped 2 projects: wt-desk-441-fix-login, wt-desk-feat-checkout');
});

/**
 * The whole of what scopes this command. A custom label cannot cover the
 * anonymous volumes a `VOLUME` directive produces, so the marker in the project
 * name is what stands between `reap` and somebody else's database — and no
 * configuration can change or remove it.
 */
it('never touches a project without the wt- marker, whatever it is called', function () {
    daemonHolds([
        'app-441' => ['containers' => ['app-441-web'], 'volumes' => ['app-441-pgsql']],
        'desk-441' => ['volumes' => ['desk-441-pgsql']],
        'wt-desk-441-fix-login' => ['volumes' => ['441-pgsql']],
    ]);

    $process = worktreeReap(['--yes', '--all']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('found 1 orphaned project on this machine:')
        ->not->toContain('app-441')
        ->not->toContain('desk-441-pgsql')
        ->and(dockerCalls())
        ->toContain('volume rm --force 441-pgsql')
        ->not->toContain('volume rm --force app-441-pgsql')
        ->not->toContain('volume rm --force desk-441-pgsql')
        ->not->toContain('compose -p app-441 down --volumes --remove-orphans');
});

it('leaves another repository\'s projects alone until --all says otherwise', function () {
    daemonHolds([
        'wt-desk-441-fix-login' => ['volumes' => ['441-pgsql']],
        'wt-shop-feat-checkout' => ['volumes' => ['shop-pgsql']],
    ]);

    $scoped = worktreeReap(['--yes']);

    expect($scoped)->toHaveSucceeded()
        ->and($scoped->getErrorOutput())
        ->toContain('found 1 orphaned project for desk:')
        ->not->toContain('wt-shop-feat-checkout')
        ->and(dockerCalls())
        ->toContain('volume rm --force 441-pgsql')
        ->not->toContain('volume rm --force shop-pgsql');

    $everywhere = worktreeReap(['--yes', '--all']);

    expect($everywhere)->toHaveSucceeded()
        ->and($everywhere->getErrorOutput())->toContain('reaped 1 project: wt-shop-feat-checkout')
        ->and(dockerCalls())->toContain('volume rm --force shop-pgsql');
});

it('leaves a project the registry claims alone, whichever checkout claims it', function () {
    daemonHolds([
        'wt-desk-441-fix-login' => ['containers' => ['441-app'], 'volumes' => ['441-pgsql']],
        'wt-shop-feat-checkout' => ['volumes' => ['shop-pgsql']],
    ]);

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->shop),
    ]);

    $process = worktreeReap(['--yes', '--all']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain('nothing to reap: no project is on this daemon that no worktree claims')
        ->and(dockerCalls())->not->toContain('volume rm --force 441-pgsql')
        ->and(dockerCalls())->not->toContain('volume rm --force shop-pgsql');
});

/**
 * The scan is a snapshot; the deletion is not taken on it. A `create` that
 * started in between holds the entry by the time the per-key lock comes free,
 * and the project it is bootstrapping is no longer an orphan.
 */
it('skips a project that was claimed between the scan and the deletion', function () {
    daemonHolds([
        'wt-desk-441-fix-login' => ['volumes' => ['441-pgsql']],
        'wt-desk-feat-checkout' => ['volumes' => ['checkout-pgsql']],
    ]);

    // Claimed while the run is already going: the fake `docker` writes the
    // entry the moment the *first* project's Compose teardown is asked for,
    // which is after the scan and before the second project is looked at.
    claimedDuringTheRun('wt-desk-feat-checkout', slotEntry(0, 'feat-checkout'));

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('found 2 orphaned projects for desk:')
        ->toContain('skipping wt-desk-feat-checkout: a worktree claimed it after the scan')
        ->toContain('reaped 1 project: wt-desk-441-fix-login')
        ->and(dockerCalls())
        ->toContain('volume rm --force 441-pgsql')
        // Nothing of the claimed project was touched, not even its Compose down.
        ->not->toContain('compose -p wt-desk-feat-checkout down --volumes --remove-orphans')
        ->not->toContain('volume rm --force checkout-pgsql');
});

/*
|--------------------------------------------------------------------------
| The other half of the sweep
|--------------------------------------------------------------------------
|
| An entry whose worktree directory is gone is claimed, so it is not an orphan;
| and it is not a worktree, because there is nothing there. It held its slot and
| its port block for as long as the registry survived, and nothing swept it
| (#53). Reclaiming one tears its project down first, so it cannot leave a fresh
| orphan behind.
|
*/

it('reclaims an entry whose worktree is gone, taking its project down with it', function () {
    daemonHolds(['wt-desk-feat-search' => ['containers' => ['search-app'], 'volumes' => ['search-pgsql']]]);

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-search' => slotEntry(3, 'feat-search'),
    ], gone: ['wt-desk-feat-search']);

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('found 1 registry entry for desk whose worktree is gone:')
        ->toContain('wt-desk-feat-search  slot 3, '.$this->root.'/desk-worktrees/feat-search')
        ->toContain('reclaimed 1 registry entry: wt-desk-feat-search (slot 3)')
        // The teardown as well as the row: an entry with a missing directory
        // may still have containers and volumes, and dropping the row alone
        // would convert it into an orphan needing a second reap.
        ->and(dockerCalls())
        ->toContain('compose -p wt-desk-feat-search down --volumes --remove-orphans')
        ->toContain('volume rm --force search-pgsql')
        ->and(registryNow())->toHaveKey('wt-desk-441-fix-login')
        ->and(registryNow())->not->toHaveKey('wt-desk-feat-search');
});

it('leaves the entry alone when its worktree is where it says it is', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain('nothing to reap')
        ->and(registryNow())->toHaveKey('wt-desk-441-fix-login')
        ->and(destructiveCalls())->toBe([]);
});

/**
 * The scoping question the issue left to be settled: an entry whose whole
 * *repository* has been deleted can never be reclaimed by a repository-scoped
 * run, because there is no checkout left to run one from. So it belongs to
 * nobody, and every run reports it — under a heading of its own, since it is
 * the case a person has the least context for.
 */
it('reports an entry whose repository is gone in any run, and another checkout\'s only under --all', function () {
    mkdir($this->shop, 0755, true);

    registryHolds([
        'wt-shop-feat-checkout' => slotEntry(0, 'feat-checkout', repo: $this->shop),
        'wt-gone-feat-search' => slotEntry(1, 'feat-search', repo: $this->root.'/gone'),
    ], gone: ['wt-shop-feat-checkout', 'wt-gone-feat-search']);

    $scoped = worktreeReap(['--dry-run']);

    expect($scoped)->toHaveSucceeded()
        ->and($scoped->getErrorOutput())
        ->toContain('found 1 registry entry whose repository is gone, so no checkout can claim it:')
        ->toContain('wt-gone-feat-search  slot 1, '.$this->root.'/gone-worktrees/feat-search, whose checkout '.$this->root.'/gone has gone too')
        // Another live checkout's, on the other hand, is that checkout's to
        // reclaim — until this run says it is asking about the machine.
        ->not->toContain('wt-shop-feat-checkout')
        ->and(worktreeReap(['--dry-run', '--all'])->getErrorOutput())
        ->toContain('found 1 registry entry on this machine whose worktree is gone:')
        ->toContain('wt-shop-feat-checkout')
        ->toContain('wt-gone-feat-search')
        // Reported by both runs, reclaimed by neither: --dry-run keeps every
        // slot it named, as it keeps every volume.
        ->and(registryNow())->toHaveKeys(['wt-shop-feat-checkout', 'wt-gone-feat-search'])
        ->and(destructiveCalls())->toBe([]);
});

/**
 * Reclaiming ends in a teardown, and a teardown that could not ask anything is
 * not a teardown that worked — so the slot stays claimed rather than being
 * given back on the strength of an unreachable daemon.
 */
it('reports a dead entry with no daemon to reach, and reclaims nothing', function () {
    $this->docker = fakeDockerBinary($this->root, daemon: false);

    registryHolds(['wt-desk-feat-search' => slotEntry(3, 'feat-search')], gone: ['wt-desk-feat-search']);

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('found 1 registry entry for desk whose worktree is gone:')
        ->toContain('could not confirm that wt-desk-feat-search is gone')
        ->toContain('there is no Docker daemon answering on this machine')
        ->and(registryNow())->toHaveKey('wt-desk-feat-search');
});

/**
 * The same discipline the orphan half is under, against the other fact a
 * reclaim turns on: the directory. A `create` that finished between the scan
 * and the lock coming free has put the worktree back, and its slot is not free
 * to take.
 */
it('skips an entry whose worktree came back between the scan and the reclaim', function () {
    daemonHolds(['wt-desk-441-fix-login' => ['volumes' => ['441-pgsql']]]);

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-search' => slotEntry(3, 'feat-search'),
    ], gone: ['wt-desk-441-fix-login', 'wt-desk-feat-search']);

    // Made while the run is already going: the fake `docker` creates the
    // directory the moment the *first* entry's Compose teardown is asked for,
    // which is after the scan and before the second is looked at.
    madeDuringTheRun($this->root.'/desk-worktrees/feat-search');

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('found 2 registry entries for desk whose worktree is gone:')
        ->toContain('skipping wt-desk-feat-search: there is a worktree at '.$this->root.'/desk-worktrees/feat-search again')
        ->toContain('reclaimed 1 registry entry: wt-desk-441-fix-login (slot 0)')
        ->and(dockerCalls())->not->toContain('compose -p wt-desk-feat-search down --volumes --remove-orphans')
        // The one that came back kept its slot; the one that did not, did not.
        ->and(registryNow())->toHaveKey('wt-desk-feat-search')
        ->and(registryNow())->not->toHaveKey('wt-desk-441-fix-login');
});

/**
 * A slot given back over volumes that are still there would leave nothing
 * naming them — the-desk#1095 from the other end. So the entry stands, and the
 * next `reap` finds it again.
 */
it('keeps the entry when its project would not go, and says why', function () {
    daemonHolds(['wt-desk-feat-search' => ['volumes' => ['search-pgsql']]], refuses: ['search-pgsql']);

    registryHolds(['wt-desk-feat-search' => slotEntry(3, 'feat-search')], gone: ['wt-desk-feat-search']);

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('wt-desk-feat-search survived teardown')
        ->toContain('the entry for wt-desk-feat-search still holds its slot, deliberately')
        ->and(registryNow())->toHaveKey('wt-desk-feat-search');
});

it('reports both classes in one summary, under one confirmation', function () {
    daemonHolds([
        'wt-desk-441-fix-login' => ['containers' => ['441-app'], 'volumes' => ['441-pgsql']],
        'wt-desk-feat-search' => ['volumes' => ['search-pgsql']],
    ]);

    registryHolds(['wt-desk-feat-search' => slotEntry(3, 'feat-search')], gone: ['wt-desk-feat-search']);

    $process = worktreeReap();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('found 1 orphaned project for desk:')
        ->toContain('wt-desk-441-fix-login  1 container, 1 volume')
        ->toContain('found 1 registry entry for desk whose worktree is gone:')
        ->toContain('wt-desk-feat-search  slot 3,')
        // One gate for one reap: releasing a slot is less destructive than
        // force-deleting a volume and does not get a softer one for it.
        ->toContain('there is no terminal here to confirm on')
        ->and(destructiveCalls())->toBe([])
        ->and(registryNow())->toHaveKey('wt-desk-feat-search');
});

it('reports under --dry-run without making a single destructive call', function () {
    daemonHolds([
        'wt-desk-441-fix-login' => ['containers' => ['441-app'], 'volumes' => ['441-pgsql', '441-redis']],
    ]);

    $process = worktreeReap(['--dry-run']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('found 1 orphaned project for desk:')
        ->toContain('wt-desk-441-fix-login  1 container, 2 volumes')
        ->toContain('--dry-run: nothing was removed')
        // The label queries the scan is made of, and nothing that removes: no
        // `compose down`, no `rm`, no `volume rm`, under any name.
        ->and(destructiveCalls())->toBe([])
        ->and(dockerCalls())->toContain('volume ls --filter label=com.docker.compose.project --format {{.Label "com.docker.compose.project"}}');
});

/**
 * `--dry-run` does not need `--yes`, and does not consult the gate at all:
 * a report that refused to run because nobody was at the terminal would be a
 * report nobody could ever get out of CI, which is where it is most useful.
 */
it('reports under --dry-run with no terminal and no --yes', function () {
    daemonHolds(['wt-desk-441-fix-login' => ['volumes' => ['441-pgsql']]]);

    expect(worktreeReap(['--dry-run']))->toHaveSucceeded()
        ->and(destructiveCalls())->toBe([]);
});

it('refuses to destroy anything with no terminal to confirm on and no --yes', function () {
    daemonHolds(['wt-desk-441-fix-login' => ['volumes' => ['441-pgsql']]]);

    $process = worktreeReap();

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        // Named first, so the run that refuses still tells you what is there.
        ->toContain('found 1 orphaned project for desk:')
        ->toContain('there is no terminal here to confirm on')
        ->toContain('pass --yes to agree in advance, or --dry-run to see what it would take')
        ->and(destructiveCalls())->toBe([]);
});

it('exits non-zero naming a volume that could not be removed', function () {
    daemonHolds(['wt-desk-441-fix-login' => ['volumes' => ['441-pgsql', '441-redis']]], refuses: ['441-pgsql']);

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain('wt-desk-441-fix-login survived teardown')
        ->toContain('1 volume (441-pgsql)')
        ->toContain('1 of them are still on this daemon: wt-desk-441-fix-login')
        // The one that did go, went.
        ->and(dockerCalls())->toContain('volume rm --force 441-redis');
});

it('treats nothing to reap as a clean run rather than an error', function () {
    $process = worktreeReap(['--yes']);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('nothing to reap: no project of desk is on this daemon that no worktree claims');
});

/**
 * The distinction the-desk#1095 was filed over, on the command whose whole job
 * is the disk: an empty scan and a scan that never happened are not the same
 * answer, and only one of them may be reported as a tidy machine.
 */
it('says the daemon could not be asked rather than reporting a clean machine', function () {
    $this->docker = fakeDockerBinary($this->root, daemon: false, projects: [
        'wt-desk-441-fix-login' => ['volumes' => 3],
    ]);

    $process = worktreeReap(['--yes']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())
        ->toContain('there is no Docker daemon answering on this machine, so nothing could be scanned for')
        ->toContain('nothing here says the disk is clean')
        ->not->toContain('nothing to reap')
        ->and(destructiveCalls())->toBe([]);
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreeReap($arguments);

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree reap [--all] [--dry-run] [--yes]');
})->with([
    'an argument it has no use for' => [['441'], 'reap takes no arguments, only options; given 441'],
    'an option it does not have' => [['--force'], "unknown option '--force'; this command takes --all, --dry-run, --yes"],
]);

/**
 * The gate itself, away from the binary: `--yes` is what the cases above use,
 * because a terminal is the one thing a test process does not have — so what a
 * person at one would be asked, and what would be made of their answer, is
 * asserted directly here.
 */
it('destroys only on a clear yes', function (string $typed, bool $agreed) {
    $diagnostics = fopen('php://memory', 'w+');
    $input = fopen('php://memory', 'w+');

    fwrite($input, $typed);
    rewind($input);

    $confirmation = new Confirmation(new Output($diagnostics), $input);

    expect($confirmation->confirm('destroy these? [y/N]'))->toBe($agreed)
        // The question is a diagnostic like everything else: stdout is for what
        // a script parses, and a prompt is not that.
        ->and(diagnosticsIn($diagnostics))->toBe('destroy these? [y/N] ');
})->with([
    'y' => ["y\n", true],
    'yes' => ["YES\n", true],
    'n' => ["n\n", false],
    'a bare return' => ["\n", false],
    'something else entirely' => ["441\n", false],
    'a closed stdin' => ['', false],
]);

it('knows a pipe is not somebody to ask', function () {
    $input = fopen('php://memory', 'w+');

    expect((new Confirmation(new Output(fopen('php://memory', 'w+')), $input))->isInteractive())->toBeFalse();
});

/**
 * What this daemon is holding, project by project.
 *
 * @param  array<string, array{containers?: int|list<string>, volumes?: int|list<string>}>  $projects
 * @param  list<string>  $refuses  Resources whose removal fails, as one still in use would.
 */
function daemonHolds(array $projects, array $refuses = []): void
{
    test()->docker = fakeDockerBinary(test()->root, refuses: $refuses, projects: $projects);
}

/**
 * Register $project in the middle of the run, as a `create` that started after
 * the scan would have: the fake `docker` writes the registry the first time it
 * is asked to take a project down.
 *
 * A hook in the fake rather than a second `worktree create`, because the
 * property under test is that the registry is re-read *under the per-key lock*
 * — a real concurrent create would be made to wait on that very lock, which is
 * the correct behaviour and the reason it could never write the entry in the
 * window this needs it written in.
 *
 * @param  array<string, mixed>  $entry
 */
function claimedDuringTheRun(string $project, array $entry): void
{
    $registry = json_encode([$project => $entry], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    file_put_contents(
        test()->root.'/fake/hook',
        "cat > '".test()->home."/registry.json' <<'JSON'\n".$registry."\nJSON\n",
    );
}

/**
 * Put a worktree directory back in the middle of the run, as a `create` that
 * finished after the scan would have: the fake `docker` makes it the first time
 * it is asked to take a project down.
 *
 * A hook in the fake for the same reason {@see claimedDuringTheRun()} is one —
 * a real concurrent `create` would be made to wait on the very lock this is
 * about, which is the correct behaviour and the reason it could never write in
 * the window this needs written in.
 */
function madeDuringTheRun(string $path): void
{
    file_put_contents(test()->root.'/fake/hook', "mkdir -p '$path'\n");
}

/**
 * The invocations that would have changed something on the daemon — what a
 * `--dry-run`, or a refusal, must not have made a single one of.
 *
 * @return list<string>
 */
function destructiveCalls(): array
{
    return array_values(array_filter(dockerCalls(), fn (string $call): bool => (bool) preg_match(
        '/^(compose -p|rm --force|volume rm)/',
        $call,
    )));
}
