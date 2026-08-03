<?php

/**
 * Hammers the registry with writes, under the registry lock, the way several
 * concurrent `create` runs would.
 *
 * Usage: writes-the-registry.php <key prefix> <count>
 * Environment: WORKTREE_HOME.
 */

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Locks;
use DeskHQ\LaravelWorktree\Registry\Ports;
use DeskHQ\LaravelWorktree\Registry\Registry;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$prefix, $count] = [$argv[1], (int) $argv[2]];

$config = Configuration::fromArray([]);
$output = new Output(STDERR);
$shutdown = new ShutdownHandler($output);
$shutdown->listen();

$registry = Registry::fromConfiguration($config);
$ports = Ports::fromConfiguration($config);
$locks = new Locks($config->home, $shutdown, $output);

for ($index = 0; $index < $count; $index++) {
    $key = $prefix.'-'.$index;

    $locks->registry()->hold(fn () => $registry->put(new Entry(
        $key,
        $index,
        '/checkouts/desk',
        (string) $index,
        'branch-'.$index,
        '/checkouts/desk-worktrees/'.$key,
        $ports->forSlot($index),
        gmdate('Y-m-d\TH:i:s\Z'),
    )));
}

$output->line("wrote $count entries");
