<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

use DeskHQ\LaravelWorktree\Config\Assignments;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * The `when:` of a step — three questions, and deliberately no more.
 *
 * A sentinel says "this has been done once and never needs doing again". A
 * condition says "this needs doing right now", which is a different question:
 * `npm ci` is worth re-running whenever `node_modules` has been thrown away,
 * and no sentinel can express that.
 *
 * What the DSL cannot ask is whether some other command succeeded. That would
 * mean running a probe, reading its exit code and branching on it — a
 * programming language, in a config file, with its own error handling to
 * document and test. Recipes that need it get a script of their own and one
 * `host:` step ({@see Pipeline}).
 */
final readonly class Condition
{
    /** True when the worktree does not have this path. */
    public const string Missing = 'missing';

    /** True when it does. */
    public const string Exists = 'exists';

    /** True when the worktree's environment file leaves this variable blank. */
    public const string EnvEmpty = 'env_empty';

    private function __construct(
        private string $kind,
        private string $argument,
    ) {}

    /**
     * @param  string  $where  The config path it came from, for the error message.
     *
     * @throws WorktreeException when $when is not one of the three.
     */
    public static function parse(string $when, string $where): self
    {
        $parts = explode(':', $when, 2);

        if (count($parts) !== 2 || ! in_array($parts[0], Schema::Conditions, true) || $parts[1] === '') {
            throw new WorktreeException(
                Schema::File.": $where.when must be one of "
                .implode(', ', array_map(fn (string $kind): string => $kind.':', Schema::Conditions)).", '$when' given"
            );
        }

        return new self($parts[0], $parts[1]);
    }

    /**
     * Whether the step this guards has something to do.
     *
     * @param  string  $path  The worktree, which relative arguments are relative to.
     * @param  string  $environmentFile  The worktree's `.env`, as generated.
     *
     * @throws WorktreeException when the environment file exists and cannot be read.
     */
    public function holds(string $path, string $environmentFile): bool
    {
        return match ($this->kind) {
            self::Exists => file_exists($this->resolve($path)),
            self::Missing => ! file_exists($this->resolve($path)),
            default => self::blank($environmentFile, $this->argument),
        };
    }

    /**
     * Why the step it guards was skipped, as a person reading the run needs it.
     */
    public function unmet(): string
    {
        return match ($this->kind) {
            self::Exists => "there is no $this->argument here",
            self::Missing => "$this->argument is already there",
            default => "$this->argument is already set",
        };
    }

    private function resolve(string $path): string
    {
        return str_starts_with($this->argument, '/') ? $this->argument : $path.'/'.$this->argument;
    }

    /**
     * Whether $key is a variable the worktree's environment leaves blank.
     *
     * `env_empty`, not `env_missing`, and the distinction is the whole point:
     * the case this exists for is `APP_KEY`, which a freshly copied `.env`
     * carries as `APP_KEY=` — present, and empty. A condition that only fired on
     * an absent key would never fire at all. A key that really is absent counts
     * as empty too, because a worktree with no `APP_KEY` line needs one
     * generated every bit as much as one with a blank.
     */
    private static function blank(string $environmentFile, string $key): bool
    {
        if (! is_file($environmentFile)) {
            return true;
        }

        $value = Assignments::read($environmentFile)->value($key);

        return $value === null || $value === '';
    }
}
