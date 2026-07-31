<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * One worktree's record in the machine-global registry.
 *
 * The key is the Compose project name — `wt-<repo-slug>-<slug>` — because that
 * is the one identifier that reaches every layer this package has to reconcile:
 * the registry, the containers, and the volumes `reap` scopes by
 * `com.docker.compose.project` (D7).
 *
 * `repo` is what makes a machine-global registry usable per repository: `list`
 * scopes to the current one by default, `list --all` shows the machine, and an
 * entry claimed by a different checkout is a collision rather than a resume.
 */
final readonly class Entry
{
    public function __construct(
        /** The registry key, which is also the Compose project name. */
        public string $key,
        /** The slot this worktree holds, and therefore the port block it owns. */
        public int $slot,
        /** The main working tree this worktree hangs off. */
        public string $repo,
        /** The identity the user asked for: `441-fix-login`, `feat/checkout`. */
        public string $slug,
        /** The branch checked out in the worktree. */
        public string $branch,
        /** The worktree's absolute path. */
        public string $path,
        /**
         * The host ports it publishes, complete for the current configuration.
         *
         * @var array<string, int>
         */
        public array $ports,
        /** When the slot was claimed, as an ISO-8601 UTC timestamp. */
        public string $createdAt,
        /**
         * The bootstrap steps that failed and were allowed to, by name.
         *
         * Re-entering a worktree runs these and nothing else. A step degrades
         * because a registry was unreachable or a download timed out far more
         * often than because it is genuinely broken, so the next run is the
         * natural moment to try again — while the steps that succeeded stay
         * skipped, which is what keeps re-entry cheap.
         *
         * @var list<string>
         */
        public array $degraded = [],
    ) {}

    /**
     * Hydrate an entry as it was written, possibly by an earlier version.
     *
     * Ports are repaired rather than demanded ({@see Ports::complete()}); the
     * fields that identify *where* the worktree is cannot be derived from
     * anything, so an entry missing one of those is a corrupt entry and says so
     * with the key in hand.
     *
     * @throws WorktreeException when the entry names no slot, or no path.
     */
    public static function fromArray(string $key, mixed $entry, Ports $ports): self
    {
        if (! is_array($entry)) {
            throw self::corrupt($key, 'it is '.get_debug_type($entry).', not an object');
        }

        $slot = $entry['slot'] ?? null;

        if (! is_int($slot) || $slot < 0) {
            throw self::corrupt($key, "it names no usable slot (got '".get_debug_type($slot)."')");
        }

        $recorded = $entry['ports'] ?? [];

        return new self(
            $key,
            $slot,
            self::text($entry, 'repo', $key),
            self::text($entry, 'slug', $key),
            self::text($entry, 'branch', $key),
            self::text($entry, 'path', $key),
            $ports->complete($slot, is_array($recorded) ? $recorded : []),
            is_string($entry['created_at'] ?? null) ? $entry['created_at'] : '',
            self::degraded($entry['degraded'] ?? []),
        );
    }

    /**
     * @return array{slot: int, repo: string, slug: string, branch: string, path: string, ports: array<string, int>, created_at: string, degraded?: list<string>}
     */
    public function toArray(): array
    {
        $entry = [
            'slot' => $this->slot,
            'repo' => $this->repo,
            'slug' => $this->slug,
            'branch' => $this->branch,
            'path' => $this->path,
            'ports' => $this->ports,
            'created_at' => $this->createdAt,
        ];

        // Written only when there is something to say: a healthy worktree's
        // entry should read as a healthy worktree's entry.
        return $this->degraded === [] ? $entry : $entry + ['degraded' => $this->degraded];
    }

    /**
     * The entry as a standalone object, which is what `--json` publishes.
     *
     * The registry keys entries by project name, so {@see toArray()} leaves it
     * out of the object it writes; a payload that has left the registry has to
     * carry it, or the one name a consumer needs to reach this worktree's
     * containers and volumes is the one name it does not have. `degraded` is
     * always present here for the same reason — a reader should not have to
     * tell an absent key from an empty list.
     *
     * @return array{project: string, slot: int, repo: string, slug: string, branch: string, path: string, ports: array<string, int>, created_at: string, degraded: list<string>}
     */
    public function toPayload(): array
    {
        return ['project' => $this->key] + $this->toArray() + ['degraded' => $this->degraded];
    }

    /**
     * The same entry, carrying what the bootstrap that just ran left behind.
     *
     * @param  list<string>  $degraded
     */
    public function withDegraded(array $degraded): self
    {
        return new self(
            $this->key,
            $this->slot,
            $this->repo,
            $this->slug,
            $this->branch,
            $this->path,
            $this->ports,
            $this->createdAt,
            $degraded,
        );
    }

    /**
     * Whether this entry belongs to the checkout at $repo.
     */
    public function belongsTo(string $repo): bool
    {
        return $this->repo === rtrim($repo, '/');
    }

    /**
     * Names that are no longer strings, or no longer a list, are dropped rather
     * than refused: this field costs a retry, and an entry is not worth
     * rejecting a worktree over.
     *
     * @return list<string>
     */
    private static function degraded(mixed $recorded): array
    {
        if (! is_array($recorded)) {
            return [];
        }

        return array_values(array_filter($recorded, is_string(...)));
    }

    /**
     * @param  array<mixed>  $entry
     */
    private static function text(array $entry, string $field, string $key): string
    {
        $value = $entry[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw self::corrupt($key, "it names no $field");
        }

        return $value;
    }

    private static function corrupt(string $key, string $why): WorktreeException
    {
        return new WorktreeException("the registry entry for '$key' is unusable: $why");
    }
}
