<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Registry;
use DeskHQ\LaravelWorktree\Runtime\Orphan;
use DeskHQ\LaravelWorktree\Runtime\Orphans;

/**
 * What this machine is holding, and what it is holding that nothing claims.
 *
 * ```
 * KEY                    SLOT  APP    VITE   REVERB  DB     REDIS  BRANCH         PATH
 * wt-desk-441-fix-login  0     20000  20001  20002   20003  20004  441-fix-login  /Users/…
 * ```
 *
 * The table is the second thing in this package allowed on stdout, and the
 * reason is the same as `create`'s path: it is an answer a script reads. So the
 * orphan warning below goes to **stderr** — a diagnostic printed between the
 * rows would be read as a row, and `worktree list | wc -l` would count it.
 *
 * ## Two scopes
 *
 * The registry is machine-global, because host ports are; a listing is not, and
 * defaults to the checkout it was run from. `--all` widens it to the machine,
 * which is the fastest way to answer "what is holding port 20012?" — that
 * question has no answer inside one repository, because the port it asks about
 * may belong to a clone somebody else's terminal is in.
 *
 * ## The warning is the point
 *
 * A worktree torn down by hand, a teardown interrupted partway, or a registry
 * that was lost, all leave the same thing behind: containers and volumes under
 * a `wt-` project name that no entry claims. Naming them here is what makes
 * `reap` discoverable at the moment it is relevant rather than after the disk
 * fills, and the scan is {@see Orphans} — literally the one `reap` destroys by,
 * because a warning about something `reap` would not touch teaches people to
 * ignore the warning.
 *
 * Nothing here needs Docker to work. The daemon is asked once, and a machine
 * with none loses the warning and keeps the table.
 */
final readonly class ListCommand implements Command
{
    /** Show every worktree on the machine, not just this repository's. */
    public const string All = 'all';

    /** Emit the registry entries instead of the table. */
    public const string Json = 'json';

    public function __construct(
        private Output $output,
        private Emitter $emitter,
        private ProcessRunner $runner,
    ) {}

    public function name(): string
    {
        return 'list';
    }

    public function usage(): array
    {
        return [
            '[--all] [--json]',
            'Show the worktrees this repository has, with their slots and ports.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        $invocation = Arguments::parse($arguments, [self::All, self::Json]);

        if ($invocation->at(0) !== null) {
            throw new UsageException(
                'list takes no arguments, only options; given '.implode(' ', $invocation->positional)
            );
        }

        $everywhere = $invocation->has(self::All);
        $registry = Registry::fromConfiguration($config);
        $entries = $everywhere ? $registry->all() : $registry->forRepo($anchor->mainRoot);

        // The repository's own name, needed by both halves of a scoped run —
        // and asked for only then, because deriving it can fail on a checkout
        // whose directory name slugifies to nothing, and `--all` has no use for
        // it at all.
        $repoSlug = $everywhere ? null : $this->repoSlug($anchor, $config);

        if ($invocation->has(self::Json)) {
            // Emitted even when there is nothing: a script that asked for JSON
            // is parsing this, and an empty array is the answer it can parse.
            $this->emitter->emit(self::json($entries));
        } elseif ($entries !== []) {
            $this->tabulate($entries, $config->ports);
        }

        if ($entries === []) {
            $this->output->line(($everywhere ? 'no worktree on this machine' : "no worktree of $repoSlug")
                ." holds a slot; create one with 'worktree create <slug>'");
        }

        $this->warn($registry, $repoSlug);

        return ExitCode::Success;
    }

    /**
     * The table, one line at a time, on stdout.
     *
     * The port columns are the configured ones in their configured order, so a
     * repository that publishes a `meilisearch` port sees it here without this
     * command knowing the name — and every entry carries exactly those, because
     * the registry completes them against this configuration as it reads them.
     *
     * @param  array<string, Entry>  $entries
     * @param  list<string>  $ports
     */
    private function tabulate(array $entries, array $ports): void
    {
        $headers = ['KEY', 'SLOT', ...array_map(strtoupper(...), $ports), 'BRANCH', 'PATH'];
        $rows = [];

        foreach ($entries as $entry) {
            $published = [];

            foreach ($ports as $name) {
                $published[] = (string) ($entry->ports[$name] ?? '-');
            }

            $rows[] = [$entry->key, (string) $entry->slot, ...$published, $entry->branch, $entry->path];
        }

        foreach ((new Table($this->runner))->render($headers, $rows) as $line) {
            $this->emitter->emit($line);
        }
    }

    /**
     * The projects on this machine that nothing claims, on stderr.
     *
     * Scoped exactly as the table above it was, $repoSlug being null for the
     * run that asked about the whole machine.
     *
     * Silent when there is nothing to say, which includes the machine that
     * could not be asked: a warning is worth printing, a clean bill of health
     * inferred from an unanswered daemon is not.
     */
    private function warn(Registry $registry, ?string $repoSlug): void
    {
        $orphans = Orphans::for($registry, $this->runner, $this->output)->under(Orphans::prefix($repoSlug));

        if ($orphans === []) {
            return;
        }

        $everywhere = $repoSlug === null;

        $this->output->line(count($orphans).' '.(count($orphans) === 1 ? 'project' : 'projects')
            .($everywhere ? ' on this machine' : " of $repoSlug")
            .' still on this daemon that no worktree claims:');

        // The lines `reap` puts in its manifest, built where it builds them: a
        // warning that reads differently from the confirmation it sends you to
        // is one somebody has to reconcile by eye before answering.
        foreach (Orphan::manifest($orphans) as $line) {
            $this->output->line($line);
        }

        $this->output->line("'worktree reap".($everywhere ? ' --all' : '')."' removes them");
    }

    /**
     * @param  array<string, Entry>  $entries
     */
    private static function json(array $entries): string
    {
        $payload = json_encode(
            array_values(array_map(fn (Entry $entry): array => $entry->toPayload(), $entries)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($payload === false) {
            throw new WorktreeException('the registry entries could not be encoded: '.json_last_error_msg());
        }

        return $payload;
    }

    private function repoSlug(Anchor $anchor, Configuration $config): string
    {
        return Identities::fromConfiguration($config, $anchor, $this->runner, $this->output)->repoSlug();
    }
}
