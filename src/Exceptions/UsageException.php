<?php

namespace DeskHQ\LaravelWorktree\Exceptions;

use DeskHQ\LaravelWorktree\Console\ExitCode;

/**
 * "You called me wrong", as opposed to "the run failed".
 *
 * The bash original returned `EX_USAGE` for the first and 1 for the second, and
 * shell callers depend on being able to tell them apart: a script that mistyped
 * a flag has nothing to retry, while one whose bootstrap failed has. Carrying
 * the distinction on the exception rather than in each command's return value
 * keeps it with the message that explains it — the entry point turns one into
 * {@see ExitCode::Usage} and the command's own invocation line, and the other
 * into {@see ExitCode::Failure}.
 */
final class UsageException extends WorktreeException {}
