<?php

/**
 * What `Env` believes the shell exported, after a repository's `.env` has been
 * loaded over it.
 *
 * A process of its own, because the answer is captured once in a static and the
 * whole defect this guards is a *second* reading disagreeing with the first.
 * Asserting it in-process would either pass on a value another test captured or
 * poison the tests that follow.
 *
 * Usage: reports-the-exported-environment.php <main root>
 */

use DeskHQ\LaravelWorktree\Config\Env;

require dirname(__DIR__, 2).'/vendor/autoload.php';

Env::load($argv[1]);

echo json_encode([
    // What decides whether the worktree gets `.env` or `.env.<APP_ENV>`.
    'exported' => Env::exportedEnvironment(),
    // What `config/worktree.php` reads, which the file may legitimately set.
    'app_env' => Env::get('APP_ENV'),
], JSON_THROW_ON_ERROR)."\n";
