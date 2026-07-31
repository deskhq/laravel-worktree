<?php

namespace DeskHQ\LaravelWorktree\Process;

use DeskHQ\LaravelWorktree\Console\Output;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The only place in this package that starts a subprocess.
 *
 * That is the structural half of the stdout contract. The bash original parked
 * the caller's stdout on fd 3 and pointed the script's own at stderr, so no
 * step could reach stdout even when its call site forgot `>&2`; redirecting per
 * call site had already been tried and held only until the next bootstrap step
 * was added (the-desk#1043). PHP has no `exec 3>&1`, so the guarantee is made
 * the same way it was there — by construction rather than by discipline:
 * children are given pipes, never this process's descriptors, and every pipe
 * lands in {@see Output}, which is stderr. A step cannot reach stdout because
 * it is never handed to one.
 *
 * Timeouts are disabled throughout: bootstrap steps legitimately run for
 * minutes (Composer, npm, image pulls) and killing them mid-way is worse than
 * waiting.
 */
final readonly class ProcessRunner
{
    public function __construct(private Output $output) {}

    /**
     * Run a command, streaming everything it writes into the diagnostics.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function run(array $command, ?string $cwd = null, array $env = []): int
    {
        $process = $this->process($command, $cwd, $env);

        return $process->run(function (string $type, string $chunk): void {
            $this->output->write($chunk);
        });
    }

    /**
     * Run a bootstrap step, as the shell that wrote it would have.
     *
     * Steps are strings in a config file — `artisan migrate --force`, `npm ci &&
     * npm run build` — so they get a shell, and the shape is `laravel/sail`'s
     * own `runCommands()` (MIT): a shell command line, no timeout, and a TTY
     * when there is one to be had.
     *
     * The TTY is what makes Composer, npm and artisan print progress rather than
     * a wall of scrollback, and it does not cost the stdout contract: Symfony
     * only takes it when this process's stdout is *already* a terminal, and it
     * opens `/dev/tty` itself rather than handing the child a descriptor of
     * ours. Under `cd "$(worktree create 441)"` stdout is a pipe, so the
     * condition is false, and every byte goes back to falling through the pipes
     * into {@see Output}. Either way a step never reaches the caller's stdout.
     *
     * @param  array<string, string>  $env  Added to the environment the step inherits.
     */
    public function shell(string $command, string $cwd, array $env = []): int
    {
        $process = Process::fromShellCommandline($command, $cwd, $env === [] ? null : $env);
        $process->setTimeout(null);

        if (Process::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (RuntimeException) {
                // Sail warns here; we fall back silently, because the fallback
                // is the pipes below and they lose nothing but the colours.
            }
        }

        return $process->run(function (string $type, string $chunk): void {
            $this->output->write($chunk);
        });
    }

    /**
     * Run a command and keep its stdout for the caller.
     *
     * Only for reading a machine-readable answer back out of a tool — the
     * output does not become this process's stdout, it becomes a value. The
     * command's stderr still goes to the diagnostics as it happens.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function capture(array $command, ?string $cwd = null, array $env = []): ProcessResult
    {
        $process = $this->process($command, $cwd, $env);
        $captured = '';

        $exitCode = $process->run(function (string $type, string $chunk) use (&$captured): void {
            if ($type === Process::OUT) {
                $captured .= $chunk;

                return;
            }

            $this->output->write($chunk);
        });

        return new ProcessResult($exitCode, $captured);
    }

    /**
     * Ask an optional tool a question, keeping its answer and discarding
     * whatever it had to say about itself.
     *
     * One caller: the `gh issue view` title lookup, which is an enrichment
     * rather than a step. `gh` may be absent, logged out or offline, and each of
     * those is an ordinary answer — `gh: command not found` written to the
     * diagnostics would read as a failure of the run rather than as the absence
     * of a tool nothing required, and the worktree is named `issue-<n>` either
     * way.
     *
     * @param  list<string>  $command
     */
    public function consult(array $command, ?string $cwd = null): ProcessResult
    {
        $process = $this->process($command, $cwd);
        $captured = '';

        $exitCode = $process->run(function (string $type, string $chunk) use (&$captured): void {
            if ($type === Process::OUT) {
                $captured .= $chunk;
            }
        });

        return new ProcessResult($exitCode, $captured);
    }

    /**
     * Run a command, keeping everything it wrote on both streams and showing
     * none of it.
     *
     * For a call whose output is worth having only if it failed. Teardown's
     * `docker compose down` is that call: on the ordinary run it says nothing
     * anybody needs, and on the run that mattered it is the only account of what
     * went wrong — so it is held here, and the caller prints it when the exit
     * code says to. Discarding it instead is how "compose teardown reported
     * nothing to remove (already down?)" came to be printed over a genuine
     * failure, for as long as it took the disk to fill (the-desk#1095).
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function attempt(array $command, ?string $cwd = null, array $env = []): ProcessResult
    {
        $process = $this->process($command, $cwd, $env);
        $captured = '';

        $exitCode = $process->run(function (string $type, string $chunk) use (&$captured): void {
            $captured .= $chunk;
        });

        return new ProcessResult($exitCode, $captured);
    }

    /**
     * Run a command whose failure is an answer rather than an event,
     * discarding both of its streams.
     *
     * One caller: the pre-resolution fetch of a base branch, tried against
     * every remote and expected to fail on most of them. `couldn't find remote
     * ref epic` is not a diagnostic when the branch is local-only — it is the
     * question being answered, and printing it would teach people to ignore
     * git's failures in a run where one of them does matter.
     *
     * @param  list<string>  $command
     */
    public function quiet(array $command, ?string $cwd = null): int
    {
        $process = $this->process($command, $cwd);
        $process->disableOutput();

        return $process->run();
    }

    /**
     * Run a command as a transparent pipe, with both streams explicitly sunk by
     * the caller.
     *
     * This exists for one caller: the artisan facade, which is not the
     * implementation but a delegator standing in front of it, and so must pass
     * the host binary's stdout through unaltered — the binary already applied
     * the contract on the way out. Bootstrap steps use {@see run()}.
     *
     * @param  list<string>  $command
     * @param  callable(string): void  $onStdout
     * @param  callable(string): void  $onStderr
     */
    public function passthrough(array $command, callable $onStdout, callable $onStderr, ?string $cwd = null): int
    {
        $process = $this->process($command, $cwd);

        return $process->run(function (string $type, string $chunk) use ($onStdout, $onStderr): void {
            $type === Process::OUT ? $onStdout($chunk) : $onStderr($chunk);
        });
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function process(array $command, ?string $cwd, array $env = []): Process
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env);
        $process->setTimeout(null);

        return $process;
    }
}
