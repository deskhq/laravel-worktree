<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Fleet;
use DeskHQ\LaravelWorktree\Registry\Liveness;
use DeskHQ\LaravelWorktree\Registry\Lock;
use DeskHQ\LaravelWorktree\Registry\Owner;

/**
 * The explicit way out of a lock nobody holds.
 *
 * ```
 * $ worktree unlock 441
 * removed /Users/…/.laravel-worktree/locks/wt-the-desk-441-fix-login.lock,
 *   taken by pid 51234 on studio.local (worktree create 441), holding it since 2026-08-01T09:12:44Z,
 *   which is not running any more
 * ```
 *
 * {@see Lock} already breaks a lock whose holder it can prove is gone, so this
 * is not the ordinary path — it is the one for the cases that proof will not
 * take: a lock written by a version of this package that recorded no holder, a
 * `WORKTREE_HOME` on a network share where the pid belongs to another machine,
 * or a laptop whose `ps` will not answer.
 *
 * Before this existed the remedy was in the timeout message, spelled `rm -rf`
 * into this package's own state directory. This at least names what it is about
 * to remove and who took it, and **refuses a lock whose holder is still
 * running** — the one removal that would put two bootstraps inside the same
 * directory. `--force` overrides that, because a person who has looked at the
 * pid and knows better is the reason the flag exists.
 *
 * ## What it may touch
 *
 * A name, or `--all`. `--all` is machine-wide rather than repository-wide,
 * because the locks are: they hang off `WORKTREE_HOME` rather than off a
 * checkout, and skipping another clone's stale lock would leave behind the thing
 * the command was run to clear. Nothing here reads or writes the registry, and
 * nothing here touches a container, a volume or a worktree — a lock is a
 * directory, and this removes it.
 */
final readonly class UnlockCommand implements Command
{
    /** Every lock on this machine, rather than one worktree's. */
    public const string All = 'all';

    /** Remove it even though its holder is still running, or might be. */
    public const string Force = 'force';

    public function __construct(
        private Output $output,
        private ProcessRunner $runner,
        private ShutdownHandler $shutdown,
    ) {}

    public function name(): string
    {
        return 'unlock';
    }

    public function usage(): array
    {
        return [
            '<slug> [--all] [--force]',
            'Remove a lock a run never gave back, naming who took it.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse($arguments, [self::All, self::Force], takes: Arity::optional($this->name()));
        $name = $invocation->at(0);
        $everything = $invocation->has(self::All);

        if ($everything && $name !== null) {
            throw new UsageException("--all unlocks every lock on this machine, so it takes no name; given '$name'");
        }

        if (! $everything && ($name === null || trim($name) === '')) {
            throw new UsageException(
                'name the worktree whose lock to remove — an issue number, or a branch name — or pass --all '
                .'for every lock on this machine'
            );
        }

        // The fleet, for the two things this command needs from it and no more:
        // the key a name implies, and the locks that are out. It is the one
        // command here that neither reads the registry nor takes a lock — a
        // lock is a directory, and this removes it.
        $fleet = Fleet::fromConfiguration($config, $anchor, $this->runner, $this->shutdown, $this->output);

        $targets = $everything ? $fleet->locksTaken() : [$fleet->lockOn((string) $name)];

        return $this->unlock($targets, $fleet, $invocation->has(self::Force), $everything);
    }

    /**
     * @param  list<Lock>  $targets
     */
    private function unlock(array $targets, Fleet $fleet, bool $forced, bool $everything): int
    {
        $removed = 0;
        $refused = 0;
        $missed = 0;

        foreach ($targets as $lock) {
            if (! $lock->exists()) {
                $this->output->line('nothing holds '.$lock->path());

                continue;
            }

            $owner = $lock->owner();
            $liveness = $owner === null ? null : $owner->liveness($fleet->machine());

            if ($owner !== null && $liveness !== Liveness::Gone && ! $forced) {
                $this->output->error($this->refusal($lock, $owner, $liveness ?? Liveness::Unknown));
                $refused++;

                continue;
            }

            if (! $lock->breakOpen($owner)) {
                // Not a refusal — nobody decided anything — but not a removal
                // either, and the lock the command was asked to clear is still
                // there. It counts against the exit code for that reason alone.
                $this->output->error(
                    $lock->path().' is still there: either something took it while it was being removed, '
                    .'or this run may not write in '.dirname($lock->path())
                );
                $missed++;

                continue;
            }

            $this->removed($lock, $owner, $liveness);
            $removed++;
        }

        return $this->report($removed, $refused, $missed, $targets === [] && $everything);
    }

    /**
     * Why a lock was left alone, and what to type if that is the wrong answer.
     *
     * Who holds it and whether they are still there is {@see Owner::heldBy()}'s
     * sentence rather than this command's: the same lock is described by the run
     * waiting for it and by `worktree doctor`, and the three of them used to
     * word it three ways (#74).
     */
    private function refusal(Lock $lock, Owner $owner, Liveness $liveness): string
    {
        return $lock->path().' is '.$owner->heldBy($liveness)
            .'; wait for it to finish, or pass --force to remove it anyway';
    }

    /**
     * What went, and on whose account — so that a removal is auditable after the
     * fact rather than only counted.
     */
    private function removed(Lock $lock, ?Owner $owner, ?Liveness $liveness): void
    {
        $this->output->line('removed '.$lock->path().', '.($owner === null
            ? 'which recorded no holder — nothing here could say whether one was still running'
            : $owner->heldBy($liveness ?? Liveness::Unknown).($liveness === Liveness::Gone
                ? ''
                : ', and this run was told to remove it regardless')));
    }

    private function report(int $removed, int $refused, int $missed, bool $nothingAtAll): int
    {
        if ($nothingAtAll) {
            $this->output->line('no lock is held on this machine');
        }

        if ($refused > 0) {
            $this->output->line(
                $refused.' of them '.($refused === 1 ? 'was' : 'were').' left alone'
                .($removed === 0 ? '' : ", and $removed removed").'; --force removes '
                .($refused === 1 ? 'it' : 'them').' regardless'
            );
        }

        // Non-zero for either: a lock this was asked to clear is still there.
        // Which of the two it was is already on screen above, in the terms a
        // person acts on — a pid to wait for, or a lock to look at again.
        return $refused === 0 && $missed === 0 ? ExitCode::Success : ExitCode::Failure;
    }
}
