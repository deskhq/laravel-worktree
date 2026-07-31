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
        );
    }

    /**
     * @return array{slot: int, repo: string, slug: string, branch: string, path: string, ports: array<string, int>, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'repo' => $this->repo,
            'slug' => $this->slug,
            'branch' => $this->branch,
            'path' => $this->path,
            'ports' => $this->ports,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Whether this entry belongs to the checkout at $repo.
     */
    public function belongsTo(string $repo): bool
    {
        return $this->repo === rtrim($repo, '/');
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
