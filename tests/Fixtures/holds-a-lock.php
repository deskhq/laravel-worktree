<?php

/**
 * A run that holds a lock and then waits to be interrupted.
 *
 * Stands in for a bootstrap: the lock is a directory, as in the original, and
 * the release is registered with the shutdown handler rather than performed at
 * the end of a happy path.
 */

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$lock = $argv[1];

$output = new Output(STDERR);
$shutdown = new ShutdownHandler($output);
$shutdown->listen();

mkdir($lock, 0755, true);
$shutdown->onShutdown(fn () => @rmdir($lock));

$output->line('lock held');

while (true) {
    usleep(20_000);
}
