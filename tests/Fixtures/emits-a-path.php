<?php

/**
 * A stand-in for `create`: diagnostics, a noisy subprocess, then the one line of
 * machine-readable output the caller consumes with `cd "$(...)"`.
 */

use DeskHQ\LaravelWorktree\Console\Emitter;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$output = new Output(STDERR);
$runner = new ProcessRunner($output);

$output->line('creating worktree');
$runner->run([PHP_BINARY, __DIR__.'/noisy-step.php']);
$runner->capture([PHP_BINARY, __DIR__.'/noisy-step.php']);
$output->line('done');

(new Emitter)->emit('/Users/agent/project-worktrees/441-fix-login');
