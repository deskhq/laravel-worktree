<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Fleet;
use DeskHQ\LaravelWorktree\Registry\ForeignCheckout;
use DeskHQ\LaravelWorktree\Registry\Verdict;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;

/**
 * Give a machine its memory back without giving up the worktree (#56).
 *
 * ```bash
 * worktree stop 441
 * worktree stop --all-except 441
 * ```
 *
 * There used to be two states a worktree could be in — bootstrapped, or gone —
 * and one transition between them. That is a large gap in the middle, because
 * an *idle* worktree costs real memory: its own Postgres, its own Redis, its
 * own app container, per worktree. Five of them is an ordinary number for the
 * fleet this package is built for, and it is most of a laptop. The only way to
 * get that memory back was `remove`, which also destroys the database, the
 * sentinels, the `.env` somebody edited and the slot — for a branch they intend
 * to work on tomorrow.
 *
 * So this stops the containers and nothing else. The volumes stay, the working
 * tree stays, the sentinels stay, the registry entry stays, and `start` brings
 * it back without a bootstrap. Nothing reaches stdout: there is no answer for a
 * script to read here, only an exit code, as with `remove`.
 *
 * ## `stop`, and not `down`
 *
 * `compose down` would reclaim a little more — the containers themselves, and
 * with them a stale one — and it keeps the volumes too, so it would have been a
 * defensible reading of the same command. It is not this one, because the
 * containers are what make {@see StartCommand} a restart rather than a build,
 * and the cost of the state it leaves is a few kilobytes of metadata against
 * the gigabytes of memory this run is here to reclaim. A `--down` for whoever
 * wants the other trade is a flag away, and is deliberately not here until
 * somebody does.
 *
 * ## The slot stays claimed, deliberately
 *
 * Releasing it would let another worktree take those ports, and `start` would
 * then have nowhere to come back to — the database would be there and the
 * addresses in the `.env` would be somebody else's. A stopped worktree is one
 * you intend to return to, and holding a slot costs nothing but a number.
 *
 * ## The one people actually type
 *
 * The real workflow is not "stop this one", it is "I am working on this one
 * now": an agent picking up a task wants the other nine idle. That is
 * `--all-except <slug>`, with `--all` for the whole repository underneath it.
 *
 * The worktree named by `--all-except` has to be one the registry holds. A
 * mistyped name that meant "keep this running" would otherwise stop everything
 * *including* the one it was meant to protect, which is the exact opposite of
 * what was asked, so it is refused rather than treated as an empty exception.
 *
 * ## Scope, and the word for it
 *
 * `--all-repos` widens either bulk form from this checkout's worktrees to every
 * worktree on the machine — the scope `list --all` and `reap --all` mean by
 * `--all`. It is spelled differently because `--all` is not free here: `list`
 * and `reap` have a subject already and use `--all` to widen it, while `stop`
 * has no default subject at all, so `--all` has to *be* the subject and the
 * widening needs its own word. Leaving this checkout still takes an explicit
 * flag, which is the part of `reap`'s care about acting on things the person
 * cannot see that applies to a command that destroys nothing.
 *
 * ## The lock, and the re-check that is not here
 *
 * Each project is stopped holding the same per-worktree lock `create` and
 * `remove` take, since stopping a project mid-bootstrap is precisely the race
 * it exists to prevent. One key at a time, released between, so stopping a
 * machineful does not block every unrelated command for the duration —
 * `reap`'s discipline exactly.
 *
 * What is deliberately *not* copied from `reap` is its re-read of the registry
 * under each lock. `reap` re-checks because it force-deletes volumes and a
 * stale scan would destroy something that came back; here the worst a stale
 * scan can do is stop containers a moment after something else claimed the key,
 * which `start` undoes in one command.
 */
