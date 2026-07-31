<?php

/**
 * A stand-in for `create`, as far as the registry is concerned: take this
 * worktree's lock, claim (or resume) a slot, then stay in the slow work until
 * told to finish — or until something interrupts it.
 *
 * That is the shape the locking exists for. Two of these running at once are
 * what the concurrency tests actually assert against: real processes, real
 * `mkdir` locks, one registry file, no Docker.
 *
 * Usage: claims-a-slot.php <repo> <key> [<gate file>]
 * Environment: WORKTREE_HOME, and the usual WORKTREE_SLOTS / WORKTREE_PORT_BASE.
 */

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Console\Emitter;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Registry\Allocator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$repo, $key] = [$argv[1], $argv[2]];
$gate = $argv[3] ?? null;

$output = new Output(STDERR);
$shutdown = new ShutdownHandler($output);
$shutdown->listen();

$allocator = Allocator::fromConfiguration(Configuration::fromArray([]), $shutdown, $output);

// Printed before the lock is even attempted, so a test can tell "still starting
// up" apart from "waiting for the worktree that has it".
$output->line("claiming $key");

try {
    $entry = $allocator->allocate($key, $repo, 'slug', 'branch', $repo.'-worktrees/'.$key);
} catch (WorktreeException $e) {
    $output->error($e->getMessage());

    exit(1);
}

(new Emitter)->emit((string) json_encode([
    'key' => $entry->key,
    'slot' => $entry->slot,
    'ports' => $entry->ports,
    'path' => $entry->path,
]));

// The bootstrap this stands in for: minutes of git, Composer, Sail and npm,
// with the worktree lock still held. `clearstatcache()` because PHP caches the
// failed stat, and a poll that never sees the gate appear is a test that hangs.
while ($gate !== null && ! file_exists($gate)) {
    usleep(20_000);

    clearstatcache(true, $gate);
}
