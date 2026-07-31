<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Env;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * How wide the window is, for the one rendering that has to fit inside it.
 *
 * PHP cannot ask a terminal its size — there is no `ioctl` on the host, and the
 * `posix` extension this package does not require would not answer this anyway
 * — so the question goes where a shell asks it: `stty`, reading the controlling
 * terminal rather than this process's stdout, because a `worktree list` whose
 * stdout is a pipe is not the run that gets here in the first place.
 *
 * `COLUMNS` comes first, as the conventional override and as what makes this
 * testable: a terminal allocated by a test harness is not the harness's
 * controlling terminal, so `stty` there would answer for the developer's window
 * or for nothing at all.
 *
 * `tput cols` is deliberately not consulted. ncurses sizes the terminal from
 * *stdout*, which is a pipe every time this package asks — so it would answer
 * with the terminfo entry's static width, which is 80 on almost every entry
 * there is, and be indistinguishable from the fallback below while looking like
 * a measurement.
 */
final readonly class Terminal
{
    /**
     * What a terminal that cannot be measured is assumed to be. The width every
     * VT100 descendant starts at, and what `column` itself falls back to.
     */
    public const int Fallback = 80;

    public function __construct(private ProcessRunner $runner) {}

    public function width(): int
    {
        $configured = Env::get('COLUMNS');

        if (is_string($configured) && preg_match('/\A[1-9]\d*\z/', $configured) === 1) {
            return (int) $configured;
        }

        // `< /dev/tty` rather than the inherited stdin: a subprocess started
        // here is given pipes on all three descriptors ({@see ProcessRunner}),
        // and `stty` reading a pipe answers that it is not a terminal. Its
        // complaint is discarded for the reason `gh`'s is — a machine with no
        // controlling terminal is answering the question, not failing.
        $size = $this->runner->consult(['sh', '-c', 'stty size < /dev/tty']);

        if ($size->succeeded() && preg_match('/\A\d+\s+([1-9]\d*)/', trim($size->output), $matches) === 1) {
            return (int) $matches[1];
        }

        return self::Fallback;
    }
}
