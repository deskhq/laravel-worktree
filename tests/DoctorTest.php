<?php

use DeskHQ\LaravelWorktree\Runtime\SailRuntime;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `doctor`, end to end through the real binary: a real repository, a real
 * registry and real locks on disk, and the suite's fake `docker` — because the
 * whole claim of this command is that it answers without a daemon and without
 * creating anything, and both halves of that are only worth asserting against a
 * machine it cannot touch.
 *
 * Two things are under test everywhere here. **Every check runs**: a report that
 * stopped at the first failure would be the refusal `create` already gives. And
 * **each check reaches the right verdict**, which is this command's own
 * vocabulary and the only thing in the report that is not somebody else's.
 *
 * ## What these cases deliberately do not assert
 *
 * The refusals themselves. Every one of them is built by the module that
 * enforces it — the published-port collision by `PublishedPorts`, the exhausted
 * registry and the bind-probe skip by `Allocator`, the lock holder by `Owner`,
 * the app service by `Services` — and each is pinned by *that* module's own
 * test. A second copy here bought nothing but a second edit, and where the two
 * copies disagreed it was this file that was wrong (#74). What is pinned below
 * is what `doctor` itself writes: the counts, the "could not be checked"
 * sentences, and the lines that describe a healthy answer, which nothing else in
 * the package has occasion to say.
 *
 * Most cases assert through `--json` rather than through the rendering: the
 * columns are padded to the widest verdict a run produced, so a test that
 * matched `ok    config` would be a test about which other checks failed. The
 * two that are about the rendering say so.
 */
beforeEach(function () {
    harness('worktree-doctor');

    $this->main = $this->root.'/desk';
    $this->base = freePortBase(100);

    mainCheckout($this->main);

    // What a Laravel application has and this fixture otherwise would not: the
    // manifest a create resolves through a throwaway Composer container to
    // obtain Sail.
    file_put_contents($this->main.'/composer.json', json_encode(['require-dev' => ['laravel/sail' => '^1.0']])."\n");

    configureRepository();
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('passes every check on a repository and a machine that are set up correctly', function () {
    $process = worktreeDoctor(['--json'], ghOnPath());

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toBe('')
        ->and(verdictsOf($process))->toBe([
            'config' => 'ok',
            'names' => 'ok',
            'compose' => 'ok',
            'service' => 'ok',
            'sail' => 'ok',
            'ports' => 'ok',
            'docker' => 'ok',
            'gh' => 'ok',
            'slots' => 'ok',
            'block' => 'ok',
            'locks' => 'ok',
            'entries' => 'ok',
            'orphans' => 'ok',
        ]);
});

/**
 * The claim the command is named for. Nothing is allocated, nothing is written,
 * and the daemon is asked what it has rather than told to do anything — so the
 * two directories a run could leave a mark in are byte-identical afterwards, and
 * every `docker` invocation is a question.
 */
it('creates nothing, starts nothing and removes nothing', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $home = directorySnapshot($this->home);
    $checkout = directorySnapshot($this->main);
    $worktrees = directorySnapshot($this->root.'/desk-worktrees');

    expect(worktreeDoctor())->toHaveSucceeded()
        // The registry, the locks, the checkout and the worktrees beside it:
        // every directory a run of this package can leave a mark in.
        ->and(directorySnapshot($this->home))->toBe($home)
        ->and(directorySnapshot($this->main))->toBe($checkout)
        ->and(directorySnapshot($this->root.'/desk-worktrees'))->toBe($worktrees)
        ->and(dockerCalls())
        ->toContain('info')
        // Every question, and no instruction: the verbs that change a machine
        // are the ones that must not be here.
        ->not->toContain('compose -p wt-desk-441-fix-login up -d')
        ->and(implode("\n", dockerCalls()))
        ->not->toContain('down --volumes')
        ->not->toContain('volume rm')
        ->not->toContain('rm --force');
});

it('reports every failure rather than stopping at the first', function () {
    unlink($this->main.'/composer.json');

    file_put_contents($this->main.'/compose.yaml', <<<'YAML'
        services:
          laravel.test:
            image: laravel
            depends_on: [redis]
          redis:
            image: redis
            ports: ['6379:6379']
        YAML);

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveExited(1)
        ->and(verdictsOf($process))->toMatchArray([
            'ports' => 'fail',
            // A checkout with no Sail anywhere is a warning rather than a
            // refusal: the manifest is not the only way an install produces one.
            'sail' => 'warn',
            // And the ones after the failure ran anyway, which is the point.
            'slots' => 'ok',
            'locks' => 'ok',
        ]);
});

/**
 * The pre-flight from #36, carried rather than summarised. It is the best thing
 * this package says — the mapping, the reason that service is running at all,
 * and the one edit that fixes it — and it is three lines long, which is what a
 * report is tempted to shorten.
 *
 * The sentence itself is `PublishedPorts`' and is pinned by PublishedPortsTest.
 * What is asserted here is that the whole of it arrives: the last line of a
 * refusal built three lines at a time is the part a summary would have dropped.
 */
it('carries the published-port refusal as a create raises it, in full', function () {
    file_put_contents($this->main.'/compose.yaml', <<<'YAML'
        services:
          laravel.test:
            image: laravel
            depends_on: [redis]
          redis:
            image: redis
            ports: ['6379:6379']
        YAML);

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveExited(1)
        ->and(doctorReport($process)['ports'])
        ->verdict->toBe('fail')
        ->message->toBe(publishedPortRefusal($this->main));
});

/**
 * Every other command ends on this: the config is read before any of them is
 * reached, and one that throws is the whole run. `doctor` is the command whose
 * subject that failure is, so it reports it — and then says which of the checks
 * below could not be made because of it, rather than passing them.
 */
it('reports a configuration that will not load, and what it could not check without one', function () {
    file_put_contents($this->main.'/config/worktree.php', "<?php return ['slotz' => 5];\n");

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveExited(1)
        ->and(verdictsOf($process))->toMatchArray([
            'config' => 'fail',
            // Everything that reads the file could not be asked...
            'names' => 'unchecked',
            'ports' => 'unchecked',
            'slots' => 'unchecked',
            'block' => 'unchecked',
            'locks' => 'unchecked',
            'entries' => 'unchecked',
            'orphans' => 'unchecked',
            // ...and everything that does not was asked anyway.
            'compose' => 'ok',
            'service' => 'ok',
            'sail' => 'ok',
            'docker' => 'ok',
        ])
        ->and(doctorReport($process)['config']['message'])
        ->toContain("config/worktree.php: unknown key 'slotz' (did you mean 'slots'?)")
        ->and(doctorReport($process)['slots']['message'])
        ->toContain('this check reads config/worktree.php, which did not load');
});

it('works with no Docker daemon, reporting what it could not check rather than inferring a pass', function () {
    $this->docker = fakeDockerBinary($this->root, daemon: false);

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveSucceeded()
        ->and(verdictsOf($process))->toMatchArray([
            'docker' => 'warn',
            'orphans' => 'unchecked',
            // The version pre-flight is a question about the client, so it is
            // still answerable with Docker Desktop closed.
            'compose' => 'ok',
            'ports' => 'ok',
        ])
        ->and(doctorReport($process)['orphans']['message'])
        ->toContain('nothing here says the disk is clean');
});

/**
 * The version pre-flight, graded by whether this repository writes an overlay at
 * all — because `Overlay::generate()` returns before verifying the version when
 * nothing is configured to be overridden, so on such a repository a create never
 * makes this check. A report that failed over it was refusing a machine that
 * would have worked (#74). The sentence is `ComposeVersion`'s and OverlayTest
 * pins it.
 */
it('grades a Compose too old for the merge tag by whether this repository writes an overlay', function () {
    $this->docker = fakeDockerBinary($this->root, composeVersion: '2.23.3');

    expect(doctorReport(worktreeDoctor(['--json']))['compose'])
        ->verdict->toBe('warn')
        ->message->toContain('no keep_services, no port_overrides — so a create would not reach this check');

    configureRepository(['compose' => ['keep_services' => ['redis']]]);

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveExited(1)
        ->and(doctorReport($process)['compose']['verdict'])->toBe('fail');
});

/**
 * A Compose that is missing outright is a failure however this repository is
 * configured: `sail up -d` is Compose, overlay or no overlay.
 */
it('refuses a machine with no Compose v2 at all, whatever the overlay would have been', function () {
    $this->docker = fakeDockerBinary($this->root, composeSubcommand: false);

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveExited(1)
        ->and(doctorReport($process)['compose']['verdict'])->toBe('fail');
});

/**
 * A build that answers with something unparseable is let through by `create`,
 * deliberately: the subcommand answered, which is the part that would have
 * failed on v1. Letting it through is not the same as calling it healthy, and a
 * command called `doctor` is the one place that difference has to show.
 */
it('says it could not tell, for a Compose that names no version it can read', function () {
    $this->docker = fakeDockerBinary($this->root, composeVersion: 'devel');

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveSucceeded()
        ->and(doctorReport($process)['compose']['verdict'])->toBe('unchecked')
        ->and(doctorReport($process)['compose']['message'])
        ->toContain("answered 'devel', which does not name a version");
});

/**
 * The warning is `Services`' sentence, said here and by `create`'s own
 * pre-flight; CreateTest pins the wording. The failure is this command's own —
 * nothing else in the package has anything to say about a repository with no
 * Compose file at all, since every other reader of it treats that as "declares
 * no services".
 */
it('warns about a service the Compose file does not declare, and fails when there is no file at all', function () {
    file_put_contents($this->main.'/.env', "APP_NAME=Desk\nAPP_SERVICE=web\n");

    expect(doctorReport(worktreeDoctor(['--json']))['service']['verdict'])->toBe('warn');

    unlink($this->main.'/compose.yaml');

    expect(doctorReport(worktreeDoctor(['--json']))['service'])
        ->verdict->toBe('fail')
        ->message->toContain('this checkout has no Compose file');
});

/**
 * Warned rather than failed, since #74: a manifest that does not name
 * `laravel/sail` is a strong hint and not a fact. A path repository, a
 * `replace`, or a fork under another name all produce a `vendor/bin/sail` the
 * runtime is perfectly happy with, and only the install itself can settle it —
 * so the report says so instead of calling a working repository broken.
 */
it('warns when nothing here names Sail, rather than refusing a repository that may still have one', function () {
    unlink($this->main.'/composer.json');

    expect(doctorReport(worktreeDoctor(['--json']))['sail'])
        ->verdict->toBe('warn')
        ->message->toContain(SailRuntime::Install);

    fakeSail($this->main);

    expect(doctorReport(worktreeDoctor(['--json']))['sail']['verdict'])->toBe('ok');
});

it('counts the slots this machine has claimed, and fails when it has claimed all of them', function () {
    configureRepository(['slots' => 2]);

    expect(doctorReport(worktreeDoctor(['--json']))['slots']['message'])
        ->toContain('0 of 2 slots are claimed on this machine, so 2 are free');

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->root.'/shop'),
    ]);

    $process = worktreeDoctor(['--json']);

    // Machine-wide, because slots are: the second entry is another checkout's,
    // and it is holding a port block all the same. What the exhaustion *says* is
    // the allocator's refusal, which AllocatorTest pins.
    expect($process)->toHaveExited(1)
        ->and(doctorReport($process)['slots']['verdict'])->toBe('fail')
        ->and(doctorReport($process)['block'])
        ->verdict->toBe('unchecked')
        ->message->toContain('every slot on this machine is claimed');
});

