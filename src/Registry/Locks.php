<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Console\ShutdownHandler;

/**
 * The two locks, and the promise that they are given back.
 *
 * The scheme is the bash original's, and the split is the point:
 *
 * - the **registry lock** guards the free-slot search plus the mutation that
 *   follows it, so two *different* worktrees never take the same slot. It is
 *   held for the few milliseconds that takes, and released before any of the
 *   slow work — git, Composer, Sail, npm — begins.
 * - a **per-worktree lock** serialises the whole create or remove of *one*
 *   worktree, so a stray second `create 441` (or a `create` racing a `remove`)
 *   cannot run all of that concurrently in the same directory. It is held for
 *   the whole run, and different keys take different locks, so concurrency
 *   between worktrees survives untouched.
 *
 * Both are released together by the single shutdown handler every run installs,
 * so an interrupted bootstrap leaves nothing behind for the next run to trip
 * over — including a `SIGINT` in the middle of the slow work, which is when it
 * actually happens.
 */
final class Locks
{
    /**
     * ~20 seconds. The registry lock is only ever held for a read, a decision
     * and a write, so waiting this long already means something is wrong.
     */
    private const int RegistryAttempts = 200;

    /**
     * ~10 minutes. A first bootstrap legitimately takes that long — image
     * pulls, `composer install`, `npm ci` — and the second `create` should wait
     * for it rather than declare it stale.
     */
    private const int WorktreeAttempts = 6000;

    /** @var array<string, Lock> */
    private array $locks = [];

    public function __construct(
        private readonly string $home,
        private readonly ShutdownHandler $shutdown,
    ) {}

    /**
     * The machine-wide lock over slot allocation.
     */
    public function registry(): Lock
    {
        $path = $this->home.'/registry.lock';

        return $this->remember($path, new Lock(
            $path,
            self::RegistryAttempts,
            "could not acquire the registry lock at $path within 20s; "
            .'if no other worktree command is running, remove it and retry'
        ));
    }

    /**
     * The lock over everything one worktree's run does.
     */
    public function worktree(string $key): Lock
    {
        $path = $this->home.'/locks/'.$this->filename($key).'.lock';

        return $this->remember($path, new Lock(
            $path,
            self::WorktreeAttempts,
            "another worktree command has been working on '$key' for over 10m (lock $path); "
            .'wait for it to finish, or remove the lock if it is stale'
        ));
    }

    /**
     * One {@see Lock} per path, for the lifetime of the run.
     *
     * Two calls asking for the same lock must hand back the same object, or the
     * second would try to `mkdir` a directory the first already owns and wait
     * out its whole timeout against itself.
     */
    private function remember(string $path, Lock $lock): Lock
    {
        if (! isset($this->locks[$path])) {
            $this->locks[$path] = $lock;

            $this->shutdown->onShutdown(fn () => $lock->release());
        }

        return $this->locks[$path];
    }

    /**
     * Registry keys are Compose project names, so they are already safe; this
     * is here so that a key from somewhere else can never name a directory
     * outside the lock directory.
     */
    private function filename(string $key): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $key), '-.');

        return $name === '' ? 'worktree' : $name;
    }
}
