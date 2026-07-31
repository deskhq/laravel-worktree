<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * The machine-global store of who holds which slot (D6).
 *
 * `~/.laravel-worktree/registry.json`, one object per worktree, keyed by
 * Compose project name:
 *
 * ```json
 * {
 *   "wt-the-desk-441":       {"slot": 0, "repo": "/Users/…/the-desk", "ports": {…}},
 *   "wt-shop-feat-checkout": {"slot": 1, "repo": "/Users/…/shop",     "ports": {…}}
 * }
 * ```
 *
 * Machine-global rather than per-repository because host ports are: a per-repo
 * registry means two clones of the same repository each allocate slot 0, derive
 * the same port block, and the second one dies on `Bind for :::20000 failed` —
 * and having two clones is exactly what a worktree tool encourages. Every entry
 * records the checkout it belongs to, so `list` can still scope to the current
 * repository ({@see forRepo()}) while allocation sees the whole machine.
 *
 * Reads are safe from anywhere. Mutations are read-modify-write, so they run
 * under the registry lock — {@see Allocator} is where that happens, and is the
 * only thing in this package that mutates the store.
 */
final readonly class Registry
{
    /**
     * The store, relative to the worktree home.
     */
    public const string File = 'registry.json';

    public function __construct(
        /** `WORKTREE_HOME`, or `~/.laravel-worktree`. */
        private string $home,
        private Ports $ports,
    ) {}

    public static function fromConfiguration(Configuration $config): self
    {
        return new self($config->home, Ports::fromConfiguration($config));
    }

    public function path(): string
    {
        return $this->home.'/'.self::File;
    }

    /**
     * The entry registered under $key, or null when nothing holds it.
     */
    public function entry(string $key): ?Entry
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Every worktree on this machine, in slot order.
     *
     * @return array<string, Entry>
     */
    public function all(): array
    {
        $entries = [];

        foreach ($this->read() as $key => $entry) {
            try {
                $entries[$key] = Entry::fromArray($key, $entry, $this->ports);
            } catch (WorktreeException $e) {
                throw new WorktreeException($e->getMessage().' (in '.$this->path().')');
            }
        }

        uasort($entries, fn (Entry $one, Entry $other) => $one->slot <=> $other->slot);

        return $entries;
    }

    /**
     * The worktrees of one checkout — what `list` shows without `--all`.
     *
     * @return array<string, Entry>
     */
    public function forRepo(string $repo): array
    {
        return array_filter($this->all(), fn (Entry $entry) => $entry->belongsTo($repo));
    }

    /**
     * The slots currently claimed, whichever repository claimed them.
     *
     * @return list<int>
     */
    public function claimedSlots(): array
    {
        return array_values(array_map(fn (Entry $entry) => $entry->slot, $this->all()));
    }

    /**
     * Register (or overwrite) an entry.
     *
     * Read-modify-write: call it holding the registry lock.
     */
    public function put(Entry $entry): void
    {
        $entries = $this->read();
        $entries[$entry->key] = $entry->toArray();

        $this->write($entries);
    }

    /**
     * Free a slot. Silent when nothing holds $key — `remove` has to work with
     * no registry entry at all (the-desk#1095).
     *
     * Read-modify-write: call it holding the registry lock.
     */
    public function forget(string $key): void
    {
        $entries = $this->read();

        if (! array_key_exists($key, $entries)) {
            return;
        }

        unset($entries[$key]);

        $this->write($entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new WorktreeException("the registry at $path could not be read");
        }

        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new WorktreeException(
                "the registry at $path is not valid JSON (".json_last_error_msg().'); '
                .'move it aside and rerun, then re-create anything it was holding'
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Replace the store, atomically.
     *
     * The temporary file is created *in the registry directory* and renamed
     * within it, which is the whole point: the bash original wrote it with
     * `mktemp` and `mv`, and `mktemp` lands in `$TMPDIR`, which is not
     * guaranteed to share a filesystem with `$HOME`. A cross-device `mv` is
     * copy-then-unlink, so a reader — another `worktree` process, or a person
     * with `cat` — could see a half-written registry. `rename()` within one
     * filesystem cannot be interrupted that way.
     *
     * @param  array<string, mixed>  $entries
     */
    private function write(array $entries): void
    {
        $this->ensureHome();

        ksort($entries);

        $payload = json_encode(
            $entries === [] ? new \stdClass : $entries,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            throw new WorktreeException('the registry could not be encoded: '.json_last_error_msg());
        }

        $temporary = $this->home.'/.registry-'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $payload."\n") === false) {
            throw new WorktreeException("the registry could not be written to $temporary");
        }

        if (! rename($temporary, $this->path())) {
            @unlink($temporary);

            throw new WorktreeException('the registry at '.$this->path().' could not be replaced');
        }
    }

    private function ensureHome(): void
    {
        if (is_dir($this->home)) {
            return;
        }

        if (! @mkdir($this->home, 0755, true) && ! is_dir($this->home)) {
            throw new WorktreeException("could not create the worktree home at $this->home; set WORKTREE_HOME to somewhere writable");
        }
    }
}
