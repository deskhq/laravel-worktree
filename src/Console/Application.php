<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Support\ContainerEnvironment;
use DeskHQ\LaravelWorktree\Support\ContainerRefusal;

/**
 * The host binary's dispatch.
 *
 * Laravel is never booted: this runs before, and often instead of, an
 * application — from the host, against a repository whose containers may not
 * exist yet. Every command therefore gets the same three guarantees set up
 * here, in order: we are not inside the container, the run's locks will be
 * released however it ends, and the repository has been anchored.
 */
final class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(
        private readonly Output $output,
        private readonly ShutdownHandler $shutdown,
        private readonly ProcessRunner $runner,
        private readonly ContainerEnvironment $environment,
    ) {}

    /**
     * Wire the binary up with the real streams and the roadmap's four commands.
     */
    public static function create(): self
    {
        $output = new Output(STDERR);

        $application = new self(
            $output,
            new ShutdownHandler($output),
            new ProcessRunner($output),
            new ContainerEnvironment,
        );

        return $application
            ->register(new UnimplementedCommand('create', '<slug> [base]', 'Create (or re-enter) an isolated worktree; prints its absolute path.', $output))
            ->register(new UnimplementedCommand('list', '', 'Show this repository\'s worktrees, slots and ports.', $output))
            ->register(new UnimplementedCommand('remove', '<slug>', 'Tear down a worktree\'s containers and volumes, and free its slot.', $output))
            ->register(new UnimplementedCommand('reap', '', 'Remove stray worktree projects left on this machine.', $output));
    }

    public function register(Command $command): self
    {
        $this->commands[$command->name()] = $command;

        return $this;
    }

    /**
     * @param  list<string>  $argv  The raw argv, script name included.
     */
    public function run(array $argv): int
    {
        $this->shutdown->listen();

        $name = $argv[1] ?? null;
        $arguments = array_slice($argv, 2);

        if ($this->environment->isContainerised()) {
            $this->output->line(ContainerRefusal::message($name, $arguments));

            return ExitCode::Failure;
        }

        if ($name === null) {
            $this->printUsage();

            return ExitCode::Usage;
        }

        if (in_array($name, ['help', '-h', '--help'], true)) {
            $this->printUsage();

            return ExitCode::Success;
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $this->output->error("unknown command: {$name}");
            $this->printUsage();

            return ExitCode::Usage;
        }

        try {
            return $command->run($arguments, Anchor::resolve($this->runner, $this->workingDirectory()));
        } catch (WorktreeException $e) {
            $this->output->error($e->getMessage());

            return ExitCode::Failure;
        }
    }

    private function workingDirectory(): string
    {
        $cwd = getcwd();

        if ($cwd === false) {
            throw new WorktreeException('could not determine the current directory');
        }

        return $cwd;
    }

    /**
     * Usage is a diagnostic, so it goes to stderr like every other one — even
     * when it was asked for. Only `create` and `list` ever write to stdout.
     */
    private function printUsage(): void
    {
        $this->output->line('worktree — per-worktree isolation for Laravel projects');
        $this->output->line();
        $this->output->line('Usage:');
        $this->output->line('  worktree <command> [arguments]');
        $this->output->line();
        $this->output->line('Commands:');

        $invocations = [];

        foreach ($this->commands as $command) {
            [$spec, $summary] = $command->usage();
            $invocations[] = [trim($command->name().' '.$spec), $summary];
        }

        $width = max(array_map(fn (array $invocation) => strlen($invocation[0]), $invocations));

        foreach ($invocations as [$invocation, $summary]) {
            $this->output->line('  '.str_pad($invocation, $width + 2).$summary);
        }

        $this->output->line();
        $this->output->line('Runs on the host, never inside the application container.');
    }
}
