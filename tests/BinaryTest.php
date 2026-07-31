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

    expect($process->getExitCode())->toBe(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('Usage:')
        ->and($process->getErrorOutput())->toContain('create');
});

it('treats an explicit request for help as a success', function () {
    $process = worktree(['--help']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('Usage:');
});

it('rejects an unknown command as a usage error', function () {
    $process = worktree(['destroy']);

    expect($process->getExitCode())->toBe(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('error: unknown command: destroy');
});

it('names the commands the roadmap has not built yet, rather than denying them', function () {
    $process = worktree(['create', '441']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('error: create is not implemented yet');
});

it('refuses to run inside the container and creates nothing', function () {
    $process = worktree(['create', '441'], env: ['LARAVEL_SAIL' => '1']);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain('worktree must run on the host, not inside the container.')
        ->toContain('Use:  ./vendor/bin/worktree create 441');
});

it('fails clearly when it is not run inside a git repository', function () {
    $directory = temporaryDirectory('worktree-elsewhere');

    $process = worktree(['create', '441'], cwd: $directory);

    expect($process->getExitCode())->toBe(1)
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
