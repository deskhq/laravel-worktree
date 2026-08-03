<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Bootstrap\Outcome;
use DeskHQ\LaravelWorktree\Bootstrap\Pipeline;
use DeskHQ\LaravelWorktree\Bootstrap\Readiness;
use DeskHQ\LaravelWorktree\Compose\AppService;
use DeskHQ\LaravelWorktree\Compose\ComposeVersion;
use DeskHQ\LaravelWorktree\Compose\Overlay;
use DeskHQ\LaravelWorktree\Compose\PublishedPorts;
use DeskHQ\LaravelWorktree\Compose\Services;
use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\EnvFile;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Git\BaseRefs;
use DeskHQ\LaravelWorktree\Git\Excludes;
use DeskHQ\LaravelWorktree\Git\Worktrees;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Naming\PullRequests;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Allocator;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Fleet;
use DeskHQ\LaravelWorktree\Runtime\Runtime;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;

/**
 * Every other layer, in the order one worktree needs them.
 *
 * ```bash
 * cd "$(vendor/bin/worktree create 441)"
 * ```
 *
 * That line is the contract: the **path**, on stdout, alone, on a run that also
 * produced megabytes of Composer and npm output. Nothing here writes to stdout
 * except {@see Emitter}, and no subprocess is ever handed a descriptor that
 * reaches it ({@see ProcessRunner}), so the guarantee holds by construction
 * rather than by every call site remembering to redirect (the-desk#1043).
 * `--json` swaps the path for the worktree's whole registry entry, which is the
 * machine-readable need an MCP server would otherwise have been invented for.
 *
 * ## Three entry states
 *
 * **Ready.** `.worktree-ready` is in the worktree. Re-read `HEAD`, retry
 * whatever degraded, print the path — no `.env`, no overlay, and no container
 * round-trip, because moving between worktrees all day is the thing this
 * command is for.
 *
 * **Registered but not ready.** A run was interrupted. Resume on the *same*
 * slot and the *same* path; the recipe runs again from the top, and step
 * sentinels make what already finished cheap to skip.
 *
 * **Unregistered.** Allocate a slot, claim it, bootstrap.
 *
 * An entry whose directory somebody deleted by hand is none of the three: it is
 * forgotten and made again, with a line saying so, because there is nothing
 * left to resume and the slot it holds would otherwise never come back.
 *
 * ## `--pr`, the other thing worktrees are for (#59)
 *
 * ```bash
 * cd "$(vendor/bin/worktree create --pr 441)"
 * ```
 *
 * Every other create cuts a new branch from a base, which is right for picking
 * up an issue and wrong for the case people reach for a worktree just as often:
 * check somebody's pull request out, run it, and look at it. `--pr` names the
 * worktree from the number and the pull request's title — exactly as a numeric
 * slug is named, so it is found by number afterwards like any other — and
 * attaches its **head branch** rather than forking from it. The slot, the
 * ports, the overlay, the `.env`, the bootstrap and the three entry states above
 * are unchanged.
 *
 * Two things about it are genuinely different. `gh` stops being optional, since
 * the head branch is a fact only the forge holds ({@see PullRequests}). And the
 * branch is read back off the worktree rather than predicted, because a pull
 * request from a fork lands on a name gh chooses
 * ({@see Worktrees::attachPullRequest()}).
 *
 * Re-entry deliberately fetches nothing. The branch belongs to somebody else
 * and it moves; a create that quietly fast-forwarded — or worse, reset — a
 * worktree with local commits in it would be far more expensive than one that
 * left it stale.
 *
 * ## Lock discipline
 *
 * The **per-worktree lock** is taken before anything is read and held until the
 * process exits, so a stray second `create 441` waits and then re-enters rather
 * than racing git, Composer, Sail and npm against the first in one directory.
 * The **registry lock** covers the free-slot search and the claim that follows
 * it and nothing else ({@see Allocator}) — held across a five-minute bootstrap
 * it would serialise every unrelated worktree on the machine.
 *
 * Both are released by the run's shutdown handler however it ends, so an
 * interrupted bootstrap strands nothing; its registry entry deliberately
 * survives, because that entry is what the next run resumes.
 */
