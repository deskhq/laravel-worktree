<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
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
 * exist yet. Every command therefore gets the same four guarantees set up
 * here, in order: we are not inside the container, the run's locks will be
 * released however it ends, the repository has been anchored, and its
 * configuration has been read and understood.
 *
 * The last two are the ones a command may decline, and two of them do. A
 * {@see Diagnostic} runs against a configuration that would not load, because
 * that failure is its subject rather than its end: `doctor` reports on it, and
 * `init` is how you get out of it. A {@see Rootless} command needs no repository
 * under it at all — `shell-init` is evaluated from an rc file, which runs in `~`.
 *
 * The usage text is built from the commands registered below, so a command that
 * exists is a command that is listed — there is no second place to update. The
 * shell completion is written from the same list ({@see specifications()}), so
 * it is a command that completes, too.
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
     * Wire the binary up with the real streams and its commands.
     */
    public static function create(): self
    {
        $output = new Output(STDERR);
        $shutdown = new ShutdownHandler($output);
        $runner = new ProcessRunner($output);

        $application = new self($output, $shutdown, $runner, new ContainerEnvironment);

        return $application
            ->register(new CreateCommand($output, new Emitter, $runner, $shutdown))
            ->register(new PathCommand($output, new Emitter, $runner, $shutdown))
            ->register(new ListCommand($output, new Emitter, $runner))
            ->register(new StopCommand($output, $runner, $shutdown))
            ->register(new StartCommand($output, $runner, $shutdown))
            ->register(new RemoveCommand($output, $runner, $shutdown))
            ->register(new ReapCommand($output, $runner, $shutdown, new Confirmation($output)))
            ->register(new UnlockCommand($output, $runner, $shutdown))
            ->register(new DoctorCommand($output, new Emitter, $runner, $shutdown))
            ->register(new InitCommand($output, new Emitter))
            // Handed the application it is being registered into: the completion
            // it emits is written out of the commands above, so a command added
            // next month completes without anybody maintaining a second list.
            ->register(new ShellInitCommand(new Emitter, $application));
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
            $this->output->error("unknown command: $name");
            $this->printUsage();

            return ExitCode::Usage;
        }

        try {
            // Before the anchor, for the one command that has to work in `~`:
            // an rc file evaluating `shell-init` is in no repository, and a
            // refusal there would be the package failing at the one moment it
            // is supposed to be invisible ({@see Rootless}).
            if ($command instanceof Rootless) {
                return $command->emit($arguments);
            }

            $anchor = Anchor::resolve($this->runner, $this->workingDirectory());

            try {
                $config = Configuration::load($anchor->mainRoot);
            } catch (WorktreeException $unreadable) {
                // The one guarantee a command may decline: `doctor` is the
                // command whose subject is this very failure, and a report that
                // named the offending key and then said nothing about the
                // machine would be the refusal every other command already gives.
                if (! $command instanceof Diagnostic) {
                    throw $unreadable;
                }

                return $command->diagnose($arguments, $anchor, $unreadable);
            }

            return $command->run($arguments, $anchor, $config);
        } catch (UsageException $e) {
            // Called wrong rather than failed, so `EX_USAGE` and the one line
            // of usage that answers it — the whole usage text would bury it.
            $this->output->error($e->getMessage());
            $this->output->line('usage: worktree '.trim($name.' '.$command->usage()[0]));

            return ExitCode::Usage;
        } catch (WorktreeException $e) {
            $this->output->error($e->getMessage());

            return ExitCode::Failure;
        }
    }

    /**
     * What each command is called, and what it takes, as `name => spec`.
     *
     * The same pairs the usage text is built from, read by `shell-init` to write
     * a completion: a command that exists is a command that is listed, and now
     * also a command that completes.
     *
     * @return array<string, string>
     */
    public function specifications(): array
    {
        return array_map(fn (Command $command): string => $command->usage()[0], $this->commands);
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
     * when it was asked for. The only things that ever reach stdout are the
     * answers a script reads: `create`'s path, `path`'s path, `list`'s table,
     * and the object each of those and `doctor` emit under `--json`.
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
