<?php

namespace DeskHQ\LaravelWorktree\Process;

/**
 * The outcome of a captured subprocess: its exit code, and the stdout the
 * caller asked to read. Its stderr is not here — it went to the run's
 * diagnostics as it happened, like every other subprocess.
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * The captured stdout with trailing newlines removed, which is what every
     * `git rev-parse`-shaped call actually wants.
     */
    public function trimmedOutput(): string
    {
        return trim($this->output);
    }
}
