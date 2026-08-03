<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Git\Anchor;

/**
 * A subcommand of the host binary.
 *
 * Commands receive the resolved {@see Anchor} and {@see Configuration} rather
 * than resolving them themselves, so "where is the repository" and "what does
 * it want" are each answered once per run, before any command has had the
 * chance to do work in the wrong place or with the wrong ports.
 */
interface Command
{
    /**
     * The name the user types: `create`, `list`, `remove`, `reap`, `unlock`.
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
    public function run(array $arguments, Anchor $anchor, Configuration $config): int;
}
