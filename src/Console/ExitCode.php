<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * The exit codes the host binary may return.
 *
 * `Usage` is `EX_USAGE` from sysexits.h, as the bash original returned it, so
 * shell callers can keep distinguishing "you called me wrong" from "the run
 * failed". `Interrupted` and `Terminated` are the conventional 128 + signal
 * codes, returned by the signal handlers in {@see ShutdownHandler} after every
 * held lock has been released.
 */
final readonly class ExitCode
{
    public const int Success = 0;

    public const int Failure = 1;

    public const int Usage = 64;

    public const int Interrupted = 130;

    public const int Terminated = 143;

    private function __construct() {}
}
