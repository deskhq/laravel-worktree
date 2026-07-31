<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * The real shell: a subprocess per step, through the one runner this package
 * starts processes with.
 *
 * Nothing else. Which Sail a `sail:` step reaches, and what it has to be told
 * about the container before it will behave — `SAIL_SKIP_CHECKS`, `WWWUSER`,
 * `APP_SERVICE` — is the runtime's business rather than the pipeline's, and
 * arrives with it.
 */
final readonly class ProcessShell implements Shell
{
    public function __construct(private ProcessRunner $runner) {}

    public function run(string $commandLine, string $path): int
    {
        return $this->runner->shell($commandLine, $path);
    }
}