/**
 * The port-block check needs a slot and `doctor` is not allocating one, so it
 * reports on the next slot a create would take — skipping the blocks something
 * outside the registry is holding, exactly as the allocator skips them, and
 * saying which slot it landed on.
 */
it('warns about a port block something outside the registry is holding, and names the slot a create would take', function () {
    $held = stream_socket_server('tcp://0.0.0.0:'.$this->base, $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

    expect($held)->not->toBeFalse();

    $process = worktreeDoctor(['--json']);

    fclose($held);

    expect($process)->toHaveSucceeded()
        ->and(doctorReport($process)['block'])
        ->verdict->toBe('warn')
        ->message->toContain('slot 0 is free in the registry, but port '.$this->base.' (app) of its block is already '
            .'in use by something the registry does not know about, so a create would skip it')
        ->message->toContain('slot 1 is the next one a create would take, and its ports ('
            .($this->base + 10).'-'.($this->base + 14).') are free');
});

/**
 * The other end of the same search, which had no test at all before #74: every
 * free slot's block held by something outside the registry. The refusal is the
 * allocator's, word for word, and AllocatorTest pins it; what is asserted here
 * is that this is a **failure** — a create would have nowhere to go — and that
 * the report still names every slot it walked past.
 */
it('fails when no free slot has a free port block, and names each one it probed', function () {
    configureRepository(['slots' => 2]);

    $held = [
        stream_socket_server('tcp://0.0.0.0:'.$this->base, $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN),
        stream_socket_server('tcp://0.0.0.0:'.($this->base + 12), $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN),
    ];

    expect($held[0])->not->toBeFalse()->and($held[1])->not->toBeFalse();

    $process = worktreeDoctor(['--json']);

    foreach ($held as $socket) {
        fclose($socket);
    }

    expect($process)->toHaveExited(1)
        ->and(doctorReport($process)['block'])
        ->verdict->toBe('fail')
        ->message->toContain('slot 0 is free in the registry, but port '.$this->base.' (app)')
        ->message->toContain('slot 1 is free in the registry, but port '.($this->base + 12).' (reverb)');
});

it('names the registry entries with nothing behind them, and the sweep that reclaims them', function () {
    registryHolds(
        ['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')],
        gone: ['wt-desk-441-fix-login'],
    );

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveSucceeded()
        ->and(doctorReport($process)['entries'])
        ->verdict->toBe('warn')
        ->message->toContain('1 registry entry holds a slot whose worktree directory is gone')
        ->message->toContain('wt-desk-441-fix-login  slot 0, '.$this->root.'/desk-worktrees/441-fix-login')
        ->message->toContain("'worktree reap' reclaims it");
});

