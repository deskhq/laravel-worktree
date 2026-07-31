<?php

use Symfony\Component\Process\Process;

/**
 * Start a run that holds a lock, wait until it has it, then interrupt it.
 */
function interruptedRunHolding(string $lock, int $signal): Process
{
    $process = new Process([PHP_BINARY, packagePath('tests/Fixtures/holds-a-lock.php'), $lock]);
    $process->setTimeout(30);
    $process->start();
    $process->waitUntil(fn (string $type, string $chunk) => str_contains($chunk, 'lock held'));

    expect($lock)->toBeDirectory();

    $process->signal($signal);
    $process->wait();

    return $process;
}

it('releases every held lock when the run is interrupted', function () {
    $lock = temporaryDirectory('worktree-locks').'/registry.lock';

    $process = interruptedRunHolding($lock, SIGINT);

    expect($process->getExitCode())->toBe(130)
        ->and(is_dir($lock))->toBeFalse();

    deleteDirectory(dirname($lock));
});

it('releases every held lock when the run is terminated', function () {
    $lock = temporaryDirectory('worktree-locks').'/registry.lock';

    $process = interruptedRunHolding($lock, SIGTERM);

    expect($process->getExitCode())->toBe(143)
        ->and(is_dir($lock))->toBeFalse();

    deleteDirectory(dirname($lock));
});
