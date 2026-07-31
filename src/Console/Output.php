<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * Diagnostics. Everything written here goes to stderr, never to stdout.
 *
 * This is where the whole output of every subprocess ends up too: the process
 * runner is handed one of these and streams git, Composer, npm, artisan, Sail
 * and docker into it, so a call site cannot leak a subprocess onto stdout by
 * forgetting to redirect it. The only route to real stdout is {@see Emitter}.
 */
final class Output
{
    /** @var resource */
    private $stream;

    /**
     * @param  resource|null  $stream  Defaults to STDERR.
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDERR;
    }

    public function write(string $text): void
    {
        fwrite($this->stream, $text);
    }

    public function line(string $message = ''): void
    {
        $this->write($message."\n");
    }

    public function error(string $message): void
    {
        $this->line('error: '.$message);
    }
}
