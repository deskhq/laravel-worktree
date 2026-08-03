<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Runtime\Orphans;
use DeskHQ\LaravelWorktree\Support\Machine;

/**
 * The worktrees this machine is holding, and the lifecycle every command shares
 * (#73).
 *
 * A name in, a locked and verified worktree out. Seven commands used to write
 * that themselves: each one resolved the name, refused a key belonging to
 * another checkout, took the per-worktree lock, re-read the entry now that the
 * lock was held, and — for the two that act on many worktrees at once — looped
 * over keys taking a lock per key. Around 220 lines of it, and the cost was not
 * the lines: an eighth command meant restating the rules, and none of them
 * could be exercised without building a git repository and racing real
 * processes against it.
 *
 * So the rules are here, stated once:
 *
 * - **what a name resolves to** — {@see locate()}, {@see require()}, and the
 *   sentence for a name nothing holds;
 * - **the foreign-checkout refusal** — {@see verify()}, in one sentence whose
 *   middle clause each command supplies ({@see ForeignCheckout});
 * - **the lock, and both ways of holding it** — {@see claim()} for the commands
 *   that hold to process exit, {@see sweep()} for the ones that hold a key at a
 *   time and give it back;
 * - **the re-read under the lock** — {@see entry()}, and {@see stillHeld()} for
 *   the caller that will not go on without one;
 * - **the sweep** — lock, act, collect what worked and what survived.
 *
 * ## What is deliberately not here
 *
 * *What to do about a name nothing holds.* Five commands give five answers —
 * `start` and `path` refuse and name `create`, `stop` refuses without it,
 * `remove` proceeds on a derived guess because a worktree whose entry somebody
 * deleted still has containers, and `reap` skips quietly because another
 * process freeing a slot mid-sweep is not a reason to abort the sweep. Every
 * one of those is right for its command, so every one of them stays at its call
 * site. What went away is three commands writing the same *sentence* and five
 * writing the same refusal.
 *
 * ## One registry, one set of locks
 *
 * Everything below reads the same {@see Registry} and takes locks from the same
 * {@see Locks}, and so do the {@see Identities} that resolve names and the
 * {@see Allocator} that hands out slots. A run used to hold two registries —
 * the naming layer quietly built a second one for itself — which is one read of
 * a machine-global file that nothing could account for.
 */
