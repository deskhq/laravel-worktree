<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Support\Machine;

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
 * actually happens. What that cannot cover — `SIGKILL`, the OOM killer, a
 * reboot — is covered instead by the owner record each lock now carries
 * ({@see Lock}), and by `worktree unlock` for the cases the record cannot settle.
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
     *
     * The wait is unchanged, and is still the right answer for a holder that is
     * running. It is no longer paid by a run whose holder has been gone since
     * Tuesday: that one is judged and broken on the first failed attempt.
     */
    private const int WorktreeAttempts = 6000;

    /** The registry lock's directory, relative to the worktree home. */
    private const string RegistryLock = 'registry.lock';

    /** Where the per-worktree locks live, relative to the worktree home. */
    private const string Directory = 'locks';

    /** @var array<string, Lock> */
    private array $locks = [];

    private readonly Machine $machine;

    public function __construct(
        private readonly string $home,
        private readonly ShutdownHandler $shutdown,
        private readonly Output $output,
    ) {
        $this->machine = new Machine(new ProcessRunner($output));
    }

    /**
     * The machine these locks are judged against, so a command that has to
     * decide about one of them judges it the same way {@see Lock} does.
     */
    public function machine(): Machine
    {
        return $this->machine;
    }

    /**
     * The machine-wide lock over slot allocation.
     */
    public function registry(): Lock
    {
        $path = $this->home.'/'.self::RegistryLock;

        return $this->remember($path, fn (): Lock => new Lock(
            $path,
            self::RegistryAttempts,
            "could not acquire the registry lock at $path within 20s; "
            ."if no other worktree command is running, 'worktree unlock --all' removes it",
            $this->output,
            $this->machine,
        ));
    }

    /**
     * The lock over everything one worktree's run does.
     */
    public function worktree(string $key): Lock
    {
        $path = $this->home.'/'.self::Directory.'/'.$this->filename($key).'.lock';

        return $this->remember($path, fn (): Lock => new Lock(
            $path,
            self::WorktreeAttempts,
            "another worktree command has been working on '$key' for over 10m (lock $path); "
            ."wait for it to finish, or 'worktree unlock' it if it is stale",
            $this->output,
            $this->machine,
        ));
    }

    /**
     * Every lock on this machine right now — the registry's, and one per
     * worktree — whichever checkout took them.
     *
     * Machine-wide because the locks are: they hang off `WORKTREE_HOME`, not off
     * a repository, and a `worktree unlock --all` that quietly skipped another
     * clone's stale lock would leave the thing it was run to clear.
     *
     * @return list<Lock>
     */
    public function taken(): array
    {
        $locks = [];

        if (is_dir($this->home.'/'.self::RegistryLock)) {
            $locks[] = $this->registry();
        }

        foreach (glob($this->home.'/'.self::Directory.'/*.lock', GLOB_ONLYDIR) ?: [] as $path) {
            $locks[] = $this->worktree(basename($path, '.lock'));
        }

        return $locks;
    }

    /**
     * One {@see Lock} per path, for the lifetime of the run.
     *
     * Two calls asking for the same lock must hand back the same object, or the
     * second would try to `mkdir` a directory the first already owns and wait
     * out its whole timeout against itself.
     *
     * @param  callable(): Lock  $make  Deferred, so a lock this run already has is not rebuilt to be thrown away.
     */
    private function remember(string $path, callable $make): Lock
    {
        if (! isset($this->locks[$path])) {
            $lock = $make();

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
