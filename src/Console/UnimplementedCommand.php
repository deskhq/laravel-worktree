<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Git\Anchor;

/**
 * A command the roadmap has named but not yet built.
 *
 * It exists so the usage text, the dispatch table and the artisan facade are
 * all honest today: `worktree create` says the command is not implemented yet
 * rather than that it does not exist, and each of the four is replaced in place
 * by its own issue.
 */
final readonly class UnimplementedCommand implements Command
{
    public function __construct(
        private string $name,
        private string $spec,
        private string $summary,
        private Output $output,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function usage(): array
    {
        return [$this->spec, $this->summary];
    }

    public function run(array $arguments, Anchor $anchor): int
    {
        $this->output->error("{$this->name} is not implemented yet");

        return ExitCode::Failure;
    }
}
