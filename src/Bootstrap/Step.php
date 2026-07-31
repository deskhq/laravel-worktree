<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

use DeskHQ\LaravelWorktree\Config\Placeholders;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * One line of the recipe, with every placeholder in it already resolved.
 *
 * Steps arrive from `config/worktree.php` as arrays that {@see Schema} has
 * checked the shape of; this is what they become once a worktree exists to
 * resolve them against, which is the earliest moment `{{port.app}}` means a
 * number.
 */
final readonly class Step
{
    private function __construct(
        public Action $action,
        /** What runs, placeholders resolved. */
        public string $command,
        /** The progress line, or null to fall back to the command. */
        public ?string $label,
        /** A file in the worktree: skip if it is there, touch it on success. */
        public ?string $sentinel,
        public ?Condition $when,
        public bool $allowFailure,
        /** Re-printed at the very end when this step failed. */
        public ?string $degrade,
    ) {}

    /**
     * One configured step, resolved against the worktree it is about to run in.
     *
     * Every string the step carries is interpolated, not just the command: a
     * sentinel under `{{path}}` and a degrade notice naming the port it could
     * not reach are both worth having, and a rule with exceptions in it is a
     * rule people have to look up.
     *
     * @param  array<string, string|bool>  $step  One step, as Schema normalised it.
     * @param  string  $where  Its config path — `steps.3` — for any error.
     *
     * @throws WorktreeException when the step names a placeholder this worktree does not have.
     */
    public static function resolve(array $step, string $where, Placeholders $placeholders): self
    {
        $values = [];

        foreach ($step as $key => $value) {
            $values[$key] = is_string($value) ? $placeholders->interpolate($value, "$where.$key") : $value;
        }

        [$action, $command] = self::action($values, $where);
        $when = $values['when'] ?? null;

        return new self(
            $action,
            $command,
            self::text($values, 'label'),
            self::text($values, 'sentinel'),
            is_string($when) ? Condition::parse($when, $where) : null,
            ($values['allow_failure'] ?? false) === true,
            self::text($values, 'degrade'),
        );
    }

    /**
     * The command line this step becomes on the host.
     */
    public function commandLine(): string
    {
        return $this->action->commandLine($this->command);
    }

    /**
     * What this step is called, in the run and in the registry.
     *
     * The label when there is one, and otherwise the step as it was written —
     * `sail artisan migrate --force`. This is the string a degraded step is
     * recorded under and matched by on re-entry, so an unlabelled step whose
     * command is edited is a different step, and gets run rather than retried.
     */
    public function name(): string
    {
        return $this->label ?? $this->action->value.' '.$this->command;
    }

    /**
     * Where this step's sentinel lives, or null when it has none.
     */
    public function sentinelPath(string $path): ?string
    {
        if ($this->sentinel === null) {
            return null;
        }

        return str_starts_with($this->sentinel, '/') ? $this->sentinel : $path.'/'.$this->sentinel;
    }

    /**
     * @param  array<string, string|bool>  $values
     * @return array{0: Action, 1: string}
     */
    private static function action(array $values, string $where): array
    {
        foreach (Schema::StepActions as $name) {
            $command = $values[$name] ?? null;

            if (is_string($command)) {
                return [Action::from($name), $command];
            }
        }

        throw new WorktreeException(
            Schema::File.": $where runs nothing; give it one of ".implode(', ', Schema::StepActions)
        );
    }

    /**
     * @param  array<string, string|bool>  $values
     */
    private static function text(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
