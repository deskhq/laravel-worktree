<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;

/**
 * A command that still has something to say about a repository whose
 * configuration would not load.
 *
 * {@see Application} hands every command a {@see Configuration} because no
 * command can do its work without one: a `create` with no settings does not know
 * how many slots there are, and a `list` does not know what the ports are called.
 * So a `config/worktree.php` that throws ends the run before any command is
 * reached, which is right — for every command but one.
 *
 * `doctor`'s subject *is* that failure. Reporting it as an unreadable config and
 * then saying which of the remaining checks could not be made because of it is
 * the whole answer somebody publishing that file for the first time wants, and
 * the alternative is a single line of refusal that names one key and stops.
 */
interface Diagnostic extends Command
{
    /**
     * Run against a repository whose `config/worktree.php` did not load.
     *
     * @param  list<string>  $arguments
     * @param  WorktreeException  $unreadable  Exactly what the run would have failed with.
     * @return int One of the {@see ExitCode} constants.
     */
    public function diagnose(array $arguments, Anchor $anchor, WorktreeException $unreadable): int;
}
