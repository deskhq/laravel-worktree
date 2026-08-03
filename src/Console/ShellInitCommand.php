<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Shell\Dialect;
use DeskHQ\LaravelWorktree\Shell\Script;
use DeskHQ\LaravelWorktree\Shell\Vocabulary;

/**
 * The shell integration, printed for `eval` (#60).
 *
 * ```bash
 * eval "$(./vendor/bin/worktree shell-init)"     # in ~/.zshrc or ~/.bashrc
 * ```
 *
 * What that installs is a `wt` function and completion for both names, and the
 * case for it is the package's own usage line:
 *
 * ```bash
 * cd "$(./vendor/bin/worktree create 441)"
 * ```
 *
 * Which is correct, and is also thirty-eight characters of ceremony around one
 * argument — typed several times a day, from directories where the relative path
 * to the binary is different each time, for a slug the user is holding in their
 * head because nothing offers it. Completing that slug is the largest ergonomic
 * win this package has left: the names are long, generated from issue titles,
 * and nobody remembers whether it was `441-fix-login` or `441-login-fix`.
 *
 * ## The output is the exception, and deliberately so
 *
 * Every other command puts prose on stderr and only machine-readable answers on
 * stdout. A whole shell script on stdout is exactly that contract rather than an
 * exception to it: it is read by `eval`, which is a machine, and it is the same
 * promise `create`'s path and `list`'s table hold to. Nothing is written to
 * stderr at all, because the ordinary way to run this is from an rc file, and a
 * note printed there is a note printed on every new terminal forever.
 *
 * ## Which shell
 *
 * The argument, when there is one; `$SHELL` when there is not. A login shell
 * this cannot write for is a usage error naming what it can, rather than a bash
 * script emitted at a fish and failing a line at a time.
 *
 * ## It needs no repository ({@see Rootless})
 *
 * An rc file runs in `~`. Anchoring first would mean `not inside a git
 * repository` on every new terminal, and the script does not need a repository
 * anyway: what it bakes in is the absolute path of the binary that printed it,
 * which is what makes `wt` work from anywhere — and makes the failure from
 * outside a repository this package's own message rather than `command not
 * found`.
 */
final readonly class ShellInitCommand implements Rootless
{
    public function __construct(
        private Emitter $emitter,
        /** The binary's own dispatch, which is where the completion's vocabulary comes from. */
        private Application $application,
    ) {}

    public function name(): string
    {
        return 'shell-init';
    }

    public function usage(): array
    {
        return [
            '[bash|zsh]',
            "Emit the 'wt' function and completion, to eval in your shell's rc file.",
        ];
    }

    /**
     * The anchor and the configuration are ignored, which is the whole of what
     * {@see Rootless} declares — and why this is never the entry point the
     * binary reaches for.
     */
    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        return $this->emit($arguments);
    }

    public function emit(array $arguments): int
    {
        $invocation = Arguments::parse($arguments, [], takes: Arity::optional($this->name(), 'shell'));
        $named = $invocation->at(0);

        $dialect = $named !== null ? Dialect::named($named) : Dialect::current();

        if ($dialect === null) {
            throw new UsageException(
                'could not tell which shell this is from $SHELL; name it — '.Dialect::known()
            );
        }

        $this->emitter->emit(Script::for(
            $dialect,
            Vocabulary::of($this->application->specifications()),
            self::binary(),
        )->render());

        return ExitCode::Success;
    }

    /**
     * The binary that is running, absolutely — the one thing the script cannot
     * work out for itself, and the one it is useless without.
     *
     * Resolved rather than taken as typed: `vendor/bin/worktree` is a relative
     * path *and* usually a symlink into the package, and a script that baked
     * either would break the moment the cursor moved out of the checkout it was
     * generated from.
     */
    private static function binary(): string
    {
        $invoked = $_SERVER['argv'][0] ?? null;
        $resolved = is_string($invoked) && $invoked !== '' ? realpath($invoked) : false;

        // The package's own copy, for a run whose `argv` says nothing usable —
        // `php -r`, or a caller that rewrote it. It is executable, and it is the
        // file `vendor/bin/worktree` points at anyway.
        return $resolved === false ? dirname(__DIR__, 2).'/bin/worktree' : $resolved;
    }
}
