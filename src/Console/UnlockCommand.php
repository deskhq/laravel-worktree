<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Liveness;
use DeskHQ\LaravelWorktree\Registry\Lock;
use DeskHQ\LaravelWorktree\Registry\Locks;
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
        $invocation = Arguments::parse($arguments, [self::All, self::Force]);
        $name = $invocation->at(0);
        $everything = $invocation->has(self::All);

        if ($invocation->at(1) !== null) {
            throw new UsageException('unlock takes one name; given '.implode(' ', $invocation->positional));
        }

        if ($everything && $name !== null) {
            throw new UsageException("--all unlocks every lock on this machine, so it takes no name; given '$name'");
        }

        if (! $everything && ($name === null || trim($name) === '')) {
            throw new UsageException(
                'name the worktree whose lock to remove — an issue number, or a branch name — or pass --all '
                .'for every lock on this machine'
            );
        }

        $locks = new Locks($config->home, $this->shutdown, $this->output);

        $targets = $everything
            ? $locks->taken()
            : [$locks->worktree(Identities::fromConfiguration($config, $anchor, $this->runner, $this->output)
                ->identify((string) $name)->key)];

        return $this->unlock($targets, $locks, $invocation->has(self::Force), $everything);
    }

    /**
     * @param  list<Lock>  $targets
     */
    private function unlock(array $targets, Locks $locks, bool $forced, bool $everything): int
    {
        $removed = 0;
        $refused = 0;

        foreach ($targets as $lock) {
            if (! $lock->exists()) {
                $this->output->line('nothing holds '.$lock->path());

                continue;
            }

            $owner = $lock->owner();
            $liveness = $owner === null ? null : $owner->liveness($locks->machine());

            if ($owner !== null && $liveness !== Liveness::Gone && ! $forced) {
                $this->output->error($this->refusal($lock, $owner, $liveness ?? Liveness::Unknown));
                $refused++;

                continue;
            }

            if (! $lock->breakOpen($owner)) {
                $this->output->line(
                    $lock->path().' changed while it was being removed — something has taken it since, '
                    .'so nothing was removed'
                );

                continue;
            }

            $this->removed($lock, $owner, $liveness);
            $removed++;
        }

        return $this->report($removed, $refused, $targets === [] && $everything);
    }

    /**
     * Why a lock was left alone, and what to type if that is the wrong answer.
     */
    private function refusal(Lock $lock, Owner $owner, Liveness $liveness): string
    {
        return $lock->path().' is held by '.$owner->describe().', '
            .($liveness === Liveness::Alive
                ? 'and that process is still running'
                : 'which is another machine — nothing here can tell whether it is still running')
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
            : 'taken by '.$owner->describe().', '.($liveness === Liveness::Gone
                ? 'which is not running any more'
                : 'which this run was told to remove regardless')));
    }

    private function report(int $removed, int $refused, bool $nothingAtAll): int
    {
        if ($nothingAtAll) {
            $this->output->line('no lock is held on this machine');
        }

        if ($refused === 0) {
            return ExitCode::Success;
        }

        // Non-zero, and named rather than counted: the run did not do what it
        // was asked, and what a person does next is look at the pid it printed.
        $this->output->line(
            $refused.' of them '.($refused === 1 ? 'was' : 'were').' left alone'
            .($removed === 0 ? '' : ", and $removed removed").'; --force removes '
            .($refused === 1 ? 'it' : 'them').' regardless'
        );

        return ExitCode::Failure;
    }
}
