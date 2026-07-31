<?php

use Symfony\Component\Process\Process;

$binary = __DIR__.'/../bin/worktree';

it('registers the binary composer installs into vendor/bin', function () use ($binary) {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['bin'])->toContain('bin/worktree')
        ->and($binary)->toBeReadableFile()
        ->and(is_executable($binary))->toBeTrue();
});

it('prints its usage on stderr and exits with EX_USAGE', function () use ($binary) {
    $process = new Process([PHP_BINARY, $binary]);
    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('Usage:');
});
