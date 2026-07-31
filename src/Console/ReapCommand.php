<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Locks;
use DeskHQ\LaravelWorktree\Registry\Registry;
use DeskHQ\LaravelWorktree\Runtime\Orphan;
use DeskHQ\LaravelWorktree\Runtime\Orphans;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;
use DeskHQ\LaravelWorktree\Runtime\TeardownResult;

/**
 * The catch-all `remove` never got to: containers and volumes this package
 * created and no longer accounts for (D7).
 *
 * ```
 * $ worktree reap
 * found 2 orphaned projects for the-desk:
 *   wt-the-desk-441-fix-login  4 containers, 3 volumes
 *   wt-the-desk-feat-checkout  0 containers, 3 volumes
 * destroy these? [y/N]
 * ```
 *
 * A worktree torn down by hand, a teardown interrupted partway, a registry file
 * somebody lost: each leaves the same thing behind, and before this command
 * there was no supported way back to it (the-desk#1095).
 *
 * **This force-deletes Docker volumes and has no undo**, so its scoping deserves
 * more scrutiny than anything else here.
 *
 * ## What it may touch, and why that is provable
 *
 * The scan is {@see Orphans} — literally the one `list` warns by, because a
 * warning about something `reap` would not touch teaches people to ignore the
 * warning, and the reverse is worse. A project is eligible only when its name
 * carries the `wt-` marker for this repository (`--all` widens that to the
 * machine), it has containers or volumes on this daemon, and no registry entry
 * from any checkout claims it.
 *
 * The marker is the whole of the safety, and it holds because it is a literal
 * prefix no configuration can change or remove ({@see Identities::Marker}).
 * A custom label would have been the obvious design and does not work: service
 * labels land on containers rather than volumes, labelling volumes would mean
 * enumerating everything `compose.yaml` declares, and the anonymous volumes an
 * image's `VOLUME` directive produces cannot carry a custom label at all. The
 * one label that covers every volume a project owns is
 * `com.docker.compose.project`, whose value is ours because we write
 * `COMPOSE_PROJECT_NAME`. So overlapping with somebody's unrelated Compose
 * project requires them to have deliberately named it `wt-`.
 *
 * ## The gate, and the re-check under the lock
 *
 * Provable scoping still gets a manifest and a confirmation: `--dry-run`
 * reports and touches nothing, `--yes` agrees in advance for CI, and a run with
 * no terminal to ask on refuses rather than assuming ({@see Confirmation}).
 *
 * A scan is a snapshot, so it is not what anything is destroyed on. Each
 * project is torn down holding **the same per-key lock `create` takes**, with
 * the registry re-read inside it: a bootstrap that started between the scan and
 * the deletion has claimed the key by then, and is skipped with a diagnostic
 * rather than reaped out from under itself. One key at a time, released
 * between, so reaping a machineful of projects does not block every unrelated
 * command for the duration.
 */
