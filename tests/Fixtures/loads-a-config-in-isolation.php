<?php

/**
 * Reads a repository's configuration in a process with only this package
 * autoloaded — no Composer autoloader, so no framework, no helpers and no
 * application classes are reachable at all.
 *
 * That is the documented constraint on `config/worktree.php` made executable:
 * a config that reaches for an application class, a container binding or a
 * facade fails here, and the package's own shipped config must not.
 *
 * Usage: loads-a-config-in-isolation.php <main root>
 */

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

spl_autoload_register(function (string $class): void {
    $prefix = 'DeskHQ\\LaravelWorktree\\';

    // Anything else is simply not here — which is the point. A missing class is
    // reported as such, naming it, rather than quietly resolving from a vendor
    // directory the host binary may not be able to rely on.
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    require dirname(__DIR__, 2).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
});

try {
    $configuration = Configuration::load($argv[1]);
} catch (WorktreeException $e) {
    fwrite(STDERR, 'error: '.$e->getMessage()."\n");

    exit(1);
}

echo json_encode($configuration, JSON_THROW_ON_ERROR)."\n";
