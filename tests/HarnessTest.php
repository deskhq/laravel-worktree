<?php

use Symfony\Component\Process\Process;

/**
 * The harness, turned on itself.
 *
 * Every other file in this suite trusts three properties: that a run cannot
 * reach the developer's registry, that a run cannot reach the developer's
 * daemon, and that a failing run reports why. None of them is visible in the
 * cases that depend on them — a case that quietly wrote to `~/.laravel-worktree`
 * would pass — so they are asserted here, once.
 */
it('pins HOME away from the developer’s own for every case, whatever the case declares', function () {
    expect(getenv('HOME'))->not->toBe(developerHome())
        // All three, because a subprocess started without an explicit
        // environment inherits the union of them, with `$_ENV` winning.
        ->and($_SERVER['HOME'])->toBe(getenv('HOME'))
        ->and($_ENV['HOME'])->toBe(getenv('HOME'))
        ->and(worktreeEnvironment()['HOME'])->toBe(getenv('HOME'));
});

/**
 * `WORKTREE_HOME` is what a run reads first, and pinning `$HOME` is what makes
 * losing it harmless. A fixture that set only the first would put every case
 * that unsets it — and every code path that never reads it — back in the
 * developer's registry.
 */
it('keeps a run that lost WORKTREE_HOME inside its own fixture', function () {
    harness('worktree-harness');

    $this->main = mainCheckout($this->root.'/desk');

    mkdir($this->home.'/.laravel-worktree', 0755, true);
    file_put_contents(
        $this->home.'/.laravel-worktree/registry.json',
        (string) json_encode(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]),
    );

    $registry = directorySnapshot(developerHome().'/.laravel-worktree');

    $process = worktree(['list'], env: ['WORKTREE_HOME' => false]);

    expect($process)->toHaveSucceeded()
        // The row could only have come from the fixture's own `$HOME`.
        ->and($process->getOutput())->toContain('wt-desk-441-fix-login')
        // Untouched, rather than absent: the developer running this suite is
        // the most likely person to have a real registry of their own.
        ->and(directorySnapshot(developerHome().'/.laravel-worktree'))->toBe($registry);

    deleteDirectory($this->root);
});

/**
 * That check is worth only what it notices. Asserting the developer's registry
 * is *unchanged* has to fail on a run that appended a row to one that already
 * existed — which is precisely what asserting it is absent could not do, on the
 * machines where it exists.
 */
it('notices a write into a registry that was already there', function () {
    $home = temporaryDirectory('worktree-snapshot');

    mkdir($home.'/.laravel-worktree/locks', 0755, true);
    file_put_contents($home.'/.laravel-worktree/registry.json', '{}');

    $before = directorySnapshot($home.'/.laravel-worktree');

    expect(directorySnapshot($home.'/.laravel-worktree'))->toBe($before)
        ->and(directorySnapshot($home.'/.nothing-here'))->toBeNull();

    file_put_contents(
        $home.'/.laravel-worktree/registry.json',
        (string) json_encode(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]),
    );

    expect(directorySnapshot($home.'/.laravel-worktree'))->not->toBe($before);

    deleteDirectory($home);
});

/**
 * Nothing in this suite may need a daemon: the whole of what this package asks
 * of Docker is asserted through a fake that records its argv and replays canned
 * output. A case that forgot to declare one must fail, not fall through to
 * whatever `docker` is on `PATH` — that passes here and starts containers on
 * the developer's laptop.
 */
it('hands a case that declared no fake docker one that refuses', function () {
    expect(worktreeEnvironment()['SAIL_DOCKER_BINARY'])->toBe(packagePath('tests/Fixtures/unreachable-docker'));

    $refused = new Process([packagePath('tests/Fixtures/unreachable-docker'), 'compose', 'up']);
    $refused->run();

    expect($refused)->toHaveFailed()
        ->and($refused->getErrorOutput())->toContain('the suite reached for a real docker: docker compose up');
});

it('answers the queries a teardown asks with no daemon anywhere on the machine', function () {
    harness('worktree-harness');

    $docker = new Process([$this->docker, 'ps', '-aq', '--filter', 'label=com.docker.compose.project=wt-desk-441']);
    $docker->run();

    expect($docker)->toHaveSucceeded()
        ->and(dockerCalls())->toBe(['ps -aq --filter label=com.docker.compose.project=wt-desk-441']);

    deleteDirectory($this->root);
});

/**
 * The one thing an assertion on the exit code alone throws away. *"Failed
 * asserting that 1 is identical to 0"* is what left the-desk#619 undiagnosable
 * for as long as it was.
 */
it('reports what the tool said, not only the status it exited with', function () {
    $process = worktree(['destroy']);

    expect(worktreeFailure($process))
        ->toContain('worktree exited 64')
        ->toContain('error: unknown command: destroy');
});

it('says so when a failing run wrote nothing at all', function () {
    $silent = new Process([PHP_BINARY, '-r', 'exit(3);']);
    $silent->run();

    expect(worktreeFailure($silent))
        ->toContain('worktree exited 3')
        ->toContain('(it wrote nothing)');
});

/**
 * The environment above is the guarantee, and it is only a guarantee while
 * every run goes through it.
 */
it('spawns the host binary from one place', function () {
    expect(testFilesMatching("/PHP_BINARY,\s*packagePath\('bin\/worktree'\)/"))->toBe(['Pest.php']);
});

/**
 * A `--parallel` run gives each file its own process, so a helper declared
 * beside one file's cases does not exist for another's. That fails as *"call to
 * undefined function"* — a suite that passes in one execution order and not in
 * the other.
 */
it('declares every helper a second file reaches for where every case can see it', function () {
    $declared = [];

    foreach (testFiles() as $file) {
        if (basename($file) === 'Pest.php') {
            continue;
        }

        preg_match_all('/^function (\w+)\(/m', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $name) {
            $declared[$name] = basename($file);
        }
    }

    $reached = [];

    foreach (testFiles() as $file) {
        $body = (string) file_get_contents($file);

        foreach ($declared as $name => $declaredIn) {
            if (basename($file) === $declaredIn) {
                continue;
            }

            // A bare call, rather than a method or a static of the same name.
            if (preg_match('/(?<![\w>:$\\\\])'.$name.'\s*\(/', $body) === 1) {
                $reached[] = basename($file).' calls '.$name.'(), declared in '.$declaredIn;
            }
        }
    }

    expect($reached)->toBe([]);
});

/**
 * @return list<string> Every test file, absolute.
 */
function testFiles(): array
{
    return glob(__DIR__.'/*.php') ?: [];
}

/**
 * @return list<string> The names of the test files whose contents match, sorted.
 */
function testFilesMatching(string $pattern): array
{
    $matches = [];

    foreach (testFiles() as $file) {
        if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
            $matches[] = basename($file);
        }
    }

    sort($matches);

    return $matches;
}