final readonly class ReapCommand implements Command
{
    /** Widen the scan from this repository's projects to every `wt-` project on the machine. */
    public const string All = 'all';

    /** Report what would go, and touch nothing. */
    public const string DryRun = 'dry-run';

    /** Agree in advance, for a run with nobody watching it. */
    public const string Yes = 'yes';

    public function __construct(
        private Output $output,
        private ProcessRunner $runner,
        private ShutdownHandler $shutdown,
        private Confirmation $confirmation,
    ) {}

    public function name(): string
    {
        return 'reap';
    }

    public function usage(): array
    {
        return [
            '[--all] [--dry-run] [--yes]',
            'Destroy the containers and volumes no worktree claims any more.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse($arguments, [self::All, self::DryRun, self::Yes]);

        if ($invocation->at(0) !== null) {
            throw new UsageException(
                'reap takes no arguments, only options; given '.implode(' ', $invocation->positional)
            );
        }

        $everywhere = $invocation->has(self::All);
        $registry = Registry::fromConfiguration($config);

        // Scoped exactly as `list`'s warning is, and asked for only when it is
        // needed: deriving the repository's own name can fail on a checkout
        // whose directory name slugifies to nothing, and `--all` has no use for
        // it at all.
        $repoSlug = $everywhere
            ? null
            : Identities::fromConfiguration($config, $anchor, $this->runner, $this->output)->repoSlug();

        $scan = Orphans::for($registry, $this->runner, $this->output);
        $orphans = $scan->under(Orphans::prefix($repoSlug));

        if ($orphans === []) {
            return $this->nothing($scan->reachable(), $repoSlug);
        }

        $this->manifest($orphans, $repoSlug);

        if ($invocation->has(self::DryRun)) {
            $this->output->line('--dry-run: nothing was removed; the same command without it destroys the above');

            return ExitCode::Success;
        }

        $permission = $this->permission($invocation->has(self::Yes));

        if ($permission !== null) {
            return $permission;
        }

        return $this->destroy($orphans, $registry, new Locks($config->home, $this->shutdown));
    }

    /**
     * An empty scan, told apart from a scan that never happened.
     *
     * Nothing to reap is the ordinary state of a tidy machine and exits clean.
     * A daemon nobody could reach is *not* that — reporting it as a machine
     * with nothing on it is the shape of the-desk#1095 — but it is not a failed
     * reap either: nothing was claimed, and nothing was destroyed.
     */
    private function nothing(bool $reachable, ?string $repoSlug): int
    {
        $this->output->line($reachable
            ? 'nothing to reap: no project'.($repoSlug === null ? '' : " of $repoSlug")
              .' is on this daemon that no worktree claims'
            : 'there is no Docker daemon answering on this machine, so nothing could be scanned for; '
              .'nothing was removed, and nothing here says the disk is clean');

        return ExitCode::Success;
    }

    /**
     * What is about to be destroyed, named in full before anything is asked.
     *
     * The same lines `list` warns with ({@see Orphan::manifest()}), so the
     * manifest a person reads here is the one that sent them here.
     *
     * @param  list<Orphan>  $orphans
     */
    private function manifest(array $orphans, ?string $repoSlug): void
    {
        $this->output->line('found '.count($orphans).' orphaned '.(count($orphans) === 1 ? 'project' : 'projects')
            .($repoSlug === null ? ' on this machine' : " for $repoSlug").':');

        foreach (Orphan::manifest($orphans) as $line) {
            $this->output->line($line);
        }
    }

    /**
     * Whether the run may proceed, as an exit code when it may not.
     *
     * Three answers, and the third is the one worth being strict about: `--yes`
     * proceeds, a person at a terminal decides, and a run with no terminal
     * refuses. Non-interactive with no `--yes` is an error rather than an
     * implied yes — a cron job that force-deletes volumes because nobody was
     * there to object is the failure mode this whole command is built around.
     */
    private function permission(bool $agreed): ?int
    {
        if ($agreed) {
            return null;
        }

        if (! $this->confirmation->isInteractive()) {
            $this->output->error(
                'reap force-deletes containers and volumes, and there is no terminal here to confirm on; '
                .'pass --yes to agree in advance, or --dry-run to see what it would take'
            );

            return ExitCode::Failure;
        }

        if ($this->confirmation->confirm('destroy these? [y/N]')) {
            return null;
        }

        // Declining is a decision, not a failure: the person asked, was told,
        // and said no. Nothing was touched, and the exit code says so.
        $this->output->line('nothing was removed');

        return ExitCode::Success;
    }

    /**
     * Take each project down under its own lock, and report what survived.
     *
     * The teardown is the one `remove` uses, so a reaped project goes the same
     * way a removed one does — Compose first, then the label sweep, then the
     * re-query that turns "already down?" into evidence. What `reap` cannot
     * supply is a directory: an orphan's worktree is usually the thing that
     * went missing, and the label queries are what never needed it.
     *
     * @param  list<Orphan>  $orphans
     */
    private function destroy(array $orphans, Registry $registry, Locks $locks): int
    {
        $runtime = SailRuntime::for($this->output, $this->runner);
        $reaped = [];
        $survived = [];

        foreach ($orphans as $orphan) {
            // One key's lock at a time, given back before the next is taken:
            // holding all of them would make a machine-wide reap block every
            // create on the machine for as long as it runs.
            $result = $locks->worktree($orphan->project)->hold(
                fn (): ?TeardownResult => $this->claimed($registry, $orphan) ? null : $runtime->teardown($orphan->project)
            );

            if ($result === null) {
                continue;
            }

            if ($result->succeeded()) {
                $reaped[] = $orphan->project;

                continue;
            }

            $this->output->error($result->describe());
            $survived[] = $orphan->project;
        }

        return $this->report($reaped, $survived);
    }

    /**
     * Whether something claimed this project between the scan and now.
     *
     * Read under the project's own lock, which is what makes the answer worth
     * anything: a `create` for this key is either finished — in which case the
     * entry is there and this is no longer an orphan — or waiting on the lock
     * this holds, and will find its containers where it left them.
     */
    private function claimed(Registry $registry, Orphan $orphan): bool
    {
        $entry = $registry->entry($orphan->project);

        if ($entry === null) {
            return false;
        }

        $this->output->line(
            "skipping $orphan->project: a worktree claimed it after the scan — slot $entry->slot, at $entry->path — "
            .'so it is not an orphan any more'
        );

        return true;
    }

    /**
     * @param  list<string>  $reaped
     * @param  list<string>  $survived
     */
    private function report(array $reaped, array $survived): int
    {
        if ($reaped !== []) {
            $this->output->line('reaped '.count($reaped).' '.(count($reaped) === 1 ? 'project' : 'projects')
                .': '.implode(', ', $reaped));
        }

        if ($survived === []) {
            if ($reaped === []) {
                $this->output->line('nothing was removed');
            }

            return ExitCode::Success;
        }

        // Named rather than counted, and non-zero: what a person does about a
        // volume that would not go is find what is still mounting it, and they
        // need its name to do that.
        $this->output->line(
            count($survived).' of them are still on this daemon: '.implode(', ', $survived)
            .'; whatever is still using their volumes has to stop before they can go'
        );

        return ExitCode::Failure;
    }
}