/**
 * The `--all` half of the same sentence, which nothing exercised before #74: a
 * scoped `reap` reaches this checkout's dead entries and the ones whose checkout
 * is gone too, and nothing else — so a dead entry belonging to a clone that is
 * still there is only reclaimable by the machine-wide sweep, and naming the
 * scoped one would send somebody back having done exactly what they were told.
 */
it('names the machine-wide sweep when a dead entry belongs to a checkout that is still there', function () {
    mkdir($this->root.'/shop', 0755, true);

    registryHolds(
        ['wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->root.'/shop')],
        gone: ['wt-shop-feat-checkout'],
    );

    expect(doctorReport(worktreeDoctor(['--json']))['entries'])
        ->verdict->toBe('warn')
        ->message->toContain("'worktree reap --all' reclaims it");
});

it('names a lock whose holder is not running any more, and the command that removes it', function () {
    lockTakenBy($this->home.'/locks/wt-desk-441-fix-login.lock', ownerRecord(['pid' => deadPid()]));

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveSucceeded()
        ->and(doctorReport($process)['locks'])
        ->verdict->toBe('warn')
        // The count and the remedy are this report's; what it says about the
        // holder is `Owner`'s clause, and LockTest pins that.
        ->message->toContain('1 of 1 lock on this machine is held by nothing this run could find')
        ->message->toContain("'worktree unlock --all' removes them now");
});

it('names the projects on this daemon that no worktree claims, with the lines reap asks permission by', function () {
    $this->docker = fakeDockerBinary($this->root, projects: [
        'wt-desk-441-fix-login' => ['containers' => 4, 'volumes' => 3],
    ]);

    $process = worktreeDoctor(['--json']);

    expect($process)->toHaveSucceeded()
        ->and(doctorReport($process)['orphans'])
        ->verdict->toBe('warn')
        ->message->toContain('1 project of desk is still on this daemon that no worktree claims')
        ->message->toContain('wt-desk-441-fix-login  4 containers, 3 volumes')
        ->message->toContain("'worktree reap' removes it");
});

it('warns without failing when gh is not here, since nothing requires it', function () {
    $process = worktreeDoctor(['--json'], ghOffPath());

    expect($process)->toHaveSucceeded()
        ->and(doctorReport($process)['gh'])
        ->verdict->toBe('warn')
        ->message->toContain('names the worktree issue-441 rather than 441-fix-login');
});

/**
 * The rendering, which is what somebody pastes: one line per check, the message
 * under its own verdict, and a tally that says whether any of it stops a create.
 */
it('prints the report on stderr, and nothing on stdout without --json', function () {
    $process = worktreeDoctor([], ghOnPath());

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('worktree doctor — '.$this->main)
        ->toContain('13 checks: 13 ok')
        ->toContain("nothing here would stop a 'worktree create'");
});

it('indents the continuation lines of a multi-line finding under the first', function () {
    file_put_contents($this->main.'/compose.yaml', <<<'YAML'
        services:
          laravel.test:
            image: laravel
            ports: ['8025:8025']
        YAML);

    $lines = explode("\n", worktreeDoctor()->getErrorOutput());
    $collision = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, "publishes '8025:8025'")));

    expect($collision)->toHaveCount(1)
        // Under the message it belongs to rather than under the column, which
        // is what keeps a three-line refusal reading as one finding.
        ->and($collision[0])->toStartWith('    ')
        ->and(trim($collision[0]))->toStartWith('laravel.test publishes');
});

