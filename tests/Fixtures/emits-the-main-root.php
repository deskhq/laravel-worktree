<?php

/**
 * Prints the main working tree the binary would anchor to, from wherever it was
 * invoked — the main checkout, or one of its worktrees.
 */

use DeskHQ\LaravelWorktree\Console\Emitter;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$anchor = Anchor::resolve(new ProcessRunner(new Output(STDERR)), (string) getcwd());

(new Emitter)->emit($anchor->mainRoot);
