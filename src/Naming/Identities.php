<?php

namespace DeskHQ\LaravelWorktree\Naming;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Registry;

/**
 * One argument in, every name a run needs out (D2, and the naming half of D7).
 *
 * ```
 * worktree create 441             -> gh issue view 441 --json title -> 441-fix-login
 * worktree create feat/checkout   -> no lookup                      -> feat-checkout
 * worktree create --pr 441        -> gh pr view 441                 -> 441-fix-login
 * ```
 *
 * A numeric argument is the only thing that triggers the issue lookup; anything
 * else is used verbatim. One code path either way, and `gh` is never required
 * ({@see Issues}).
 *
 * `--pr` is the exception, and the only one: the three names above are what a
 * pull request's worktree gets too — so it is found by number like any other —
 * but the branch it is checked out on is the pull request's own head, which
 * nothing here can derive and only `gh` can say ({@see PullRequests}).
 *
 * ## The `wt-` marker is not configurable
 *
 * `reap` force-deletes Docker volumes, and it can only scope itself by project
 * name: service-level labels land on containers, labelling volumes would mean
 * enumerating every volume `compose.yaml` declares, and anonymous volumes from
 * a `VOLUME` directive cannot carry a custom label at all. The one label that
 * covers every volume a project owns is `com.docker.compose.project` — whose
 * value we control, because we write `COMPOSE_PROJECT_NAME`.
 *
 * So the marker is a safety mechanism rather than a style choice, and it is a
 * literal prefix no configuration can change or remove: `repo_slug` names the
 * repository *inside* the key, and that is the whole of the influence a config
 * file has. Overlapping with an unrelated Compose project on this daemon then
 * requires somebody to have deliberately named theirs `wt-`.
 */