final readonly class CreateCommand implements Command
{
    /** Run the whole recipe against a worktree that is already ready. */
    public const string Refresh = 'refresh';

    /** Read the argument as a pull request number, and check its head out. */
    public const string PullRequest = 'pr';

    /** Emit the worktree's registry entry instead of its path. */
    public const string Json = 'json';

    public function __construct(
        private Output $output,
        private Emitter $emitter,
        private ProcessRunner $runner,
        private ShutdownHandler $shutdown,
    ) {}

    public function name(): string
    {
        return 'create';
    }

    public function usage(): array
    {
        return [
            '<slug> [base] [--pr] [--refresh] [--json]',
            'Create (or re-enter) an isolated worktree; prints its absolute path.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse(
            $arguments,
            [self::PullRequest, self::Refresh, self::Json],
            takes: Arity::nameAndBase($this->name()),
        );

        $name = $invocation->at(0);
        $pullRequest = $invocation->has(self::PullRequest);

        // The one missing-name refusal {@see Arity} cannot write, because what
        // to call the thing changes with the flag. An empty argument is the
        // same mistake as no argument — `create "$ISSUE"` with nothing in
        // `$ISSUE` — and gets the same answer, rather than the operational
        // failure the naming layer would report a moment later.
        if ($name === null || trim($name) === '') {
            throw new UsageException($pullRequest
                ? 'name the pull request: its number'
                : 'name the worktree: an issue number, or a branch name');
        }

        // A base is the one argument `--pr` cannot mean: the head branch is
        // checked out as it stands rather than forked from anything, so a
        // second argument here is somebody expecting the opposite of what they
        // would get.
        if ($pullRequest && $invocation->at(1) !== null) {
            throw new UsageException(
                "create --pr takes a pull request number and nothing else; a pull request's head is checked out, "
                .'not forked from a base — given '.implode(' ', $invocation->positional)
            );
        }

        $fleet = Fleet::fromConfiguration($config, $anchor, $this->runner, $this->shutdown, $this->output);
        $identity = $pullRequest ? $fleet->identifyPullRequest($name) : $fleet->identify($name);

        // Before anything is read, and given back only when this process ends.
        $fleet->claim($identity->key);

        $entry = $this->registered($fleet, $identity);

        // Whether git has to be asked for anything at all beyond a verification:
        // a worktree this checkout already has is entered again as it is, and
        // that is the whole of what keeps `--pr` from moving somebody's branch
        // under them on the way back in (#59).
        $known = $entry !== null;

        if ($entry !== null) {
            $identity = $this->resumedAt($identity, $entry);
        }

        // Building the runtime asks nothing of Docker; only boot() and the
        // `sail:` steps do, which is what keeps a ready re-entry free of it.
        $runtime = SailRuntime::for($this->output, $this->runner, $config->bootTimeout);

        if ($entry !== null && ! $invocation->has(self::Refresh) && $this->readiness()->isReady($identity->path)) {
            $outcome = $this->reenter($identity, $entry, $config, $anchor, $runtime);
        } else {
            // The last free moment: after this a slot is claimed, a directory
            // exists and a refusal costs a teardown. Pure parsing, no daemon.
            $this->preflight($anchor, $config);

            $entry = $fleet->allocate($identity->key, $identity->slug, $identity->branch, $identity->path);

            // Git first, because there is nowhere to write anything until the
            // directory exists — and, for a pull request from a fork, because
            // the branch it lands on is not a name this run chose and the entry
            // has to be told what it turned out to be.
            if ($pullRequest && ! $known) {
                [$identity, $entry] = $this->checkedOut(
                    $fleet,
                    $identity,
                    $entry,
                    $this->worktrees($anchor)->attachPullRequest($identity->path, $identity->name, $identity->branch),
                );
            } else {
                $this->worktrees($anchor)->attach(
                    $identity->path,
                    $identity->branch,
                    $invocation->at(1) ?? $config->baseBranch,
                );
            }

            $outcome = $this->bootstrap($identity, $entry, $config, $anchor, $runtime);
        }

        $entry = $this->record($fleet, $entry, $outcome);

        // The one line of this run that reaches the caller.
        $this->emitter->emit($invocation->has(self::Json) ? $entry->toJson() : $entry->path);

        // And the notices after it, deliberately: one printed where it happened
        // scrolls away behind four minutes of asset building (the-desk#1005).
        $outcome->announce($this->output);

        return ExitCode::Success;
    }

    /**
     * What can be known about this repository before anything exists to undo.
     *
     * Both halves are pure parsing of the main checkout's Compose file — no
     * daemon, no slot, nothing written — and both are checks `worktree doctor`
     * makes. The published-port collision is a refusal, because the alternative
     * is `Bind for :::6379 failed` minutes in ({@see PublishedPorts}). The app
     * service is a line rather than a refusal, for the reason
     * {@see Services::undeclared()} gives — but it is said *here*, before the
     * throwaway Composer container spends minutes on a `vendor/` for a boot that
     * is going to fail on the service name (#74).
     *
     * @throws WorktreeException when a service this worktree starts publishes a host port nothing offsets.
     */
    private function preflight(Anchor $anchor, Configuration $config): void
    {
        // Read once and handed to both: a file parsed twice is a file two
        // checks of one run can disagree about.
        $services = Services::in($anchor->mainRoot);
        $appService = AppService::at($anchor->mainRoot);
        $undeclared = $services->undeclared($appService);

        if ($undeclared !== null) {
            $this->output->line($undeclared);
        }

        (new PublishedPorts($services, $appService))->verify($config);
    }

    /**
     * The entry this key already holds, with the one state that is not a resume
     * dealt with here: an entry whose worktree is no longer on disk.
     *
     * Forgetting it is what makes the slot come back — and re-creating rather
     * than failing is what a person who deleted a directory expects, since the
     * branch, and therefore the work, is still in the repository.
     */
    private function registered(Fleet $fleet, Identity $identity): ?Entry
    {
        $entry = $fleet->entry($identity->key);

        if ($entry === null || is_dir($entry->path)) {
            return $entry;
        }

        $this->output->line(
            "the registry has $identity->key on slot $entry->slot at $entry->path, "
            .'but there is no directory there any more; forgetting that entry and creating the worktree again'
        );

        $fleet->release($identity->key);

        return null;
    }

    /**
     * The identity a resume actually runs against.
     *
     * The path the entry records beats the one this run derives, for the same
     * reason its ports do: a `repo_slug` changed since the worktree was created
     * would otherwise bootstrap a second directory onto a slot the first one is
     * still published on.
     */
    private function resumedAt(Identity $identity, Entry $entry): Identity
    {
        if ($entry->path === $identity->path) {
            return $identity;
        }

        $this->output->line("$identity->key is registered at $entry->path; resuming there rather than at $identity->path");

        return new Identity($identity->name, $identity->slug, $identity->key, $identity->branch, $entry->path);
    }

    /**
     * A worktree that has already been through a bootstrap.
     *
     * Two things still happen. `HEAD` is re-read, so a worktree somebody
     * switched branches in by hand is caught before anything runs in it — the
     * shape of the-desk#619, and one `rev-parse`. And the steps recorded as
     * degraded are retried, because a step degrades over an unreachable
     * registry or a download that timed out far more often than because it is
     * genuinely broken, and this is the natural moment to try again.
     *
     * A worktree with nothing degraded therefore reaches Docker not at all.
     */
    private function reenter(
        Identity $identity,
        Entry $entry,
        Configuration $config,
        Anchor $anchor,
        Runtime $runtime,
    ): Outcome {
        $this->output->line("$identity->key is ready; re-entering it at $identity->path");

        $this->worktrees($anchor)->attach($identity->path, $identity->branch);

        if ($entry->degraded === []) {
            return new Outcome;
        }

        return $this->pipeline($runtime)->run(
            $identity,
            $entry->ports,
            $config->steps,
            $this->environmentFile($identity, $entry, $config, $anchor),
            $entry->degraded,
        );
    }

    /**
     * What the branch really turned out to be, on the identity and on the entry.
     *
     * Only `--pr` can get here with a surprise: gh checks a fork's head out
     * under a name it decides — `<owner>/main`, when the head ref would collide
     * with the default branch — and the registry, the `{{branch}}` placeholder
     * and `remove`'s reassurance that the work outlives the directory all have
     * to be talking about the branch the worktree is actually on.
     *
     * @return array{Identity, Entry}
     */
    private function checkedOut(Fleet $fleet, Identity $identity, Entry $entry, string $branch): array
    {
        if ($branch === $identity->branch) {
            return [$identity, $entry];
        }

        $this->output->line(
            "pull request $identity->name is checked out on '$branch' rather than '$identity->branch'; "
            .'that is the branch the registry records'
        );

        $entry = $entry->withBranch($branch);

        $fleet->record($entry);

        return [new Identity($identity->name, $identity->slug, $identity->key, $branch, $identity->path), $entry];
    }

    /**
     * A worktree from wherever it currently is to ready.
     *
     * Every position in this order is load-bearing. The directory is already
     * attached, because there is nowhere to write anything until it exists. The
     * `.env` before the overlay, because the overlay is what points
     * `SAIL_FILES` at itself inside it. The overlay before the boot, because it
     * is what decides which services come up. And `.worktree-ready` after the
     * steps rather than before them, or a half-built worktree is one the next
     * run re-enters.
     *
     * The `.env` is written once and the overlay every time, and the asymmetry
     * is deliberate: the `.env` carries somebody's debugging edits and a resume
     * must not revert them, while the overlay carries nothing but this
     * configuration — so rewriting it is how a changed `config/worktree.php`,
     * or a slot whose ports have moved, takes effect on re-entry.
     */
    private function bootstrap(
        Identity $identity,
        Entry $entry,
        Configuration $config,
        Anchor $anchor,
        Runtime $runtime,
    ): Outcome {
        $environmentFile = $this->environmentFile($identity, $entry, $config, $anchor);

        $this->overlay()->generate($identity, $entry->ports, $config->compose, $environmentFile);

        $runtime->boot($identity, $environmentFile);

        $outcome = $this->pipeline($runtime)->run($identity, $entry->ports, $config->steps, $environmentFile);

        $this->readiness()->mark($identity->path);

        return $outcome;
    }

    /**
     * Put what the bootstrap left behind on the entry, when it changed anything.
     *
     * Not written otherwise, so re-entering a healthy worktree mutates no
     * machine-global state at all — which is also what keeps it off the
     * registry lock that every other repository on this machine is sharing.
     */
    private function record(Fleet $fleet, Entry $entry, Outcome $outcome): Entry
    {
        if ($entry->degraded === $outcome->names()) {
            return $entry;
        }

        $entry = $entry->withDegraded($outcome->names());

        $fleet->record($entry);

        return $entry;
    }

    /**
     * The worktree's own environment file, generated if it has none.
     *
     * Wanted on both paths, and for the same reason on each: it is what the
     * overlay writes `SAIL_FILES` into, what the runtime reads `APP_SERVICE`
     * from, and what an `env_empty:` condition asks its question of.
     */
    private function environmentFile(Identity $identity, Entry $entry, Configuration $config, Anchor $anchor): string
    {
        return EnvFile::for($this->output, $anchor->mainRoot)->generate($identity, $entry->ports, $config->env);
    }

    private function worktrees(Anchor $anchor): Worktrees
    {
        return new Worktrees($this->runner, $this->output, $anchor, new BaseRefs($this->runner, $anchor));
    }

    private function overlay(): Overlay
    {
        return new Overlay($this->output, ComposeVersion::for($this->runner), $this->excludes());
    }

    private function pipeline(Runtime $runtime): Pipeline
    {
        return Pipeline::using($this->output, $this->runner, $runtime->shell());
    }

    private function readiness(): Readiness
    {
        return new Readiness($this->output, $this->excludes());
    }

    private function excludes(): Excludes
    {
        return new Excludes($this->runner, $this->output);
    }
}
