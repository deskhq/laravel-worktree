<?php

use Symfony\Component\Process\Process;

it('registers the binary composer installs into vendor/bin', function () {
    $composer = json_decode((string) file_get_contents(packagePath('composer.json')), true);

    expect($composer['bin'])->toContain('bin/worktree')
        ->and(packagePath('bin/worktree'))->toBeReadableFile()
        ->and(is_executable(packagePath('bin/worktree')))->toBeTrue();
});

it('prints its usage on stderr and exits with EX_USAGE when given no command', function () {
    $process = worktree();

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('Usage:')
        ->and($process->getErrorOutput())->toContain('create');
});

it('treats an explicit request for help as a success', function () {
    $process = worktree(['--help']);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('Usage:');
});

it('rejects an unknown command as a usage error', function () {
    $process = worktree(['destroy']);

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('error: unknown command: destroy');
});

it('lists every command it dispatches, with what each one takes', function () {
    $usage = worktree(['--help'])->getErrorOutput();

    expect($usage)
        ->toContain('create <slug> [base] [--refresh] [--json]')
        ->toContain('path <slug> [--json]')
        ->toContain('list [--all] [--json]')
        ->toContain('stop <slug> | --all | --all-except <slug> [--all-repos]')
        ->toContain('start <slug>')
        ->toContain('remove <slug>')
        ->toContain('reap [--all] [--dry-run] [--yes]');
});

it('refuses to run inside the container and creates nothing', function () {
    $process = worktree(['create', '441'], env: ['LARAVEL_SAIL' => '1']);

    expect($process)->toHaveFailed()
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('worktree must run on the host, not inside the container.')
        ->toContain('Use:  ./vendor/bin/worktree create 441');
});

it('fails clearly when it is not run inside a git repository', function () {
    $directory = temporaryDirectory('worktree-elsewhere');

    $process = worktree(['create', '441'], cwd: $directory);

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('error: not inside a git repository');

    deleteDirectory($directory);
});

it('anchors to the main working tree from the main checkout and from a worktree alike', function () {
    $repository = temporaryRepository();
    $attached = $repository.'-attached';

    (new Process(['git', '-C', $repository, 'worktree', 'add', '-b', 'feature', $attached]))->mustRun();

    $mainRootSeenFrom = function (string $cwd): string {
        $process = new Process([PHP_BINARY, packagePath('tests/Fixtures/emits-the-main-root.php')], $cwd);
        $process->mustRun();

        return trim($process->getOutput());
    };

    expect($mainRootSeenFrom($repository))->toBe($repository)
        ->and($mainRootSeenFrom($attached))->toBe($repository);

    (new Process(['git', '-C', $repository, 'worktree', 'remove', '--force', $attached]))->run();
    deleteDirectory($attached);
    deleteDirectory($repository);
});