final readonly class Identities
{
    /**
     * The prefix on every project name this package creates, and the entirety of
     * what makes `reap` safe. Not configurable, by design.
     */
    public const string Marker = 'wt-';

    /**
     * The directory holding one repository's worktrees sits beside the main
     * checkout — `../<repo-slug>-worktrees` — because a worktree nested inside
     * the checkout would be picked up by its own tooling: composer, the IDE's
     * indexer, `artisan test`, and git status itself.
     */
    public const string Directory = '-worktrees';

    public function __construct(
        private Anchor $anchor,
        private Registry $registry,
        private Issues $issues,
        private PullRequests $pullRequests,
        /** `repo_slug` as configured; null means the main checkout's directory name. */
        private ?string $configuredSlug,
    ) {}

    public static function fromConfiguration(
        Configuration $config,
        Anchor $anchor,
        ProcessRunner $runner,
        Output $output,
    ): self {
        return new self(
            $anchor,
            Registry::fromConfiguration($config),
            new Issues($runner, $output, $anchor),
            new PullRequests($runner, $output, $anchor),
            $config->repoSlug,
        );
    }

    /**
     * Every name $argument implies.
     *
     * The registry is consulted, but never written: a key another argument
     * already holds is refused here rather than silently re-entered, because
     * `feat/checkout` and `feat-checkout` slugify to the same project name while
     * meaning two different branches — and re-entering somebody else's worktree
     * would put this run's commits on their branch.
     *
     * @throws WorktreeException when $argument names nothing, derives a project name Docker would reject, or collides with a worktree created under another name.
     */
    public function identify(string $argument): Identity
    {
        $argument = trim($argument);

        if ($argument === '') {
            throw new WorktreeException('name the worktree: an issue number, or a branch name');
        }

        $numeric = preg_match('/\A\d+\z/', $argument) === 1;
        $slug = $numeric ? $this->issues->slug($argument) : Slug::of($argument);

        if ($slug === '') {
            throw new WorktreeException("'$argument' has nothing in it to name a worktree with; give it letters or digits");
        }

        $key = self::Marker.$this->repoSlug().'-'.$slug;

        // Unreachable while both halves are what they claim to be, which is the
        // point: a project name is validated where it is built, not left for
        // Docker to reject minutes into a bootstrap.
        if (! Slug::isProjectName($key)) {
            throw new WorktreeException(
                "'$argument' derives the project name '$key', which Docker will not accept "
                .'(it must match [a-z0-9][a-z0-9_-]*)'
            );
        }

        $identity = new Identity(
            $argument,
            $slug,
            $key,
            // A numeric argument has no branch of its own to preserve — the slug
            // *is* the name it asked for. A named one means the ref it typed.
            $numeric ? $slug : $argument,
            dirname($this->anchor->mainRoot).'/'.$this->repoSlug().self::Directory.'/'.$slug,
        );

        $this->refuseCollision($identity);

        return $identity;
    }

    /**
     * Every name pull request $number implies.
     *
     * The names are the ones a numeric argument gets — `441-fix-login`, and the
     * key, path and project that follow from it — so a worktree made for a pull
     * request is found by `path 441`, removed by `remove 441` and completed like
     * any other. What differs is the **branch**: the pull request was opened
     * from one that already exists, and that ref is the point of the whole
     * flag, so it is asked for rather than derived ({@see PullRequests}).
     *
     * Except on the way back in. A worktree this checkout already has under
     * this key is on whatever branch the checkout actually landed on — which,
     * for a pull request from a fork, is a name gh chose and this run cannot
     * reproduce — so the entry's own record wins. That is also why the
     * collision refusal {@see identify()} makes has no counterpart here: a
     * number names one pull request, and the branch disagreeing with the name
     * is the ordinary case rather than the suspicious one.
     *
     * @throws WorktreeException when $number is not a number, when `gh` cannot say what the pull request's head is, or when the name derives a project name Docker would reject.
     */
    public function identifyPullRequest(string $number): Identity
    {
        $number = trim($number);

        if (preg_match('/\A\d+\z/', $number) !== 1) {
            throw new WorktreeException("'--pr' takes a pull request number, and '$number' is not one");
        }

        $pull = $this->pullRequests->view($number);
        $key = self::Marker.$this->repoSlug().'-'.$pull->slug;

        if (! Slug::isProjectName($key)) {
            throw new WorktreeException(
                "pull request $number derives the project name '$key', which Docker will not accept "
                .'(it must match [a-z0-9][a-z0-9_-]*)'
            );
        }

        return new Identity(
            $number,
            $pull->slug,
            $key,
            $this->registeredBranch($key) ?? $pull->headRef,
            dirname($this->anchor->mainRoot).'/'.$this->repoSlug().self::Directory.'/'.$pull->slug,
        );
    }

    /**
     * The entry $argument names, or null when nothing holds it.
     *
     * The read-only half of {@see identify()}, and the one `path` runs: it
     * derives the same key from the same argument, but it asks nothing outside
     * the registry — no `gh`, and nothing that could create, retry or verify
     * anything. A lookup that shelled out to name issue 441 would be a lookup
     * that fails when somebody is offline.
     *
     * That is also why a numeric argument is *searched* for rather than derived.
     * `441` becomes `441-fix-login` or `issue-441` depending on what `gh` said
     * the day the worktree was made, and neither is recoverable from the number
     * — but both are already written down, in a key beginning with this
     * repository's prefix. So the number is matched against the slugs that are
     * there, which is the same answer without the round-trip.
     *
     * Machine-global, exactly as {@see identify()} is: a key held by another
     * checkout is found here and left for the caller to refuse, because "two
     * clones share a project name" is a different thing to say than "nothing
     * holds this name".
     *
     * @throws WorktreeException when $argument names nothing, or when a number names more than one worktree.
     */
    public function locate(string $argument): ?Entry
    {
        $argument = trim($argument);

        if ($argument === '') {
            throw new WorktreeException('name the worktree: an issue number, or a branch name');
        }

        if (preg_match('/\A\d+\z/', $argument) === 1) {
            return $this->numbered($argument);
        }

        $slug = Slug::of($argument);

        if ($slug === '') {
            throw new WorktreeException("'$argument' has nothing in it to name a worktree with; give it letters or digits");
        }

        return $this->registry->entry(self::Marker.$this->repoSlug().'-'.$slug);
    }

    /**
     * The one worktree of this repository whose slug names issue $number.
     *
     * Matched on the key rather than on the entry's `slug` field, because the
     * key is the identifier — it is what Docker, the registry and `reap` all
     * agree on, and an entry somebody has edited by hand can disagree with it.
     *
     * @throws WorktreeException when two of them do, which no run of `create` produces but a retitled issue can leave behind.
     */
    private function numbered(string $number): ?Entry
    {
        $prefix = self::Marker.$this->repoSlug().'-';
        $matches = [];

        foreach ($this->registry->all() as $key => $entry) {
            if (str_starts_with($key, $prefix) && self::namesIssue(substr($key, strlen($prefix)), $number)) {
                $matches[] = $entry;
            }
        }

        if (count($matches) > 1) {
            throw new WorktreeException(
                "'$number' names more than one worktree of ".$this->repoSlug().': '
                .implode(', ', array_map(fn (Entry $entry): string => $entry->key, $matches))
                .'; name the one you meant by its slug'
            );
        }

        return $matches[0] ?? null;
    }

    /**
     * Whether $slug is what an issue number becomes: the number, whatever title
     * `gh` folded onto it, or the `issue-<n>` used when `gh` could not answer.
     */
    private static function namesIssue(string $slug, string $number): bool
    {
        return $slug === $number || $slug === 'issue-'.$number || str_starts_with($slug, $number.'-');
    }

    /**
     * What names this repository inside keys, project names and the worktrees
     * directory: `repo_slug`, or the main checkout's directory name.
     *
     * The configured value arrived through {@see Schema}, which already refused
     * anything Compose would not take. A directory name has been through no such
     * check, so it is slugified — `The Desk` and `the-desk` name the same
     * repository, and only one of them is a legal project name.
     */
    public function repoSlug(): string
    {
        if ($this->configuredSlug !== null) {
            return $this->configuredSlug;
        }

        $directory = basename($this->anchor->mainRoot);
        $slug = Slug::of($directory);

        if ($slug === '') {
            throw new WorktreeException(
                "the checkout's directory name ('$directory') has nothing in it to name this repository with; "
                ."set 'repo_slug' in ".Schema::File
            );
        }

        return $slug;
    }

    /**
     * The branch the worktree registered under $key is on, when this checkout
     * has one at all.
     */
    private function registeredBranch(string $key): ?string
    {
        $entry = $this->registry->entry($key);

        return $entry !== null && $entry->belongsTo($this->anchor->mainRoot) ? $entry->branch : null;
    }

    /**
     * Refuse a key this checkout already holds under a different branch.
     *
     * Only the branch can tell the two apart: it is the one name kept verbatim,
     * so an entry created by `feat/checkout` and an argument of `feat-checkout`
     * agree on every derived name and disagree on exactly that. A key held by
     * *another* checkout is a different problem, and the allocator refuses it
     * with the message that fits — two clones sharing a project name.
     */
    private function refuseCollision(Identity $identity): void
    {
        $entry = $this->registry->entry($identity->key);

        if ($entry === null || ! $entry->belongsTo($this->anchor->mainRoot) || $entry->branch === $identity->branch) {
            return;
        }

        throw new WorktreeException(
            "'$identity->name' and '$entry->branch' both name '$identity->key', but they are different branches; "
            ."the worktree at $entry->path is on '$entry->branch' — use that name, or pick one that slugifies differently"
        );
    }
}
