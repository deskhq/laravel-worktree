<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Fleet;
use DeskHQ\LaravelWorktree\Registry\ForeignCheckout;

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
        /** Never used to release a lock, because this takes none; the fleet asks for one. */
        private ShutdownHandler $shutdown,
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
        $invocation = Arguments::parse($arguments, [self::Json], takes: Arity::name($this->name(), 'to look up'));

        // There, and not blank: the arity above is what says so.
        $name = (string) $invocation->at(0);

        // A key is a Compose project name, so an entry claimed by another
        // checkout is another checkout's worktree — and handing its path back
        // would `cd` somebody into a directory this repository does not own.
        // *This* rather than *it*, because what is in the wrong place is the
        // command being typed rather than anything it would have destroyed.
        $entry = Fleet::fromConfiguration($config, $anchor, $this->runner, $this->shutdown, $this->output)->require(
            $name,
            ForeignCheckout::because('the worktree it names belongs to that checkout', 'run this from there'),
            hint: 'create',
        );

        // The one line of this run, and the whole of it.
        $this->emitter->emit($invocation->has(self::Json) ? $entry->toJson() : $entry->path);

        return ExitCode::Success;
    }
}
