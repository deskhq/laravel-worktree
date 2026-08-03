<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;

/**
 * Where a worktree is, asked without changing anything.
 *
 * ```bash
 * worktree path 441
 * ```
 *
 * `create` is what you run to *enter* a worktree, and it prints the path because
 * `cd "$(...)"` is the point. This is what a script runs to *find* one, and the
 * distinction is the whole reason it exists: `create` is the write path, and it
 * does four things a lookup must not.
 *
 * It takes the per-worktree lock and holds it for the run, so asking where a
 * worktree is can block on a bootstrap that is still building it. It retries
 * whatever the entry records as degraded, so a `cd` can start a Playwright
 * install. It re-reads and verifies `HEAD`, so an entry parked on the wrong
 * branch fails a question that was only about a directory. And with no entry at
 * all it makes one — so `path 441` on a typo is a line on stderr and an exit
 * code, where `create 441` on the same typo is five minutes of Docker and a slot
 * that has to be given back.
 *
 * So: no lock, no daemon, no `gh`, no git beyond the anchor every command
 * already pays for, and nothing written anywhere. The registry is read, the
 * entry is printed, and the run is over.
 *
 * `--json` prints the whole entry instead, in the shape `create --json` prints
 * ({@see Entry::toJson()}), so a caller that wants the ports does not have to
 * pipe `list --json` through `jq`.
 *
 * ## What it does not do
 *
 * It does not check that the directory is still there. An entry whose worktree
 * somebody deleted by hand is a real problem, but it is the same problem `list`
 * and `remove` have, and it wants one answer across all three rather than a
 * second vocabulary invented here.
 */
final readonly class PathCommand implements Command
{
    /** Emit the worktree's registry entry instead of its path. */
    public const string Json = 'json';

    public function __construct(
        private Output $output,
        private Emitter $emitter,
        private ProcessRunner $runner,
    ) {}

    public function name(): string
    {
        return 'path';
    }

    public function usage(): array
    {
        return [
            '<slug> [--json]',
            'Print where a worktree is, creating and changing nothing.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse($arguments, [self::Json]);
        $name = $invocation->at(0);

        // An empty argument is `path "$ISSUE"` with nothing in `$ISSUE` — the
        // mistake this command exists to make cheap, so it is answered as one.
        if ($name === null || trim($name) === '') {
            throw new UsageException('name the worktree to look up: an issue number, or a branch name');
        }

        if ($invocation->at(1) !== null) {
            throw new UsageException('path takes one name; given '.implode(' ', $invocation->positional));
        }

        $identities = Identities::fromConfiguration($config, $anchor, $this->runner, $this->output);
        $entry = $identities->locate($name);

        if ($entry === null) {
            throw new WorktreeException(
                'no worktree of '.$identities->repoSlug()." is registered as '$name'; "
                ."'worktree create $name' makes one, and 'worktree list' shows the ones there are"
            );
        }

        $this->refuseForeignCheckout($entry, $anchor->mainRoot);

        // The one line of this run, and the whole of it.
        $this->emitter->emit($invocation->has(self::Json) ? $entry->toJson() : $entry->path);

        return ExitCode::Success;
    }

    /**
     * A key is a Compose project name, so an entry claimed by another checkout is
     * another checkout's worktree — and handing its path back would `cd`
     * somebody into a directory this repository does not own. Refused with the
     * words `remove` refuses it in, because it is the same collision.
     */
    private function refuseForeignCheckout(Entry $entry, string $repo): void
    {
        if ($entry->belongsTo($repo)) {
            return;
        }

        throw new WorktreeException(
            "'$entry->key' is registered to $entry->repo, not to ".rtrim($repo, '/').'; '
            .'the worktree it names belongs to that checkout — run this from there, '
            ."or set 'repo_slug' in ".Schema::File.' to tell the two apart'
        );
    }
}
