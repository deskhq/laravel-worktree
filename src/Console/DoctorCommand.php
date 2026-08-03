<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Doctor\Examination;
use DeskHQ\LaravelWorktree\Doctor\Report;
use DeskHQ\LaravelWorktree\Doctor\Verdict;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * Every pre-flight this package has, without creating anything (#57).
 *
 * ```
 * $ worktree doctor
 * worktree doctor — /Users/agent/the-desk
 *
 * ok         config    config/worktree.php is understood: 50 slots of 10 ports from 20000, …
 * ok         compose   Docker Compose 2.29.1, which is at least 2.24 …
 * fail       ports     config/worktree.php: 1 published host port would be the same in every worktree …
 * warn       gh        gh is not available here, so 'worktree create 441' names the worktree issue-441 …
 * unchecked  orphans   there is no Docker daemon answering on this machine …
 *
 * 13 checks: 10 ok, 1 warn, 1 fail, 1 unchecked
 * a 'worktree create' would stop on the failure above; nothing was created, started or removed by this run
 * ```
 *
 * The package knew all of this already, and every bit of it fired only as a side
 * effect of `create` — so the way to find out whether a repository was
 * configured correctly was to bootstrap a worktree, and the way to find out
 * whether a machine could run one was to try. That is a slow, side-effecting
 * answer to a question that is pure computation almost all the way down, and it
 * is the answer this package offered to somebody evaluating it who had not yet
 * decided to create anything.
 *
 * ## What it is, and what it is not
 *
 * It is a **report**: every check runs, and one that fails does not stop the
 * ones after it, because "it failed" is worth far less than a list somebody can
 * paste. It creates nothing, starts nothing, removes nothing and writes nothing
 * ({@see Examination}) — the registry and the locks are read, the daemon is
 * asked what it has, and the bind probe opens sockets and closes them again.
 *
 * The exit code is the one thing derived from all of it, and only
 * {@see Verdict::Failed} moves it: warnings are true and survivable, and a CI
 * job that went red over a missing `gh` is a CI job somebody turns off.
 *
 * ## `--json`, because the caller is often not a person
 *
 * An agent dropped into an unfamiliar repository is a first-class caller here,
 * and the question it has — *can I create a worktree in this thing?* — is
 * exactly this command's. So the whole report goes out as one object, on stdout,
 * where `list --json` and `path --json` put theirs; the human rendering is a
 * diagnostic like every other one and goes to stderr.
 */
final readonly class DoctorCommand implements Diagnostic
{
    /** Emit the whole report as one object, instead of the lines a person reads. */
    public const string Json = 'json';

    public function __construct(
        private Output $output,
        private Emitter $emitter,
        private ProcessRunner $runner,
        private ShutdownHandler $shutdown,
    ) {}

    public function name(): string
    {
        return 'doctor';
    }

    public function usage(): array
    {
        return [
            '[--json]',
            'Run every pre-flight a create makes, and report; create nothing.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        return $this->examine($arguments, $anchor, $config, null);
    }

    /**
     * The same command against a repository whose configuration would not load,
     * which is a state every other command ends in and this one reports on.
     */
    public function diagnose(array $arguments, Anchor $anchor, WorktreeException $unreadable): int
    {
        return $this->examine($arguments, $anchor, null, $unreadable->getMessage());
    }

    private function examine(array $arguments, Anchor $anchor, ?Configuration $config, ?string $unreadable): int
    {
        $invocation = Arguments::parse($arguments, [self::Json], takes: Arity::options($this->name()));

        $report = (new Examination($anchor, $config, $unreadable, $this->runner, $this->output, $this->shutdown))->report();

        $invocation->has(self::Json) ? $this->emitter->emit($report->toJson()) : $this->print($report);

        return $report->failed() ? ExitCode::Failure : ExitCode::Success;
    }

    private function print(Report $report): void
    {
        foreach ($report->lines() as $line) {
            $this->output->line($line);
        }
    }
}
