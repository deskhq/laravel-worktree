<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Git\BaseRefs;
use DeskHQ\LaravelWorktree\Git\Worktrees;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Fleet;
use DeskHQ\LaravelWorktree\Registry\ForeignCheckout;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;
use DeskHQ\LaravelWorktree\Runtime\TeardownResult;

/**
 * Give a worktree's machine back: its containers, its volumes, its directory
 * and its slot.
 *
 * ```bash
 * ./vendor/bin/worktree remove 441
 * ```
 *
 * **The branch stays.** The work is the point and the infrastructure is
 * disposable, so nothing here touches a ref — a person who removes a worktree
 * has finished with the machine, not with the commits. Nothing reaches stdout
 * either: there is no answer for a script to read here, only an exit code.
 *
 * ## The registry is a convenience, not the source of truth
 *
 * Both facts a teardown needs are derivable from the name it was given: the
 * Compose project is `wt-<repo-slug>-<slug>`, and the worktree is a directory
 * named after the slug beside the checkout. So a key the registry does not hold
 * is a line on stderr rather than a refusal — and refusing was the bug: it left
 * a worktree somebody had removed by hand, or one whose entry a *failed*
 * teardown had already deleted, with no supported way to reclaim its volumes at
 * all (the-desk#1095).
 *
 * ## The order, and the failure that matters
 *
 * The per-worktree lock is taken before anything is read and held until the
 * process exits, so a concurrent `create` for this key waits rather than
 * slotting a replacement entry in behind a teardown that then deletes it.
 *
 * Then the project goes, then the worktree, then git's record of it, then the
 * entry — and **a teardown that left anything behind exits non-zero**, naming
 * what survived and naming `reap`. The entry is deleted either way, deliberately:
 * by that point the git side has genuinely finished, so the slot really is free,
 * and what failed is the disk. Freeing it while staying silent about surviving
 * volumes is the exact bug the-desk#1095 recorded.
 */
final readonly class RemoveCommand implements Command
{
    public function __construct(
        private Output $output,
        private ProcessRunner $runner,
        private ShutdownHandler $shutdown,
    ) {}

    public function name(): string
    {
        return 'remove';
    }

    public function usage(): array
    {
        return [
            '<slug>',
            'Tear down a worktree\'s containers and volumes, and free its slot.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse($arguments);
        $name = $invocation->at(0);

        // An empty argument is `remove "$ISSUE"` with nothing in `$ISSUE`, and
        // is the same mistake as no argument at all — answered here rather than
        // by the naming layer a moment later, which would call it operational.
        if ($name === null || trim($name) === '') {
            throw new UsageException('name the worktree to remove: an issue number, or a branch name');
        }

        if ($invocation->at(1) !== null) {
            throw new UsageException('remove takes one name; given '.implode(' ', $invocation->positional));
        }

        $fleet = Fleet::fromConfiguration($config, $anchor, $this->runner, $this->shutdown, $this->output);
        $identity = $fleet->identify($name);
        $worktrees = $this->worktrees($anchor);

        // Before the registry is read, and given back only when this process
        // ends: everything below is one indivisible teardown of one worktree.
        $fleet->claim($identity->key);

        $entry = $fleet->entry($identity->key);

        $worktree = $entry === null
            ? $this->derived($identity, $anchor, $worktrees)
            : $this->recorded($identity, $entry, $fleet);

        $result = SailRuntime::for($this->output, $this->runner)->teardown($worktree->key, $worktree->path);

        $worktrees->detach($worktree->path);

        // The slot comes back whatever the daemon did with the volumes, because
        // the git side is finished and the slot is genuinely free. What did not
        // work is said below, and loudly.
        $fleet->release($identity->key);

        return $this->report($worktree, $entry, $result);
    }

    /**
     * The worktree as the registry has it.
     *
     * The recorded path beats the derived one for the same reason `create`
     * resumes at it: a checkout that has moved, or a `repo_slug` set since the
     * worktree was made, derives a directory that was never there — and the
     * entry is the only record of where the thing actually is.
     */
    private function recorded(Identity $identity, Entry $entry, Fleet $fleet): Identity
    {
        // A key is a Compose project name, so an entry claimed by another
        // checkout is another checkout's containers and volumes — the collision
        // the allocator refuses on the way in, refused here on the way out
        // rather than destroyed.
        $fleet->verify($entry, ForeignCheckout::because(
            'removing it from here would tear down that checkout\'s containers'
        ));

        if ($entry->path === $identity->path) {
            return $identity;
        }

        $this->output->line("$identity->key is registered at $entry->path; removing that, rather than $identity->path");

        return new Identity($identity->name, $identity->slug, $identity->key, $entry->branch, $entry->path);
    }

    /**
     * The worktree as its name implies it, for a key nothing in the registry
     * holds.
     *
     * Announced, because a derived teardown is a guess made explicit: the
     * project name it is about to destroy for is on screen before it happens,
     * which is what makes proceeding without an entry safe to offer at all.
     */
    private function derived(Identity $identity, Anchor $anchor, Worktrees $worktrees): Identity
    {
        $path = $this->directory($identity, $anchor, $worktrees);

        $this->output->line(
            "nothing in the registry holds $identity->key, so this run goes by the name it was given: "
            ."the project $identity->key, and the worktree at $path"
        );

        return $path === $identity->path
            ? $identity
            : new Identity($identity->name, $identity->slug, $identity->key, $identity->branch, $path);
    }

    /**
     * Where the worktree is, when the registry cannot say.
     *
     * The derived path is where this configuration would put it, and is almost
     * always right. A repository whose `repo_slug` has changed since the
     * worktree was created keeps it under the *old* worktrees directory, so the
     * sibling `*-worktrees` directories are globbed for one holding this slug —
     * and a match is only believed once git confirms it is a worktree of this
     * repository, because two checkouts side by side have sibling directories
     * that look identical from the filesystem alone.
     */
    private function directory(Identity $identity, Anchor $anchor, Worktrees $worktrees): string
    {
        if (is_dir($identity->path)) {
            return $identity->path;
        }

        $pattern = dirname($anchor->mainRoot).'/*'.Identities::Directory.'/'.$identity->slug;

        foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $candidate) {
            if (! $worktrees->registers($candidate)) {
                continue;
            }

            $this->output->line("there is no worktree at $identity->path, but git has one of this repository's at $candidate");

            return $candidate;
        }

        return $identity->path;
    }

    /**
     * What the run leaves the person with: a slot back, or a slot back and a
     * disk that is still holding something.
     */
    private function report(Identity $worktree, ?Entry $entry, TeardownResult $result): int
    {
        if ($result->succeeded()) {
            $this->output->line(
                "removed $worktree->key".($entry === null ? '' : ", and slot $entry->slot is free again")
                ."; the branch '$worktree->branch' is untouched"
            );

            return ExitCode::Success;
        }

        $this->output->error($result->describe());

        // The two halves said separately, because they are separately true and
        // an operator acts on each: the slot is usable now, and the disk is not
        // accounted for. That second half holds whether a volume refused to go
        // or no daemon answered at all, and `reap` is the way back either way.
        $this->output->line(
            "$worktree->key is out of the registry and its slot is free — the git side finished — "
            ."but its containers and volumes are not accounted for; 'worktree reap' is the way back to them"
        );

        return ExitCode::Failure;
    }

    private function worktrees(Anchor $anchor): Worktrees
    {
        return new Worktrees($this->runner, $this->output, $anchor, new BaseRefs($this->runner, $anchor));
    }
}
