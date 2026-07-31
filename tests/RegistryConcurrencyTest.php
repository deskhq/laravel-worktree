<?php

use Symfony\Component\Process\Process;

/**
 * The registry's reason for existing, asserted with real processes: two of them
 * racing for a slot, one waiting for another to finish with a worktree, and one
 * interrupted in the middle of the slow work. No Docker — the machine-scoped
 * resource being contended is the registry file and the ports it hands out.
 */
beforeEach(function () {
    $this->home = temporaryDirectory('worktree-home');
    $this->gate = temporaryDirectory('worktree-gate').'/go';
    $this->base = freePortBase(100);

    pinHome($this->home);
});

afterEach(function () {
    touch($this->gate);

    deleteDirectory(dirname($this->gate));
    deleteDirectory($this->home);
});

it('gives two worktrees claiming at once different slots and port blocks', function () {
    $first = claimsASlot($this, '/checkouts/desk', 'wt-desk-441');
    $second = claimsASlot($this, '/checkouts/desk', 'wt-desk-512');

    $claimed = [claimOf($first), claimOf($second)];

    expect($claimed[0]['slot'])->not->toBe($claimed[1]['slot'])
        ->and(array_intersect(array_values($claimed[0]['ports']), array_values($claimed[1]['ports'])))->toBe([])
        ->and(array_column($claimed, 'slot'))->each->toBeIn([0, 1]);

    touch($this->gate);

    expect($first->wait())->toBe(0, worktreeFailure($first))
        ->and($second->wait())->toBe(0, worktreeFailure($second));
});

it('gives two clones of the same repository different slots', function () {
    $first = claimsASlot($this, '/checkouts/desk', 'wt-desk-441');
    $second = claimsASlot($this, '/checkouts/desk-clone', 'wt-desk-clone-441');

    $claimed = [claimOf($first), claimOf($second)];

    expect($claimed[0]['slot'])->not->toBe($claimed[1]['slot'])
        ->and(array_intersect(array_values($claimed[0]['ports']), array_values($claimed[1]['ports'])))->toBe([]);

    touch($this->gate);

    expect($first->wait())->toBe(0, worktreeFailure($first))
        ->and($second->wait())->toBe(0, worktreeFailure($second));
});

it('makes a second run for the same worktree wait, then re-enters the same slot', function () {
    $first = claimsASlot($this, '/checkouts/desk', 'wt-desk-441');
    $claimed = claimOf($first);

    // Started, past its own set-up, and now sitting on the worktree lock the
    // first run is holding for the whole of its bootstrap.
    $second = claimsASlot($this, '/checkouts/desk', 'wt-desk-441');
    waitForOutput($second, 'claiming wt-desk-441', stderr: true);

    usleep(1_500_000);

    expect($second->getOutput())->toBe('')
        ->and($second->isRunning())->toBeTrue();

    touch($this->gate);

    expect($first->wait())->toBe(0, worktreeFailure($first))
        ->and($second->wait())->toBe(0, worktreeFailure($second))
        ->and(claimOf($second))->toBe($claimed);
});

it('leaves no lock behind when a run is interrupted in the middle of its work', function () {
    $run = claimsASlot($this, '/checkouts/desk', 'wt-desk-441');
    $claimed = claimOf($run);

    expect($this->home.'/locks/wt-desk-441.lock')->toBeDirectory();

    $run->signal(SIGINT);
    $run->wait();

    expect($run)->toHaveExited(130)
        ->and($this->home.'/locks/wt-desk-441.lock')->not->toBeDirectory()
        ->and($this->home.'/registry.lock')->not->toBeDirectory()
        ->and(entriesIn($this->home))->toHaveKey('wt-desk-441');

    // The slot survives the interruption on purpose: the next run resumes it
    // rather than allocating a second block for the same half-built worktree.
    expect(entriesIn($this->home)['wt-desk-441']['slot'])->toBe($claimed['slot']);
});

it('never leaves the registry half-written for a concurrent reader', function () {
    $writers = [
        writesTheRegistry($this, 'alpha', 20),
        writesTheRegistry($this, 'beta', 20),
        writesTheRegistry($this, 'gamma', 20),
    ];

    $reads = 0;
    $partial = null;

    while (array_filter($writers, fn (Process $writer) => $writer->isRunning()) !== []) {
        $contents = @file_get_contents($this->home.'/registry.json');

        if ($contents === false || trim($contents) === '') {
            continue;
        }

        $reads++;

        if (! is_array(json_decode($contents, true))) {
            $partial = $contents;

            break;
        }
    }

    foreach ($writers as $writer) {
        expect($writer->wait())->toBe(0, worktreeFailure($writer));
    }

    // Atomic within the filesystem: the temporary file is written in the
    // registry directory and renamed within it, so a reader sees the old
    // registry or the new one, never half of either.
    expect($partial)->toBeNull()
        ->and($reads)->toBeGreaterThan(10)
        // 60 entries, so no writer's read-modify-write lost another's.
        ->and(entriesIn($this->home))->toHaveCount(60)
        ->and(glob($this->home.'/.registry-*.tmp'))->toBe([]);
});

it('writes without going through the system temp directory', function () {
    // `mktemp` would have landed in $TMPDIR — which is not guaranteed to share
    // a filesystem with $HOME, making the `mv` that follows a copy-then-unlink
    // rather than a rename. A registry write must not depend on it at all.
    $elsewhere = temporaryDirectory('worktree-tmp');
    chmod($elsewhere, 0555);

    $writer = writesTheRegistry($this, 'alpha', 5, ['TMPDIR' => $elsewhere]);

    expect($writer->wait())->toBe(0, worktreeFailure($writer))
        ->and(entriesIn($this->home))->toHaveCount(5)
        ->and(array_diff((array) scandir($elsewhere), ['.', '..']))->toBe([]);

    chmod($elsewhere, 0755);
    deleteDirectory($elsewhere);
})->skip(
    fn () => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root writes into a read-only directory regardless',
);

/**
 * A started `claims-a-slot.php`, holding its locks until the test's gate file
 * appears.
 */
function claimsASlot(object $test, string $repo, string $key): Process
{
    $process = new Process(
        [PHP_BINARY, packagePath('tests/Fixtures/claims-a-slot.php'), $repo, $key, $test->gate],
        null,
        worktreeEnvironment(['WORKTREE_PORT_BASE' => (string) $test->base]),
    );

    $process->setTimeout(60);
    $process->start();

    return $process;
}

/**
 * @param  array<string, string|false>  $environment
 */
function writesTheRegistry(object $test, string $prefix, int $count, array $environment = []): Process
{
    $process = new Process(
        [PHP_BINARY, packagePath('tests/Fixtures/writes-the-registry.php'), $prefix, (string) $count],
        null,
        worktreeEnvironment(['WORKTREE_PORT_BASE' => (string) $test->base, ...$environment]),
    );

    $process->setTimeout(60);
    $process->start();

    return $process;
}

/**
 * What a run claimed, once it has claimed it.
 *
 * @return array{key: string, slot: int, ports: array<string, int>, path: string}
 */
function claimOf(Process $process): array
{
    return json_decode(waitForOutput($process, '"slot"'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, array{slot: int, ports: array<string, int>}>
 */
function entriesIn(string $home): array
{
    return json_decode((string) file_get_contents($home.'/registry.json'), true, flags: JSON_THROW_ON_ERROR);
}