it('emits the whole report as one object on stdout under --json', function () {
    $process = worktreeDoctor(['--json'], ghOnPath());

    $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toBe('')
        // One line, so it composes with `jq` and with `read` alike.
        ->and(substr_count(trim($process->getOutput()), "\n"))->toBe(0)
        ->and($payload)->toMatchArray(['repository' => $this->main, 'ok' => true])
        ->and($payload['counts'])->toBe(['ok' => 13, 'warn' => 0, 'fail' => 0, 'unchecked' => 0])
        ->and($payload['checks'][0])->toHaveKeys(['name', 'verdict', 'message']);
});

it('takes no arguments, only options', function () {
    $process = worktreeDoctor(['441']);

    expect($process)->toHaveExited(64)
        ->and($process->getErrorOutput())
        ->toContain('error: doctor takes no arguments, only options; given 441')
        ->toContain('usage: worktree doctor [--json]');
});

/**
 * The report as its findings, keyed by check — the shape to assert on, since the
 * rendering's columns are padded to the widest verdict a run happened to
 * produce.
 *
 * @return array<string, array{verdict: string, message: string}>
 */
function doctorReport(Process $process): array
{
    expect($process->getOutput())->not->toBe('', worktreeFailure($process));

    $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $checks = [];

    foreach ($payload['checks'] as $check) {
        $checks[$check['name']] = ['verdict' => $check['verdict'], 'message' => $check['message']];
    }

    return $checks;
}

