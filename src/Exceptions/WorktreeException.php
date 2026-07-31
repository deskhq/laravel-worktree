<?php

namespace DeskHQ\LaravelWorktree\Exceptions;

use DeskHQ\LaravelWorktree\Console\ExitCode;
use RuntimeException;

/**
 * An operational failure the user is expected to read and act on.
 *
 * The entry point turns these into `error: <message>` on stderr and an exit
 * code of {@see ExitCode::Failure}; anything else that escapes is a bug, and
 * keeps its stack trace.
 */
class WorktreeException extends RuntimeException {}
