<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Bootstrap\Readiness;
use DeskHQ\LaravelWorktree\Compose\ComposeVersion;
use DeskHQ\LaravelWorktree\Compose\Overlay;
use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\EnvFile;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Git\Excludes;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Locks;
use DeskHQ\LaravelWorktree\Registry\Registry;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;

/**
 * The way back from `stop` (#56).
 *
 * ```bash
 * worktree start 441
 * ```
 *
 * One Compose `up` of the app service, with the overlay's trimmed `depends_on`
 * deciding what comes with it — which is what `create` does on a first
 * bootstrap, and the whole of what this does. No steps run: a stopped worktree
 * has already been through the recipe, its `vendor/` and its `node_modules` are
 * where it left them, and its database is in a volume nothing removed. Nothing
 * reaches stdout, as with `stop` and `remove`: the exit code is the answer.
 *
 * ## It is not a second, weaker `create`
 *
 * A worktree with no `.worktree-ready` has never finished a bootstrap, and
 * starting its containers would leave somebody looking at an application with
 * no dependencies installed and no key generated. That is refused, by name:
 * `create` is what resumes an interrupted bootstrap, and it resumes on the same
 * slot at the same path with every finished step still skipped.
 *
 * The same goes for an entry whose directory is gone. There is nothing to start
 * there, and the branch is still in the repository, so `create` is the answer
 * to that too.
 *
 * ## The overlay is written again first
 *
 * `compose.worktree.yaml` carries nothing but this configuration — it says so in
 * its own first line — and it can be out of date by the time a stopped worktree
 * comes back: a `create --refresh` elsewhere, an edited `config/worktree.php`,
 * a `keep_services` somebody added. Regenerating it is cheap, is what `create`
 * does on every run, and is what makes `start` bring the services this
 * repository currently asks for rather than the ones it asked for in March.
 *
 * The `.env` is not rewritten, for the same reason `create` does not rewrite
 * it: it carries somebody's debugging edits, and a start that reverted them
 * would be worse than no start at all. It is only *generated* here, for the
 * worktree that somehow has none, because it is the file the overlay writes
 * `SAIL_FILES` into.
 *
 * ## The lock
 *
 * The per-worktree lock is taken before the entry is re-read and held until the
 * process exits — `create`'s discipline, for `create`'s reason: bringing
 * containers up under a bootstrap that is still building them is precisely the
 * race it exists to prevent.
 */
final readonly class StartCommand implements Command
{
    public function __construct(
        private Output $output,
        private ProcessRunner $runner,
        private ShutdownHandler $shutdown,
    ) {}

    public function name(): string
    {
        return 'start';
    }

    public function usage(): array
    {
        return [
            '<slug>',
            'Bring a stopped worktree back up, without bootstrapping it again.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse($arguments);
        $name = $invocation->at(0);

        // An empty argument is `start "$ISSUE"` with nothing in `$ISSUE`, and is
        // the same mistake as no argument at all.
        if ($name === null || trim($name) === '') {
            throw new UsageException('name the worktree to start: an issue number, or a branch name');
        }

        if ($invocation->at(1) !== null) {
            throw new UsageException('start takes one name; given '.implode(' ', $invocation->positional));
        }

        $identities = Identities::fromConfiguration($config, $anchor, $this->runner, $this->output);
        $registry = Registry::fromConfiguration($config);
        $entry = $this->located($name, $identities, $anchor);

        // Before the entry is believed, and given back only when this process
        // ends: everything below is one indivisible start of one worktree.
        (new Locks($config->home, $this->shutdown, $this->output))->worktree($entry->key)->acquire();

        $entry = $this->stillRegistered($registry, $entry);

        $identity = new Identity($name, $entry->slug, $entry->key, $entry->branch, $entry->path);

        $this->refuseUnbootstrapped($entry);

        $environmentFile = EnvFile::for($this->output, $anchor->mainRoot)->generate($identity, $entry->ports, $config->env);

        $this->overlay()->generate($identity, $entry->ports, $config->compose, $environmentFile);

        SailRuntime::for($this->output, $this->runner, $config->bootTimeout)->boot(
            $identity,
            $environmentFile,
            'the worktree is still stopped',
            "'worktree start $name' brings it back up",
        );

        $this->output->line("started $entry->key at $entry->path; nothing was bootstrapped, and its slot never moved");

        return ExitCode::Success;
    }

    /**
     * The entry $name holds, refusing a key that is not this checkout's.
     *
     * The read-only lookup `path` and `stop` make ({@see Identities::locate()}),
     * and the refusal `remove` makes: a key is a Compose project name, so an
     * entry another checkout holds is another checkout's containers, and
     * starting them from here would bring up services this repository does not
     * own on ports it did not allocate.
     *
     * @throws WorktreeException when nothing holds $name, or another checkout does.
     */
    private function located(string $name, Identities $identities, Anchor $anchor): Entry
    {
        $entry = $identities->locate($name);

        if ($entry === null) {
            throw new WorktreeException(
                'no worktree of '.$identities->repoSlug()." is registered as '$name'; "
                ."'worktree create $name' makes one, and 'worktree list' shows the ones there are"
            );
        }

        if (! $entry->belongsTo($anchor->mainRoot)) {
            throw new WorktreeException(
                "'$entry->key' is registered to $entry->repo, not to ".rtrim($anchor->mainRoot, '/').'; '
                .'starting it from here would bring up that checkout\'s containers — run it from there, '
                ."or set 'repo_slug' in ".Schema::File.' to tell the two apart'
            );
        }

        return $entry;
    }

    /**
     * The entry as it stands now that the lock is held.
     *
     * Read again rather than trusted, because the first read happened outside
     * the lock: a `remove` this run queued behind has freed the slot by the time
     * it gets in, and booting the project it just tore down would put containers
     * back on the machine with nothing in the registry naming them.
     */
    private function stillRegistered(Registry $registry, Entry $entry): Entry
    {
        $current = $registry->entry($entry->key);

        if ($current !== null) {
            return $current;
        }

        throw new WorktreeException(
            "nothing in the registry holds $entry->key any more — something released it while this run was "
            ."waiting for its lock; 'worktree create $entry->slug' makes it again"
        );
    }

    /**
     * Refuse a worktree there is nothing to start: one whose directory is gone,
     * and one that never finished a bootstrap.
     *
     * Both name `create`, because `create` is the answer to both — it re-creates
     * the first on the same slot, and resumes the second with every finished
     * step skipped. `start` is not a second, weaker `create`, and a `start` that
     * quietly booted a half-built worktree would be exactly that.
     *
     * @throws WorktreeException
     */
    private function refuseUnbootstrapped(Entry $entry): void
    {
        if (! is_dir($entry->path)) {
            throw new WorktreeException(
                "$entry->key holds slot $entry->slot but there is no worktree at $entry->path any more; "
                ."there is nothing to start — 'worktree create $entry->slug' makes it again, and the branch "
                ."'$entry->branch' is still in the repository"
            );
        }

        if ((new Readiness($this->output, $this->excludes()))->isReady($entry->path)) {
            return;
        }

        throw new WorktreeException(
            "$entry->key has never finished a bootstrap: there is no ".Readiness::File." in $entry->path, "
            ."so its dependencies and its database may not be there — 'worktree create $entry->slug' resumes it, "
            .'on the same slot, skipping every step that already finished'
        );
    }

    private function overlay(): Overlay
    {
        return new Overlay($this->output, ComposeVersion::for($this->runner), $this->excludes());
    }

    private function excludes(): Excludes
    {
        return new Excludes($this->runner, $this->output);
    }
}
