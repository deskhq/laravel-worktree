<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

/**
 * Where a step's command line actually goes.
 *
 * One method, so the pipeline can be driven against something that runs
 * nothing: ordering, skipping, sentinels and degradation are all decisions this
 * package makes, and a test that has to start Docker to observe them is a test
 * nobody runs. {@see ProcessShell} is the implementation the binary uses.
 */
interface Shell
{
    /**
     * Run $commandLine with $path as its working directory, and hand back its
     * exit code. Everything it writes belongs in the run's diagnostics.
     */
    public function run(string $commandLine, string $path): int;
}