/**
 * Every check and what it came back with, in the order they were run.
 *
 * @return array<string, string>
 */
function verdictsOf(Process $process): array
{
    return array_map(fn (array $check): string => $check['verdict'], doctorReport($process));
}

/**
 * A `PATH` with `gh` on it, and one without.
 *
 * Declared rather than left to the machine, because `gh` is the one thing this
 * package treats as optional and both answers are worth asserting — on a laptop
 * that has it and on CI that does not. Only `git` is carried across, since it is
 * the one binary a run of `doctor` looks up by name.
 *
 * @return array<string, string>
 */
function ghOnPath(): array
{
    $bin = pathCarryingGit('with-gh');

    file_put_contents($bin.'/gh', "#!/bin/sh\nprintf 'gh version 2.60.0\\n'\n");
    chmod($bin.'/gh', 0755);

    return ['PATH' => $bin];
}

/**
 * @return array<string, string>
 */
function ghOffPath(): array
{
    return ['PATH' => pathCarryingGit('without-gh')];
}

function pathCarryingGit(string $named): string
{
    $bin = test()->root.'/'.$named;

    is_dir($bin) || mkdir($bin, 0755, true);

    $git = (new ExecutableFinder)->find('git');

    expect($git)->not->toBeNull();

    is_file($bin.'/git') || symlink((string) $git, $bin.'/git');

    return $bin;
}
