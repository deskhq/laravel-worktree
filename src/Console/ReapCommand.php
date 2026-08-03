<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\DeadEntries;
use DeskHQ\LaravelWorktree\Registry\DeadEntry;
use DeskHQ\LaravelWorktree\Registry\Fleet;
use DeskHQ\LaravelWorktree\Registry\Verdict;
use DeskHQ\LaravelWorktree\Runtime\Orphan;
use DeskHQ\LaravelWorktree\Runtime\Orphans;
use DeskHQ\LaravelWorktree\Runtime\Runtime;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;
use DeskHQ\LaravelWorktree\Runtime\TeardownResult;

/**
 * The catch-all `remove` never got to, in both directions (D7).
 *
 * ```
 * $ worktree reap
 * found 2 orphaned projects for the-desk:
 *   wt-the-desk-441-fix-login  4 containers, 3 volumes
 *   wt-the-desk-feat-checkout  0 containers, 3 volumes
 * found 1 registry entry for the-desk whose worktree is gone:
 *   wt-the-desk-feat-search    slot 3, /Users/…/the-desk-worktrees/feat-search
 * destroy these? [y/N]
 * ```
 *
 * A worktree torn down by hand, a teardown interrupted partway, a registry file
 * somebody lost: each leaves the same thing behind, and before this command
 * there was no supported way back to it (the-desk#1095).
 *
 * ## Two classes, one gate
 *
 * The registry is the authority on which slots are taken, so an **orphan** is a
 * project no entry claims, and reap deliberately does not consult the disk about
 * it. The mirror image is a **{@see DeadEntry}**: an entry that claims a
 * worktree the disk has never heard of, which is claimed and therefore not an
 * orphan, and which holds its slot and its port block for as long as the
 * registry survives. Nothing swept those before (#53), and `slots` runs out with
 * a message whose honest reading is *raise `slots`* — which grows the leak.
 *
 * Releasing a slot is far less destructive than force-deleting volumes, and it
 * gets no softer gate for it: one confirmation for one `reap` is easier to
 * reason about than two, and the two classes are reported separately inside the
 * one summary so that what is about to happen to each is legible.
 *
 * A dead entry may still have containers and volumes on the daemon, so
 * reclaiming one tears its project down as well — otherwise the reclaim would
 * turn it into an orphan and want a second `reap` to finish the job. The entry
 * is forgotten only once the teardown proves the project is gone: an entry
 * dropped over surviving volumes is a slot freed and the volumes lost track of,
 * which is the-desk#1095 again from the other end.
 *
 * ## The one whose repository is gone too
 *
 * Entries are scoped to the checkout unless `--all`, and an entry whose whole
 * *repository* has been deleted could never be reclaimed by a scoped run —
 * there is no checkout left to run it from. So those belong to nobody and are
 * reported by every run, under a heading of their own, since it is the case a
 * person has the least context for.
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
 *
 * A dead entry is re-checked the same way and against both facts: the registry
 * is re-read under its lock, and the directory is looked at again — a `create`
 * that put the worktree back between the scan and now is skipped rather than
 * torn down out from under itself.
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
            'Destroy what no worktree claims any more, and reclaim the slots nothing is behind.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse(
            $arguments,
            [self::All, self::DryRun, self::Yes],
            takes: Arity::options($this->name()),
        );

        $everywhere = $invocation->has(self::All);
        $fleet = Fleet::fromConfiguration($config, $anchor, $this->runner, $this->shutdown, $this->output);

        // Scoped exactly as `list`'s warning is, and asked for only when it is
        // needed: deriving the repository's own name can fail on a checkout
        // whose directory name slugifies to nothing, and `--all` has no use for
        // it at all.
        $repoSlug = $everywhere ? null : $fleet->repoSlug();

        $scan = $fleet->scan();
        $orphans = $scan->under(Orphans::prefix($repoSlug));

        // Nothing to do with the daemon: an entry and a directory, which is why
        // this half of the sweep still works with Docker Desktop closed. What
        // needs the daemon is the teardown that reclaiming one ends with.
        $dead = $fleet->dead($everywhere ? null : $anchor->mainRoot);

        if ($orphans === [] && $dead === []) {
            return $this->nothing($scan->reachable(), $repoSlug);
        }

        $this->manifest($orphans, $dead, $repoSlug);

        if ($invocation->has(self::DryRun)) {
            $this->output->line(
                '--dry-run: nothing was removed and no slot was given back; '
                .'the same command without it acts on everything above'
            );

            return ExitCode::Success;
        }

        $permission = $this->permission($invocation->has(self::Yes));

        if ($permission !== null) {
            return $permission;
        }

        return $this->destroy($orphans, $dead, $fleet);
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
     * The orphans in the lines `list` warns with ({@see Orphan::manifest()}), so
     * the manifest a person reads here is the one that sent them here — and the
     * dead entries under a heading of their own, because a slot released and a
     * volume force-deleted are different enough that counting them together
     * would hide one behind the other.
     *
     * @param  list<Orphan>  $orphans
     * @param  list<DeadEntry>  $dead
     */
    private function manifest(array $orphans, array $dead, ?string $repoSlug): void
    {
        $scope = $repoSlug === null ? ' on this machine' : " for $repoSlug";

        if ($orphans !== []) {
            $this->section('found '.count($orphans).' orphaned '.(count($orphans) === 1 ? 'project' : 'projects')
                .$scope.':', Orphan::manifest($orphans));
        }

        $orphaned = array_values(array_filter($dead, fn (DeadEntry $entry): bool => $entry->repoIsGone));
        $claimed = array_values(array_filter($dead, fn (DeadEntry $entry): bool => ! $entry->repoIsGone));

        if ($claimed !== []) {
            $this->section('found '.count($claimed).' registry '.(count($claimed) === 1 ? 'entry' : 'entries')
                .$scope.' whose worktree is gone:', DeadEntry::manifest($claimed));
        }

        if ($orphaned !== []) {
            // Reported by every run, scoped or not, and said in full: nobody
            // asked about this repository, and there is no checkout left that
            // could have.
            $this->section('found '.count($orphaned).' registry '.(count($orphaned) === 1 ? 'entry' : 'entries')
                .' whose repository is gone, so no checkout can claim '.(count($orphaned) === 1 ? 'it' : 'them').':',
                DeadEntry::manifest($orphaned));
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function section(string $heading, array $lines): void
    {
        $this->output->line($heading);

        foreach ($lines as $line) {
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
     * A dead entry goes through the same teardown before its slot is given
     * back, so that reclaiming it cannot leave a fresh orphan behind — the
     * project may well still be on the daemon, which is the reason the
     * directory going is not the end of it.
     *
     * One key's lock at a time and given back before the next is taken, both
     * times, which is the whole of why this is {@see Fleet::sweep()} rather
     * than a loop written here: holding all of them would make a machine-wide
     * reap block every create on the machine for as long as it runs.
     *
     * @param  list<Orphan>  $orphans
     * @param  list<DeadEntry>  $dead
     */
    private function destroy(array $orphans, array $dead, Fleet $fleet): int
    {
        $runtime = SailRuntime::for($this->output, $this->runner);

        $projects = self::byKey($orphans, fn (Orphan $orphan): string => $orphan->project);
        $entries = self::byKey($dead, fn (DeadEntry $entry): string => $entry->key());

        $reaped = $fleet->sweep($projects, fn (Orphan $orphan, string $key): ?Verdict => $this->verdict(
            $this->claimed($fleet, $orphan) ? null : $runtime->teardown($key)
        ));

        $reclaimed = $fleet->sweep($entries, fn (DeadEntry $entry): ?Verdict => $this->verdict(
            $this->reclaim($fleet, $runtime, $entry)
        ));

        return $this->report(
            $reaped->succeeded,
            // The slot beside the key, because giving one back is what the
            // person came here for and the key alone does not say which.
            array_map(
                fn (string $key): string => $key.' (slot '.$entries[$key]->entry->slot.')',
                $reclaimed->succeeded,
            ),
            [...$reaped->survived, ...$reclaimed->survived],
            $reclaimed->survived,
        );
    }

    /**
     * A teardown as a sweep reads it: whether the project went, and the only
     * account there is of what would not — null passes a skip straight through.
     */
    private function verdict(?TeardownResult $result): ?Verdict
    {
        return $result === null ? null : Verdict::of($result->succeeded(), $result->describe());
    }

    /**
     * The two classes as a sweep wants them: keyed by the project name their
     * lock, their containers and their registry row all agree on.
     *
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @param  callable(TItem): string  $key
     * @return array<string, TItem>
     */
    private static function byKey(array $items, callable $key): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $keyed[$key($item)] = $item;
        }

        return $keyed;
    }

    /**
     * Take a dead entry's project down, and give its slot back once that is
     * proved — or hand back null for one that is not dead any more.
     *
     * Both facts are re-read under the entry's own lock, because both can have
     * changed since the scan: a `create` for this key has either finished, in
     * which case the directory is back, or is waiting on this very lock and
     * will find the registry as this run leaves it.
     *
     * The order at the end is deliberate. The entry is forgotten *after* the
     * teardown proves the project is gone, so a reclaim interrupted halfway
     * leaves the entry standing and the next `reap` finds it again — where
     * dropping the row first would leave containers and volumes with nothing
     * naming them, which is the-desk#1095 exactly.
     */
    private function reclaim(Fleet $fleet, Runtime $runtime, DeadEntry $dead): ?TeardownResult
    {
        $entry = $fleet->entry($dead->key());

        if ($entry === null) {
            $this->output->line(
                'skipping '.$dead->key().': nothing in the registry holds it any more — '
                .'something released it after the scan, so its slot is already free'
            );

            return null;
        }

        // Asked a second time in a process that has already been told this path
        // is not there, so the stat cache is dropped for it first: an answer
        // taken from the scan is the scan, and the scan is what this re-check
        // exists not to act on.
        clearstatcache(true, $entry->path);

        if (! DeadEntries::isDead($entry)) {
            $this->output->line(
                'skipping '.$dead->key().": there is a worktree at $entry->path again — it came back after the scan — "
                .'so its slot is not free to reclaim'
            );

            return null;
        }

        $result = $runtime->teardown($entry->key);

        if ($result->succeeded()) {
            $fleet->release($entry->key);
        }

        return $result;
    }

    /**
     * Whether something claimed this project between the scan and now.
     *
     * Read under the project's own lock, which is what makes the answer worth
     * anything: a `create` for this key is either finished — in which case the
     * entry is there and this is no longer an orphan — or waiting on the lock
     * this holds, and will find its containers where it left them.
     */
    private function claimed(Fleet $fleet, Orphan $orphan): bool
    {
        $entry = $fleet->entry($orphan->project);

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
     * @param  list<string>  $reaped  Orphaned projects destroyed.
     * @param  list<string>  $reclaimed  Dead entries whose slot went back.
     * @param  list<string>  $survived  Projects still on the daemon, of either class.
     * @param  list<string>  $held  The dead entries among them, whose slots are therefore still claimed.
     */
    private function report(array $reaped, array $reclaimed, array $survived, array $held): int
    {
        if ($reaped !== []) {
            $this->output->line('reaped '.count($reaped).' '.(count($reaped) === 1 ? 'project' : 'projects')
                .': '.implode(', ', $reaped));
        }

        if ($reclaimed !== []) {
            $this->output->line('reclaimed '.count($reclaimed).' registry '
                .(count($reclaimed) === 1 ? 'entry' : 'entries').': '.implode(', ', $reclaimed));
        }

        if ($survived === []) {
            if ($reaped === [] && $reclaimed === []) {
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

        if ($held !== []) {
            // Said separately, because a slot is the thing the person came here
            // for: an entry left standing over a volume that would not go is
            // still holding its port block, and the next run finds it again.
            $this->output->line(
                (count($held) === 1 ? 'the entry for ' : 'the entries for ').implode(', ', $held)
                .' still '.(count($held) === 1 ? 'holds its slot' : 'hold their slots')
                .', deliberately: a slot given back over volumes that are still there '
                .'would leave nothing naming them'
            );
        }

        return ExitCode::Failure;
    }
}
