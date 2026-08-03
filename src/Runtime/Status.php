<?php

namespace DeskHQ\LaravelWorktree\Runtime;

use DeskHQ\LaravelWorktree\Registry\DeadEntries;
use DeskHQ\LaravelWorktree\Registry\Entry;

/**
 * What a row of `list` is, in one word (#54).
 *
 * Every column the table printed before this came out of the registry — the key,
 * the slot, the ports, the path — which is a faithful report of what has been
 * *claimed* and silent on the question people actually have in front of the
 * table: which of these five is up, which is idle eating nothing, and did that
 * one ever finish bootstrapping at all. The answer used to be `docker ps` and
 * reading project names by eye, which is the manual step this package exists to
 * remove.
 *
 * ## One column, two authorities
 *
 * Some of these are registry facts and some are daemon facts, and the
 * interesting rows are the ones where the two disagree: an entry the registry
 * calls healthy with nothing on the daemon behind it is a real state, and it
 * used to render identically to a running one.
 *
 * So they share a column and are ordered by what a person has to do about them
 * — the most durable answer wins, because it is the one that is still true
 * tomorrow:
 *
 * 1. **{@see Gone}** — a slot claimed with no worktree behind it. #53's word for
 *    it, from #53's test ({@see DeadEntries::isDead()}), because a column and a
 *    sweep that disagree about the same fact is a column nobody trusts twice.
 * 2. **{@see Degraded}** — the entry records bootstrap steps that failed and
 *    were allowed to. A registry fact, so it survives a closed daemon, and a
 *    defect that outlives any number of restarts: re-entering the worktree is
 *    what retries those steps.
 * 3. **{@see Unknown}** — there was no daemon to ask. Not a failure and not an
 *    inferred clean bill of health, on exactly the rule the orphan warning is
 *    already under: silence is not proof.
 * 4. **the daemon's own answer** — {@see Running}, {@see Partial},
 *    {@see Stopped}, {@see Unbooted}.
 *
 * A degraded worktree that is also running says `degraded`, deliberately: what
 * is worth acting on there is the step that never finished, and `--json` carries
 * both facts for a reader that wants them apart.
 *
 * ## The tokens are the contract
 *
 * Lowercase, single words, a small closed set — this goes into a tab-separated
 * column a script compares against, so it is not prose and does not get one. The
 * set is meant to grow, which is why the README says to compare against a token
 * rather than against "not `ok`" — and why `ok` itself is gone: with the daemon
 * answering there is no state left that means only *nothing to report*.
 */
enum Status: string
{
    /** The registry claims a worktree the disk has never heard of (#53). */
    case Gone = 'gone';

    /** Bootstrap steps failed and were allowed to; re-entering retries them. */
    case Degraded = 'degraded';

    /** Every container this project has is up. */
    case Running = 'running';

    /** Some of them are, some are not — a boot that stopped partway. */
    case Partial = 'partial';

    /** Containers exist and none is up. */
    case Stopped = 'stopped';

    /** The daemon has nothing for this project: never booted, or torn down. */
    case Unbooted = 'unbooted';

    /** There was no daemon to ask, so nothing here is claimed either way. */
    case Unknown = 'unknown';

    /**
     * What $entry is, given what the daemon said about every project it has.
     *
     * $daemon is the whole census rather than this entry's share of it, so that
     * the two ways a project can be absent are told apart in one place: a key
     * missing from a census is a project the daemon has never booted, and a
     * census that is null is a daemon nobody could ask.
     *
     * @param  array<string, Presence>|null  $daemon  As {@see Orphans::containers()} answers.
     */
    public static function of(Entry $entry, ?array $daemon): self
    {
        if (DeadEntries::isDead($entry)) {
            return self::Gone;
        }

        if ($entry->degraded !== []) {
            return self::Degraded;
        }

        if ($daemon === null) {
            return self::Unknown;
        }

        $presence = $daemon[$entry->key] ?? Presence::none();

        return match (true) {
            $presence->containers === 0 => self::Unbooted,
            $presence->running === 0 => self::Stopped,
            $presence->running === $presence->containers => self::Running,
            default => self::Partial,
        };
    }
}
