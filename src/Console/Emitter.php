<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * The one route to the caller's stdout, for machine-readable output only: the
 * worktree path from `create`, the table from `list`.
 *
 * Nothing else in this package may write to stdout — a structural test asserts
 * that this is the only class that names the stream. The guarantee matters
 * because `cd "$(vendor/bin/worktree create 441)"` has to keep working on a run
 * that also produced megabytes of Composer and npm output (the-desk#1043).
 */
final class Emitter
{
    /** @var resource */
    private $stream;

    /**
     * @param  resource|null  $stream  Defaults to STDOUT.
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDOUT;
    }

    public function emit(string $line): void
    {
        fwrite($this->stream, $line."\n");
    }

    /**
     * Whether a person is reading this stream, rather than a pipe, a `$(…)` or a
     * CI log.
     *
     * Asked because `list` has two renderings and this is what chooses between
     * them: the tab-separated rows a script parses, or a table fitted to the
     * window in front of somebody. It lives here for the same reason the writes
     * do — this is the only class allowed to name stdout, so it is the only one
     * in a position to answer questions about it.
     */
    public function isInteractive(): bool
    {
        return stream_isatty($this->stream);
    }
}
