<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Git\Anchor;

/**
 * A subcommand of the host binary.
 *
 * Commands receive the resolved {@see Anchor} rather than resolving it
 * themselves, so "where is the repository" is answered once per run, before any
 * command has had the chance to do work in the wrong place.
 */
interface Command
{
    /**
     * The name the user types: `create`, `list`, `remove`, `reap`.
     */
    public function name(): string;

    /**
     * The argument spec and one-line summary shown in the usage text, as a
     * `[spec, summary]` pair.
     *
     * @return array{string, string}
     */
    public function usage(): array;

    /**
     * @param  list<string>  $arguments
     * @return int One of the {@see ExitCode} constants.
     */
    public function run(array $arguments, Anchor $anchor): int;
}