final readonly class StopCommand implements Command
{
    /** Stop every worktree in scope, rather than one named worktree. */
    public const string All = 'all';

    /** The same, less the one named — "I am working on this one now". */
    public const string AllExcept = 'all-except';

    /** Widen either of those from this checkout's worktrees to the machine's. */
    public const string AllRepos = 'all-repos';

    public function __construct(
        private Output $output,
        private ProcessRunner $runner,
        private ShutdownHandler $shutdown,
    ) {}

    public function name(): string
    {
        return 'stop';
    }

    public function usage(): array
    {
        return [
            '<slug> | --all | --all-except <slug> [--all-repos]',
            'Stop a worktree\'s containers, keeping its volumes, its files and its slot.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse($arguments, [self::All, self::AllExcept, self::AllRepos]);
        $name = $invocation->at(0);
        $named = $name !== null && trim($name) !== '';

        $this->verify($invocation, $name, $named);

        $fleet = Fleet::fromConfiguration($config, $anchor, $this->runner, $this->shutdown, $this->output);

        $targets = $invocation->has(self::All) || $invocation->has(self::AllExcept)
            ? $this->everything($invocation, $name, $fleet)
            : self::only($this->named((string) $name, $fleet));

        if ($targets === []) {
            $this->output->line(
                'nothing to stop: no worktree'
                .($invocation->has(self::AllRepos) ? ' on this machine' : ' of '.$fleet->repoSlug())
                .' holds a slot'
            );

            return ExitCode::Success;
        }

        return $this->quieten($targets, $fleet);
    }

    /**
     * Refuse the invocations that mean two things at once, or nothing at all.
     *
     * Called wrong rather than failed, every one of them, so they exit
     * `EX_USAGE` before the registry has been read.
     *
     * @throws UsageException
     */
    private function verify(Arguments $invocation, ?string $name, bool $named): void
    {
        if ($invocation->has(self::All) && $invocation->has(self::AllExcept)) {
            throw new UsageException(
                '--all and --all-except are two different runs; --all stops every worktree, '
                .'--all-except stops every worktree but the one you name'
            );
        }

        if ($invocation->at(1) !== null) {
            throw new UsageException('stop takes one name; given '.implode(' ', $invocation->positional));
        }

        if ($invocation->has(self::All) && $named) {
            throw new UsageException(
                "stop --all stops every worktree and takes no name, and '$name' is one; "
                ."'stop --all-except $name' is the run that keeps that one going"
            );
        }

        if ($invocation->has(self::AllExcept) && ! $named) {
            throw new UsageException('--all-except takes the name of the worktree to keep running');
        }

        if ($invocation->has(self::AllRepos) && ! $invocation->has(self::All) && ! $invocation->has(self::AllExcept)) {
            throw new UsageException(
                '--all-repos widens --all and --all-except to every worktree on this machine; '
                .'a worktree named on its own is one worktree wherever it is'
            );
        }

        if (! $invocation->has(self::All) && ! $invocation->has(self::AllExcept) && ! $named) {
            throw new UsageException(
                'name the worktree to stop: an issue number, or a branch name — '
                .'or --all for every worktree of this repository'
            );
        }
    }

    /**
     * The worktrees a bulk run acts on, in slot order.
     *
     * Scoped to this checkout unless `--all-repos`, exactly as `list` scopes its
     * table: the registry is machine-global because host ports are, and a run
     * that quietly reached into another clone's worktrees would be stopping
     * containers nobody at this terminal can see.
     *
     * @return array<string, Entry>
     */
    private function everything(Arguments $invocation, ?string $name, Fleet $fleet): array
    {
        $entries = $invocation->has(self::AllRepos) ? $fleet->everywhere() : $fleet->here();

        if ($invocation->has(self::AllExcept)) {
            // Resolved through the registry, and refused when nothing holds it:
            // a typo here would stop the very worktree it was typed to protect.
            $keep = $this->named((string) $name, $fleet);

            $entries = array_diff_key($entries, self::only($keep));

            $this->output->line("keeping $keep->key running");
        }

        return $entries;
    }

    /**
     * The one worktree $name holds, refusing a key that is not this checkout's.
     *
     * The read-only lookup `path` makes ({@see Fleet::locate()}): no `gh`,
     * nothing written, and a number matched against the registry rather than
     * derived, since `441` became `441-fix-login` or `issue-441` depending on
     * what `gh` said the day the worktree was made.
     *
     * No `create` in the refusal, deliberately: somebody who mistyped the name
     * of a worktree they meant to stop is not asking to bootstrap one.
     *
     * @throws WorktreeException when nothing holds $name, or another checkout does.
     */
    private function named(string $name, Fleet $fleet): Entry
    {
        // A key is a Compose project name, so an entry another checkout holds is
        // another checkout's containers — the collision `remove` refuses on the
        // way out, refused here in the same words.
        return $fleet->require($name, ForeignCheckout::because(
            'stopping it from here would stop that checkout\'s containers'
        ));
    }

    /**
     * One entry in the shape a sweep wants: keyed by the project name its
     * lock, its containers and its registry row all agree on.
     *
     * @return array<string, Entry>
     */
    private static function only(Entry $entry): array
    {
        return [$entry->key => $entry];
    }

    /**
     * Stop each project under its own lock, and report what would not go.
     *
     * @param  array<string, Entry>  $targets
     */
    private function quieten(array $targets, Fleet $fleet): int
    {
        $runtime = SailRuntime::for($this->output, $this->runner);

        $swept = $fleet->sweep($targets, fn (Entry $entry, string $key): Verdict => Verdict::of(
            // The directory is an optimisation, not a requirement: an entry
            // whose worktree somebody deleted still names containers.
            $runtime->stop($key, is_dir($entry->path) ? $entry->path : null),
        ));

        return $this->report($swept->succeeded, $swept->survived);
    }

    /**
     * What the run leaves the person with — and, on the ordinary run, the fact
     * that makes this command worth having: nothing was destroyed.
     *
     * @param  list<string>  $stopped
     * @param  list<string>  $failed
     */
    private function report(array $stopped, array $failed): int
    {
        if ($stopped !== []) {
            $this->output->line(
                'stopped '.count($stopped).' '.(count($stopped) === 1 ? 'worktree' : 'worktrees').': '
                .implode(', ', $stopped).'; their volumes, files and slots are untouched — '
                ."'worktree start <slug>' brings one back"
            );
        }

        if ($failed === []) {
            return ExitCode::Success;
        }

        $this->output->error(
            count($failed).' of them did not stop: '.implode(', ', $failed)
            ."; 'worktree list' says what is up, and what each one said is above"
        );

        return ExitCode::Failure;
    }
}
