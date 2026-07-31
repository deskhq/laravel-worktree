<?php

/**
 * Reads a repository's configuration the way the host binary does, and emits it
 * as JSON — so a test can assert on what `vendor/bin/worktree` would see,
 * including the environment it saw it in.
 *
 * Usage: dumps-the-config.php <main root>
 */

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $configuration = Configuration::load($argv[1]);
} catch (WorktreeException $e) {
    fwrite(STDERR, 'error: '.$e->getMessage()."\n");

    exit(1);
}

echo json_encode($configuration, JSON_THROW_ON_ERROR)."\n";
