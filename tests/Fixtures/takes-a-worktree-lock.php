<?php

/**
 * A run that takes a real worktree lock — owner record and all — and then stays
 * in the slow work until the gate lets it go.
 *
 * Unlike `holds-a-lock.php`, which stands in for the bare directory an older
 * version of this package left behind, this one takes the lock the way `create`
 * does, so what a second run finds is a lock that names a process that really is
 * running.
 *
 * Usage: takes-a-worktree-lock.php <home> <key> <gate file>
 */

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Registry\Locks;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$home, $key, $gate] = [$argv[1], $argv[2], $argv[3]];

$output = new Output(STDERR);
$shutdown = new ShutdownHandler($output);
$shutdown->listen();

(new Locks($home, $shutdown, $output))->worktree($key)->acquire();

$output->line('lock held');

// `clearstatcache()` because PHP caches the failed stat, and a poll that never
// sees the gate appear is a test that hangs.
while (! file_exists($gate)) {
    usleep(20_000);

    clearstatcache(true, $gate);
}
