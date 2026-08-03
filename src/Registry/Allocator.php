<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * Where a worktree gets its slot — and therefore its ports — from.
 *
 * Everything that writes to the registry does it here: `allocate`, `release`
 * and `record`, and each of them through {@see ordered()} — the worktree's own
 * lock first, so a second `create 441` waits for the first instead of running
 * git, Composer, Sail and npm alongside it in the same directory, then the
 * registry's, so the free-slot search and the claim that follows it are one
 * indivisible step. That order is stated in that one method, and nowhere else
 * in this package.
 *
 * The registry lock is deliberately let go before this returns: the slow work a
 * command does afterwards must not block every other repository on the machine
 * from allocating.
 *
 * A command does not reach for this directly. {@see Fleet} is what a run holds,
 * and it hands this one the registry and the locks the rest of the run is using.
 */
final readonly class Allocator
{
    public function __construct(
        private Registry $registry,
        private Locks $locks,
        private Ports $ports,
        private BindProbe $probe,
        private Output $output,
        /** How many slots this machine allocates. */
        private int $slots,
    ) {}

    public static function fromConfiguration(Configuration $config, ShutdownHandler $shutdown, Output $output): self
    {
        return self::over(
            Registry::fromConfiguration($config),
            new Locks($config->home, $shutdown, $output),
            $config,
            $output,
        );
    }

    /**
     * An allocator over a registry and a set of locks somebody else already
     * has, which is how {@see Fleet} builds one: a run reads one registry and
     * takes locks from one place, or the two halves of it can disagree about
     * what is claimed.
     */
    public static function over(Registry $registry, Locks $locks, Configuration $config, Output $output): self
    {
        return new self($registry, $locks, Ports::fromConfiguration($config), new BindProbe, $output, $config->slots);
    }

    /**
     * The entry for $key: the one it already holds, or the lowest free slot.
     *
     * Resuming hands back what the registry recorded rather than what the
     * current configuration would derive, because those are the ports the
     * worktree's containers were published on — a `port_base` changed since
     * must not re-point a half-bootstrapped worktree at ports nothing is
     * listening on. Only the ports the entry does not name are derived.
     *
     * @throws WorktreeException when no slot is free, or another checkout holds this key.
     */
    public function allocate(string $key, string $repo, string $slug, string $branch, string $path): Entry
    {
        $repo = rtrim($repo, '/');

        return $this->ordered($key, function () use ($key, $repo, $slug, $branch, $path): Entry {
            $entry = $this->registry->entry($key);

            if ($entry !== null) {
                ForeignCheckout::refuseClaim($entry, $repo);

                return $entry;
            }

            $slot = $this->freeSlot($repo);

            $entry = new Entry(
                $key,
                $slot,
                $repo,
                $slug,
                $branch,
                $path,
                $this->ports->forSlot($slot),
                gmdate('Y-m-d\TH:i:s\Z'),
            );

            $this->registry->put($entry);

            return $entry;
        });
    }

    /**
     * Write an entry back as the run that owns it now has it — the degraded
     * steps a bootstrap just left behind.
     *
     * The slot is not in question here, so the registry lock covers nothing but
     * the read-modify-write. The worktree's own lock is taken first all the
     * same ({@see ordered()}) — it is a no-op for every caller there is, each
     * of which has held it since before it read the entry, and stating the
     * order in one place is worth more than saving the call.
     */
    public function record(Entry $entry): void
    {
        $this->ordered($entry->key, function () use ($entry): void {
            $this->registry->put($entry);
        });
    }

    /**
     * Give a slot back.
     *
     * Under the same worktree lock a create takes, so a `remove` racing a
     * `create` for one worktree waits for it rather than tearing down
     * underneath it. Nothing registered under $key is not an error: `remove`
     * has to work with no registry entry at all (the-desk#1095).
     */
    public function release(string $key): void
    {
        $this->ordered($key, function () use ($key): void {
            $this->registry->forget($key);
        });
    }

    /**
     * The slot a create would take next, and the ones it would pass on the way
     * — asked without claiming anything.
     *
     * The allocator's own search, lifted out of the claim so that `worktree
     * doctor` can put exactly the question a create puts ({@see SlotSearch}).
     * It takes no lock and writes nothing, which is what makes it safe to ask
     * outside one; the caller that goes on to *claim* the answer is the one that
     * has to hold the registry lock across both.
     *
     * @throws WorktreeException when the registry cannot be read.
     */
    public function search(): SlotSearch
    {
        $claimed = $this->registry->claimedSlots();
        $skipped = [];

        for ($slot = 0; $slot < $this->slots; $slot++) {
            if (in_array($slot, $claimed, true)) {
                continue;
            }

            $taken = $this->probe->firstTaken($this->ports->forSlot($slot));

            if ($taken === null) {
                return new SlotSearch($slot, $skipped, $this->slots);
            }

            [$name, $port] = $taken;

            $skipped[] = ['slot' => $slot, 'name' => $name, 'port' => $port];
        }

        return new SlotSearch(null, $skipped, $this->slots);
    }

    /**
     * Every slot claimed, and what to do about it.
     *
     * *Free one, or raise `slots`* is unactionable advice when the slots are
     * held by entries whose directories, branches and repositories are all gone:
     * the honest reading of it is "raise `slots`", which grows the leak instead
     * of fixing it (#53). So the entries with nothing behind them are counted
     * here — the registry has just been read for the search above — and the
     * sweep that reclaims them is named.
     *
     * `--all` unless *every* one of them is reclaimable from this checkout: the
     * count is machine-wide, because slots are, and a message that counts three
     * and then names a run that would reclaim two sends somebody back to an
     * exhausted registry having done what it said.
     *
     * Public because `worktree doctor` reports this exhaustion rather than
     * refusing over it, and hardcoded its own `--all` before it could ask here
     * (#74).
     */
    public function exhaustion(string $repo): string
    {
        $dead = DeadEntries::for($this->registry)->of(null);

        if ($dead === []) {
            return "all $this->slots worktree slots are in use; free one with 'worktree remove <slug>', or raise 'slots'";
        }

        $here = array_filter($dead, fn (DeadEntry $entry): bool => $entry->isReclaimableFrom(rtrim($repo, '/')));
        $one = count($dead) === 1;

        return "all $this->slots worktree slots are in use, and ".count($dead).' of them '
            .($one ? 'is held by a registry entry whose' : 'are held by registry entries whose')
            .' worktree directory is gone; '
            ."'worktree reap".(count($here) === count($dead) ? '' : ' --all')."' reclaims ".($one ? 'it' : 'them')
            .", or free one with 'worktree remove <slug>'";
    }

    /**
     * Do $work holding both locks, in the one order this package ever takes
     * them: **the worktree's own first, then the registry's.**
     *
     * Stated here and nowhere else, because a second place stating it is a
     * second place to get it backwards — and two runs taking the same two locks
     * in opposite orders is the textbook deadlock. It is also the cheaper order
     * to hold: the registry lock is machine-wide and every repository on the
     * machine waits behind it, so it is taken last, held for a read, a decision
     * and a write, and given back here rather than by the caller.
     *
     * A worktree lock this run already holds stays held — `create` takes it on
     * the way in and everything below takes it again, and neither should have
     * to know about the other ({@see Lock::acquire()}).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function ordered(string $key, callable $work): mixed
    {
        $this->locks->worktree($key)->acquire();

        return $this->locks->registry()->hold($work);
    }

    /**
     * The lowest slot no entry claims and nothing is already listening on,
     * claimed by the caller.
     *
     * Called under the registry lock — the search and the claim that follows it
     * are one step, or two runs both see slot 3 free. What is said about each
     * skipped block is said here rather than in {@see search()}, because this is
     * the run that is *being* skipped past: `doctor` reports the same slots as
     * ones a create would skip, in the tense that fits a report.
     *
     * @throws WorktreeException when no slot is free.
     */
    private function freeSlot(string $repo): int
    {
        $search = $this->search();

        foreach ($search->skipped as $skip) {
            $this->output->line(
                'slot '.$skip['slot'].' skipped: port '.$skip['port'].' ('.$skip['name'].') is already in use on this machine'
            );
        }

        if ($search->slot !== null) {
            return $search->slot;
        }

        throw new WorktreeException($search->exhausted() ? $this->exhaustion($repo) : $search->refusal());
    }
}
