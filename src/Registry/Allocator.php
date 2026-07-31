<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * Where a worktree gets its slot — and therefore its ports — from.
 *
 * Two calls, `allocate` and `release`, either of which is the whole of one
 * command's dealings with the registry. Both take the worktree's own lock
 * first, so a second `create 441` waits for the first instead of running git,
 * Composer, Sail and npm alongside it in the same directory, and both do their
 * reading and writing inside the registry lock, so the free-slot search and the
 * claim that follows it are one indivisible step.
 *
 * The registry lock is deliberately let go before this returns: the slow work a
 * command does afterwards must not block every other repository on the machine
 * from allocating.
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
        return new self(
            Registry::fromConfiguration($config),
            new Locks($config->home, $shutdown),
            Ports::fromConfiguration($config),
            new BindProbe,
            $output,
            $config->slots,
        );
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function locks(): Locks
    {
        return $this->locks;
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

        $this->locks->worktree($key)->acquire();

        return $this->locks->registry()->hold(function () use ($key, $repo, $slug, $branch, $path): Entry {
            $entry = $this->registry->entry($key);

            if ($entry !== null) {
                $this->refuseForeignCheckout($entry, $repo);

                return $entry;
            }

            $slot = $this->freeSlot();

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
     * Give a slot back.
     *
     * Under the same worktree lock a create takes, so a `remove` racing a
     * `create` for one worktree waits for it rather than tearing down
     * underneath it. Nothing registered under $key is not an error: `remove`
     * has to work with no registry entry at all (the-desk#1095).
     */
    public function release(string $key): void
    {
        $this->locks->worktree($key)->acquire();

        $this->locks->registry()->hold(function () use ($key): void {
            $this->registry->forget($key);
        });
    }

    /**
     * The lowest slot no entry claims and nothing is already listening on.
     *
     * Called under the registry lock — the search and the claim that follows it
     * are one step, or two runs both see slot 3 free.
     */
    private function freeSlot(): int
    {
        $claimed = $this->registry->claimedSlots();
        $skipped = [];

        for ($slot = 0; $slot < $this->slots; $slot++) {
            if (in_array($slot, $claimed, true)) {
                continue;
            }

            $taken = $this->probe->firstTaken($this->ports->forSlot($slot));

            if ($taken !== null) {
                [$name, $port] = $taken;

                $this->output->line("slot $slot skipped: port $port ($name) is already in use on this machine");
                $skipped[] = $slot;

                continue;
            }

            return $slot;
        }

        throw new WorktreeException($skipped === []
            ? "all $this->slots worktree slots are in use; free one with 'worktree remove <slug>', or raise 'slots'"
            : 'no free slot has a free port block ('.count($skipped).' of '.$this->slots.' slots skipped by the bind probe); '
              .'stop whatever is holding those ports, or move port_base');
    }

    /**
     * A key is a Compose project name, and Compose project names are what
     * containers and volumes are scoped by — so two checkouts registering the
     * same one would not merely share a registry entry, they would share
     * containers. That is what a second clone of a repository looks like when
     * `repo_slug` was left to default to the directory name and both clones
     * have the same one.
     */
    private function refuseForeignCheckout(Entry $entry, string $repo): void
    {
        if ($entry->belongsTo($repo)) {
            return;
        }

        throw new WorktreeException(
            "'$entry->key' is already registered to $entry->repo, not $repo; "
            ."two checkouts cannot share a project name — set 'repo_slug' in config/worktree.php to tell them apart"
        );
    }
}
