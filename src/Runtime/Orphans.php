<?php

namespace DeskHQ\LaravelWorktree\Runtime;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Registry;

/**
 * What this package left on the machine and no longer accounts for (D7).
 *
 * One scan, two callers, deliberately: `list` warns about what it finds and
 * `reap` destroys it, so the two must agree to the resource on what counts as
 * an orphan — a warning about something `reap` would not touch teaches people
 * to ignore the warning, and the reverse is worse.
 *
 * A project is one when all three hold:
 *
 * 1. its name carries the `wt-` marker, narrowed to this repository's own
 *    `wt-<repo-slug>-` unless the caller asked for the whole machine;
 * 2. it has containers or volumes on this machine, which is what having been
 *    found by a label query means;
 * 3. no registry entry claims it — from *any* repository, because a project
 *    another checkout is holding is somebody else's worktree, not an orphan.
 *
 * The marker is what makes this safe to hand to a destructive command: nothing
 * configurable can remove it ({@see Identities::Marker}), so a Compose project
 * that has nothing to do with this package is only ever in scope if somebody
 * deliberately named theirs `wt-`. {@see under()} refuses a prefix that does not
 * carry it rather than trusting its caller to have added it.
 *
 * Silence is not proof: with no daemon answering, the scan reports nothing
 * found, and its callers say only what they can stand behind — `list` omits the
 * warning entirely rather than announcing a clean machine it never asked about.
 *
 * ## The census is the scan
 *
 * The query this is built on — every container on the machine, by project —
 * answers a second question the table needs: which of the rows it is printing
 * has anything running behind it (#54). So it is asked once and kept
 * ({@see containers()}), and the two callers inside one `list` share it. Ten
 * worktrees must not mean ten `docker` invocations, and the data was already in
 * hand and being thrown away.
 */
final class Orphans
{
    /**
     * The census, once asked: the projects on this daemon, or null for a daemon
     * that could not be asked at all.
     *
     * @var array<string, Presence>|null
     */
    private ?array $containers = null;

    /** Whether the question above has been put yet, which null cannot say. */
    private bool $asked = false;

    public function __construct(
        private readonly Docker $docker,
        private readonly Registry $registry,
    ) {}

    public static function for(Registry $registry, ProcessRunner $runner, Output $output): self
    {
        return new self(Docker::for($runner, $output), $registry);
    }

    /**
     * Whether there was a daemon to scan at all.
     *
     * An empty scan means one of two things and they are not the same: nothing
     * is left, or nothing could be asked. `list` treats both as "say nothing";
     * `reap` is the command somebody ran *because* they expect something to be
     * there, so it says which of the two it got. Asked after {@see under()}, it
     * costs nothing — the answer is memoised on the Docker it already used.
     */
    public function reachable(): bool
    {
        return $this->docker->isRunning();
    }

    /**
     * Every `wt-` project this daemon has, and how much of each is up — or null
     * when there was no daemon to ask.
     *
     * Null rather than an empty census, because those are different answers and
     * collapsing them is the shape of the-desk#1095: nothing here may report a
     * machine as clean, or a worktree as never booted, on the strength of a
     * question nobody could put. The table renders that as `unknown`
     * ({@see Status}), and the warning below renders it as silence.
     *
     * Asked once and kept, so that the row a person is reading and the warning
     * under it are answered from the same snapshot as well as from the same
     * query.
     *
     * @return array<string, Presence>|null
     */
    public function containers(): ?array
    {
        if ($this->asked) {
            return $this->containers;
        }

        $this->asked = true;

        // Asked rather than inferred from an empty answer: every Docker query
        // here reports "nothing" when it could not be run, and reporting a
        // machine as clean because nothing could be asked about it is the shape
        // of the-desk#1095.
        return $this->containers = $this->docker->isRunning()
            ? $this->docker->containersByProject()
            : null;
    }

    /**
     * The project-name prefix a scan is scoped to: one repository's worktrees,
     * or every worktree on the machine when $repoSlug is null.
     */
    public static function prefix(?string $repoSlug): string
    {
        return Identities::Marker.($repoSlug === null ? '' : $repoSlug.'-');
    }

    /**
     * Every unclaimed project under $prefix, in name order.
     *
     * @return list<Orphan>
     *
     * @throws WorktreeException when $prefix would widen the scan past the `wt-` marker.
     */
    public function under(string $prefix): array
    {
        if (! str_starts_with($prefix, Identities::Marker)) {
            throw new WorktreeException(
                "'$prefix' does not carry the '".Identities::Marker."' marker, so scanning for it would take in projects "
                .'this package never created; that marker is the whole of what scopes a destructive operation here'
            );
        }

        $containers = $this->containers();

        if ($containers === null) {
            return [];
        }

        $volumes = $this->docker->volumesByProject();
        $claimed = $this->registry->all();

        $projects = array_keys($containers + $volumes);
        sort($projects);

        $orphans = [];

        foreach ($projects as $project) {
            if (! str_starts_with($project, $prefix) || array_key_exists($project, $claimed)) {
                continue;
            }

            $orphans[] = new Orphan(
                $project,
                ($containers[$project] ?? Presence::none())->containers,
                $volumes[$project] ?? 0,
            );
        }

        return $orphans;
    }
}
