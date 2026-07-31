<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * Releases whatever the run is holding — locks, temporary files, half-written
 * registry entries — however the run ends.
 *
 * The bash original set three traps: EXIT ran the cleanup, INT and TERM only
 * triggered an exit with the conventional 128 + signal code so the EXIT trap
 * still fired. This reproduces that: releases run on any shutdown, and an
 * interrupted bootstrap can never leave a lock behind for the next run to trip
 * over.
 */
final class ShutdownHandler
{
    /** @var list<callable(): void> */
    private array $releases = [];

    private bool $listening = false;

    public function __construct(private readonly Output $output) {}

    /**
     * Register a release to run when the process ends, for any reason.
     */
    public function onShutdown(callable $release): void
    {
        $this->releases[] = $release;
    }

    /**
     * Install the shutdown and signal handlers.
     *
     * Called from {@see Application::run()} rather than at load time, so
     * requiring this package's classes never mutates a host application's
     * signal handling.
     */
    public function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        register_shutdown_function(function (): void {
            $this->release();
        });

        // ext-pcntl is a hard requirement of this package precisely so this is
        // unconditional: a run that could be interrupted without releasing its
        // locks is a run that strands the next one.
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn () => $this->terminate(ExitCode::Interrupted));
        pcntl_signal(SIGTERM, fn () => $this->terminate(ExitCode::Terminated));
    }

    /**
     * Run every pending release, most recently registered first.
     *
     * Each release is drained before it runs, so a second call — the shutdown
     * function firing after a signal handler already released, say — is a
     * no-op, and a release that throws cannot strand the ones behind it.
     */
    public function release(): void
    {
        while ($this->releases !== []) {
            $release = array_pop($this->releases);

            try {
                $release();
            } catch (\Throwable $e) {
                $this->output->error('cleanup failed: '.$e->getMessage());
            }
        }
    }

    private function terminate(int $code): never
    {
        $this->release();

        exit($code);
    }
}
