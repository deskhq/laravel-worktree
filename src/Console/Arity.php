<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Exceptions\UsageException;

/**
 * What a command takes besides its flags: how many positional arguments, and
 * whether it can run without the first one.
 *
 * Declared where the accepted flags are declared, and refused for the same
 * reason — `create 441 main extra` and `create 441 --refesh` are both the
 * command being called wrong, and both are answered before any work is done.
 *
 * The sentences live here rather than in each command because nine commands
 * used to write the same one, and they should go on reading alike. Each
 * refusal says the command's own name, which is why every constructor is
 * handed it: a command calls `Arity::name($this->name(), …)`, so the name in
 * the refusal is the name the binary dispatches on rather than a second copy
 * of it.
 *
 * ## What stays with the command
 *
 * A run whose name is optional *because of a flag* — `stop --all`,
 * `unlock --all` — declares only how many arguments it takes and keeps its own
 * "name the thing" refusal: what stands in for the name is a flag, and which
 * flags mean what is the command's business. {@see Arity::optional()} is that
 * declaration. So is `create`, whose missing-name sentence changes with
 * `--pr`.
 */
final readonly class Arity
{
    /**
     * @param  string  $command  The name the user typed, so a refusal can say it.
     * @param  int  $most  How many positional arguments are allowed at all.
     * @param  string  $surplus  What to say when there are more, minus the command's name.
     * @param  string|null  $missing  What to say when the first is absent or blank; null when it may be.
     */
    private function __construct(
        private string $command,
        private int $most,
        private string $surplus,
        private ?string $missing,
    ) {}

    /**
     * Options, and nothing else: `list`, `reap`, `init`, `doctor`.
     */
    public static function options(string $command): self
    {
        return new self($command, 0, 'takes no arguments, only options', null);
    }

    /**
     * One worktree name, which the run is about: `start`, `remove`, `path`.
     *
     * @param  string  $purpose  What the name is wanted for, as `to start` — the
     *                           middle of "name the worktree *to start*: an
     *                           issue number, or a branch name".
     */
    public static function name(string $command, string $purpose): self
    {
        return new self(
            $command,
            1,
            'takes one name',
            "name the worktree $purpose: an issue number, or a branch name",
        );
    }

    /**
     * One argument the command can do without: `stop` and `unlock`, which take
     * `--all` instead, and `shell-init`, which reads `$SHELL` instead.
     *
     * @param  string  $noun  What that one argument is, for the refusal when
     *                        there are two of them.
     */
    public static function optional(string $command, string $noun = 'name'): self
    {
        return new self($command, 1, "takes one $noun", null);
    }

    /**
     * A name and, at most, the base to fork it from: `create`, the one command
     * whose shape the module docblock spells out in full.
     */
    public static function nameAndBase(string $command): self
    {
        return new self($command, 2, 'takes a name and, at most, a base to fork from', null);
    }

    /**
     * @param  list<string>  $positional
     *
     * @throws UsageException on too many, or on a first one that is not there.
     */
    public function verify(array $positional): void
    {
        $first = $positional[0] ?? null;

        // An empty argument is `start "$ISSUE"` with nothing in `$ISSUE`, and is
        // the same mistake as no argument at all — answered here rather than by
        // the naming layer a moment later, which would call it operational.
        if ($this->missing !== null && ($first === null || trim($first) === '')) {
            throw new UsageException($this->missing);
        }

        if (count($positional) > $this->most) {
            throw new UsageException("$this->command $this->surplus; given ".implode(' ', $positional));
        }
    }
}