final readonly class Fleet
{
    public function __construct(
        private Anchor $anchor,
        private Identities $identities,
        private Registry $registry,
        private Locks $locks,
        private Allocator $allocator,
        private ProcessRunner $runner,
        private Output $output,
    ) {}

    /**
     * The fleet as a command gets it: one registry, one set of locks, and the
     * naming and allocation layers built over both.
     */
    public static function fromConfiguration(
        Configuration $config,
        Anchor $anchor,
        ProcessRunner $runner,
        ShutdownHandler $shutdown,
        Output $output,
    ): self {
        $registry = Registry::fromConfiguration($config);
        $locks = new Locks($config->home, $shutdown, $output);

        return new self(
            $anchor,
            Identities::fromConfiguration($config, $anchor, $runner, $output, $registry),
            $registry,
            $locks,
            Allocator::over($registry, $locks, $config, $output),
            $runner,
            $output,
        );
    }

    /**
     * Every name $argument implies, whether or not a worktree holds them yet.
     *
     * The write path's way in: `create` needs the names before there is
     * anything registered under them, and `remove` needs them for a worktree
     * whose entry has already gone ({@see Identities::identify()}).
     */
    public function identify(string $argument): Identity
    {
        return $this->identities->identify($argument);
    }

    /**
     * Every name pull request $number implies ({@see Identities::identifyPullRequest()}).
     */
    public function identifyPullRequest(string $number): Identity
    {
        return $this->identities->identifyPullRequest($number);
    }

    /**
     * The worktree $name holds, verified as this checkout's — or null when
     * nothing holds it.
     *
     * The read-only lookup: no `gh`, nothing written, and a number matched
     * against the registry rather than derived, since `441` became
     * `441-fix-login` or `issue-441` depending on what `gh` said the day the
     * worktree was made ({@see Identities::locate()}).
     *
     * @throws WorktreeException when another checkout holds it.
     */
    public function locate(string $name, ForeignCheckout $refusal): ?Entry
    {
        $entry = $this->identities->locate($name);

        return $entry === null ? null : $this->verify($entry, $refusal);
    }

    /**
     * The same, for a command that has nothing to do without one.
     *
     * $hint is the command that would make the worktree — `create`, for the two
     * that are worth suggesting it to. `stop` passes none, because somebody who
     * mistyped a name they meant to stop is not being offered a bootstrap.
     *
     * @param  string|null  $hint  The command that makes one, named in the refusal.
     *
     * @throws WorktreeException when nothing holds $name, or another checkout does.
     */
    public function require(string $name, ForeignCheckout $refusal, ?string $hint = null): Entry
    {
        $entry = $this->locate($name, $refusal);

        if ($entry !== null) {
            return $entry;
        }

        throw new WorktreeException(
            'no worktree of '.$this->repoSlug()." is registered as '$name'; "
            .($hint === null ? '' : "'worktree $hint $name' makes one, and ")
            ."'worktree list' shows the ones there are"
        );
    }

    /**
     * An entry this checkout may act on.
     *
     * Public because two commands reach the entry another way — `create` and
     * `remove` derive the key from the name rather than looking it up — and the
     * refusal is the same one either way ({@see ForeignCheckout}).
     *
     * @throws WorktreeException when $entry belongs to another checkout.
     */
    public function verify(Entry $entry, ForeignCheckout $refusal): Entry
    {
        $refusal->refuse($entry, $this->anchor->mainRoot);

        return $entry;
    }

    /**
     * The entry under $key as the registry has it *now*.
     *
     * The read every command makes once it is holding the lock, and the reason
     * it is a second read rather than a remembered one: the first look happened
     * outside the lock, so a `remove` this run queued behind has freed the slot
     * by the time it gets in, and a `reap` scanned the machine minutes before a
     * `create` claimed one of the projects it is partway through. Acting on the
     * earlier answer would boot containers nothing in the registry names, or
     * destroy a worktree that came back.
     */
    public function entry(string $key): ?Entry
    {
        return $this->registry->entry($key);
    }

    /**
     * The same read, for a caller that will not go on without an entry.
     *
     * @throws WorktreeException when something released the slot while this run was waiting for its lock.
     */
    public function stillHeld(Entry $entry): Entry
    {
        $current = $this->entry($entry->key);

        if ($current !== null) {
            return $current;
        }

        throw new WorktreeException(
            "nothing in the registry holds $entry->key any more — something released it while this run was "
            ."waiting for its lock; 'worktree create $entry->slug' makes it again"
        );
    }

    /**
     * Take $key's own lock, and hold it until this process exits.
     *
     * The discipline `create`, `start` and `remove` share: everything after
     * this is one indivisible run against one worktree, and the lock is given
     * back by the run's shutdown handler however it ends. Whoever wants the
     * other discipline — a key at a time, released between — wants
     * {@see sweep()}.
     *
     * @throws WorktreeException when the wait for it runs out.
     */
    public function claim(string $key): void
    {
        $this->locks->worktree($key)->acquire();
    }

    /**
     * Act on many worktrees, one lock at a time.
     *
     * The loop `stop` and `reap` both run, and the reason it is a loop rather
     * than one lock over everything: a machine-wide sweep holding every key
     * would block every unrelated `create` on the machine for as long as it
     * takes, so each key's lock is taken, used and given back before the next.
     *
     * $work is what to do with one worktree while its lock is held, and its
     * answer decides which list the key lands in — or, when it hands back null,
     * that the key is left alone entirely. Skipping is silent here because a
     * skip is never a surprise: whatever decided to skip has just said why, in
     * the terms of the command that asked ({@see Verdict}).
     *
     * @template TItem
     *
     * @param  array<string, TItem>  $items  What to act on, keyed by worktree key.
     * @param  callable(TItem, string): ?Verdict  $work  Run holding that key's lock; null leaves the key alone.
     */
    public function sweep(array $items, callable $work): Sweep
    {
        $succeeded = [];
        $survived = [];

        foreach ($items as $key => $item) {
            $key = (string) $key;
            $verdict = $this->locks->worktree($key)->hold(fn (): ?Verdict => $work($item, $key));

            if ($verdict === null) {
                continue;
            }

            if ($verdict->succeeded) {
                $succeeded[] = $key;

                continue;
            }

            if ($verdict->diagnostic !== '') {
                $this->output->error($verdict->diagnostic);
            }

            $survived[] = $key;
        }

        return new Sweep($succeeded, $survived);
    }

    /**
     * The entry for $key: the one it already holds, or the lowest free slot
     * ({@see Allocator::allocate()}).
     *
     * The checkout is this fleet's own, which is the whole of what a caller
     * would otherwise have had to remember to pass.
     *
     * @throws WorktreeException when no slot is free, or another checkout holds this key.
     */
    public function allocate(string $key, string $slug, string $branch, string $path): Entry
    {
        return $this->allocator->allocate($key, $this->anchor->mainRoot, $slug, $branch, $path);
    }

    /**
     * Write an entry back as the run that owns it now has it.
     */
    public function record(Entry $entry): void
    {
        $this->allocator->record($entry);
    }

    /**
     * Give a slot back. Nothing registered under $key is not an error —
     * `remove` has to work with no registry entry at all (the-desk#1095).
     */
    public function release(string $key): void
    {
        $this->allocator->release($key);
    }

    /**
     * The worktrees of this checkout, in slot order.
     *
     * @return array<string, Entry>
     */
    public function here(): array
    {
        return $this->registry->forRepo($this->anchor->mainRoot);
    }

    /**
     * Every worktree on this machine, whichever checkout made it — the scope
     * `list --all`, `reap --all` and `stop --all-repos` mean.
     *
     * @return array<string, Entry>
     */
    public function everywhere(): array
    {
        return $this->registry->all();
    }

    /**
     * The entries holding a slot with nothing behind them, for a run from $repo
     * — or every one on the machine when $repo is null ({@see DeadEntries}).
     *
     * @return list<DeadEntry>
     */
    public function dead(?string $repo): array
    {
        return DeadEntries::for($this->registry)->of($repo);
    }

    /**
     * The daemon's `wt-` projects, measured against this registry: what no
     * entry claims is an orphan ({@see Orphans}).
     */
    public function scan(): Orphans
    {
        return Orphans::for($this->registry, $this->runner, $this->output);
    }

    /**
     * What names this repository inside keys, project names and the worktrees
     * directory ({@see Identities::repoSlug()}).
     */
    public function repoSlug(): string
    {
        return $this->identities->repoSlug();
    }

    /**
     * The lock over the worktree $name implies — for `unlock`, which is the one
     * command here that breaks locks rather than taking them.
     *
     * The key is derived exactly as `create` derives it, so that `unlock 441`
     * and `create 441` are unambiguously about the same directory.
     */
    public function lockOn(string $name): Lock
    {
        return $this->locks->worktree($this->identify($name)->key);
    }

    /**
     * Every lock held on this machine right now, whichever checkout took them
     * ({@see Locks::taken()}).
     *
     * @return list<Lock>
     */
    public function locksTaken(): array
    {
        return $this->locks->taken();
    }

    /**
     * The machine a lock's holder is judged against, so a command deciding
     * about one judges it the way {@see Lock} does.
     */
    public function machine(): Machine
    {
        return $this->locks->machine();
    }
}
