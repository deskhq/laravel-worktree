<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\ExitCode;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Support\ContainerEnvironment;
use DeskHQ\LaravelWorktree\Support\ContainerRefusal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The artisan side of the entry point: a delegator, never an implementation.
 *
 * Under Sail, artisan runs inside the app container, where there is no docker
 * socket, no sibling worktrees directory and no host git — so these commands
 * refuse to run there and say exactly what to type instead. On the host they
 * simply forward to the binary and hand back its exit code, passing its stdout
 * through untouched: the binary has already applied the stream contract, and a
 * facade that reformatted the path would break `cd "$(...)"` just as surely as
 * a chatty bootstrap step would.
 */
abstract class WorktreeCommand extends Command
{
    public function __construct(private readonly ContainerEnvironment $environment)
    {
        parent::__construct();
    }

    /**
     * The subcommand of the host binary this delegates to.
     */
    abstract protected function hostCommand(): string;

    public function handle(): int
    {
        /** @var list<string> $arguments */
        $arguments = array_values(array_map(strval(...), (array) $this->argument('arguments')));

        if ($this->environment->isContainerised()) {
            $this->stderr()->writeln(ContainerRefusal::message($this->hostCommand(), $arguments));

            return ExitCode::Failure;
        }

        $binary = $this->binaryPath();

        if ($binary === null) {
            $this->stderr()->writeln('error: could not find the worktree binary; expected vendor/bin/worktree');

            return ExitCode::Failure;
        }

        return (new ProcessRunner(new Output(STDERR)))->passthrough(
            [PHP_BINARY, $binary, $this->hostCommand(), ...$arguments],
            fn (string $chunk) => $this->output->write($chunk),
            fn (string $chunk) => $this->stderr()->write($chunk),
        );
    }

    private function binaryPath(): ?string
    {
        $candidates = [];

        if (function_exists('base_path')) {
            $candidates[] = base_path('vendor/bin/worktree');
        }

        // The package's own copy, for a repository that is developing it.
        $candidates[] = dirname(__DIR__, 2).'/bin/worktree';

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function stderr(): SymfonyStyle
    {
        return $this->output->getErrorStyle();
    }
}
