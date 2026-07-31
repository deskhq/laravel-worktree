<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Exceptions\UsageException;

/**
 * What a command was called with: its positional arguments, and its flags.
 *
 * Long flags only, and only the ones the command declares. A flag it does not
 * know is refused by name rather than ignored — `create 441 --refesh` that
 * silently re-entered a ready worktree without refreshing it would be a run
 * that did the opposite of what was asked, with nothing on screen to say so.
 *
 * Deliberately not a general option parser: there are no short flags, no
 * values, and no `--flag=value`, because nothing this package exposes needs
 * them. Every command here is `<command> <name> [base] [--flags]`.
 */
final readonly class Arguments
{
    /**
     * @param  list<string>  $positional  In the order they were given.
     * @param  list<string>  $flags  The ones that were given, without their dashes.
     */
    private function __construct(
        public array $positional,
        private array $flags,
    ) {}

    /**
     * @param  list<string>  $arguments  The raw argv, with the command name already taken off.
     * @param  list<string>  $accepted  The flags this command understands, without their dashes.
     *
     * @throws UsageException on a flag the command does not accept.
     */
    public static function parse(array $arguments, array $accepted = []): self
    {
        $positional = [];
        $flags = [];

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '-')) {
                $positional[] = $argument;

                continue;
            }

            $flag = ltrim($argument, '-');

            if (! in_array($flag, $accepted, true)) {
                throw new UsageException($accepted === []
                    ? "this command takes no options, and '$argument' is one"
                    : "unknown option '$argument'; this command takes "
                      .implode(', ', array_map(fn (string $known): string => '--'.$known, $accepted)));
            }

            $flags[] = $flag;
        }

        return new self($positional, $flags);
    }

    /**
     * The positional argument at $index, or null when there was none.
     */
    public function at(int $index): ?string
    {
        return $this->positional[$index] ?? null;
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
