<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\DeadEntries;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Registry;
use DeskHQ\LaravelWorktree\Runtime\Orphan;
use DeskHQ\LaravelWorktree\Runtime\Orphans;
use DeskHQ\LaravelWorktree\Runtime\Presence;
use DeskHQ\LaravelWorktree\Runtime\Status;

/**
 * What this machine is holding, and what it is holding that nothing claims.
 *
 * ```
 * KEY                    SLOT  APP    VITE   REVERB  DB     REDIS  PATH           STATUS   AGE
 * wt-desk-441-fix-login  0     20000  20001  20002   20003  20004  441-fix-login  running  4h
 * ```
 *
 * The table is the second thing in this package allowed on stdout, and the
 * reason is the same as `create`'s path: it is an answer a script reads. So the
 * orphan warning below goes to **stderr** — a diagnostic printed between the
 * rows would be read as a row, and `worktree list | wc -l` would count it.
 *
 * ## Two renderings
 *
 * That answer is also read by a person, and the two want different tables. A row
 * carrying every field in full — the key, the branch, the absolute path — is
 * about 250 columns wide, which is right for `awk` and hard-wraps in a window;
 * the wrap puts a continuation under no header at all, which is the shape this
 * table most wants to avoid, arrived at from the other direction.
 *
 * So the fields are chosen by who is reading, on {@see Emitter::isInteractive()}:
 * a pipe gets all of them, tab-separated, and a terminal gets the ones that are
 * not already on the line somewhere else ({@see fitted()}). {@see Table} does
 * the rendering; what is elided is decided here, because it turns on what these
 * particular columns mean rather than on how wide they are.
 *
 * `--json` is neither, and is unchanged by both: a script that asked for
 * structure gets structure whatever its stdout is attached to.
 *
 * ## Two scopes
 *
 * The registry is machine-global, because host ports are; a listing is not, and
 * defaults to the checkout it was run from. `--all` widens it to the machine,
 * which is the fastest way to answer "what is holding port 20012?" — that
 * question has no answer inside one repository, because the port it asks about
 * may belong to a clone somebody else's terminal is in.
 *
 * ## What is claimed, and what is actually there
 *
 * Every column above comes out of the registry, which is a faithful report of
 * what has been *claimed* and silent on the questions somebody with five
 * worktrees open has: which of these is up, which is idle eating nothing, did
 * that one ever finish bootstrapping, and how long has that one been sitting
 * there. Two more columns, both on the end where a column added later does not
 * move the fields a script reads by position:
 *
 * - **`STATUS`** ({@see Status}) — one word for what the row is, reconciling
 *   the registry's facts with the daemon's. `gone` is #53's, from the test
 *   {@see DeadEntries} makes and `reap` sweeps by, so the column and the sweep
 *   cannot disagree; the rest are new (#54).
 * - **`AGE`** ({@see Age}) — how long ago the slot was claimed, from the
 *   `created_at` entries have always carried and nothing ever showed.
 *
 * Both are in both renderings and always: the piped one is a contract and a
 * field that comes and goes is not one, and the fitted one no longer has the
 * excuse it had while `STATUS` could only say `ok` — every token it prints now
 * is something a person acts on, so none of them is width spent on nothing.
 *
 * ## One query for the whole table
 *
 * The daemon is asked once per run, for every container on the machine and the
 * state it is in, and that one answer fills the column for every row
 * ({@see Orphans::containers()}) — ten worktrees must not mean ten `docker`
 * invocations. It is literally the query the orphan warning was already making
 * and throwing half of away.
 *
 * A machine with no daemon keeps the whole table: `STATUS` becomes `unknown`,
 * which is neither a failure nor an inferred clean bill of health, on the rule
 * the orphan warning is already under. `gone` and `degraded` are registry facts
 * and survive it.
 *
 * `--json` gets both, as fields rather than as a rendering: `status` beside the
 * entry, and `age_seconds` rather than `4d`, because a script that asked for
 * structure wants the subtraction and not the word for it. The payload is
 * otherwise the entry exactly as `create --json` and `path --json` publish it —
 * a superset rather than a different object, since those two answer about a
 * worktree in hand and neither pays for a daemon query to do it.
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

        // One scan for the whole run: the column below and the warning at the
        // end are the same query, asked once and shared, rather than a second
        // trip to the daemon for a fact it already answered.
        $scan = Orphans::for($registry, $this->runner, $this->output);

        if ($invocation->has(self::Json)) {
            // Emitted even when there is nothing: a script that asked for JSON
            // is parsing this, and an empty array is the answer it can parse.
            $this->emitter->emit(self::json($entries, $scan->containers()));
        } elseif ($entries !== []) {
            $this->tabulate($entries, $config->ports, $scan->containers());
        }

        if ($entries === []) {
            $this->output->line(($everywhere ? 'no worktree on this machine' : "no worktree of $repoSlug")
                ." holds a slot; create one with 'worktree create <slug>'");
        }

        $this->point($entries, $everywhere);
        $this->retry($entries);
        $this->warn($scan, $repoSlug);

        return ExitCode::Success;
    }

    /**
     * The table, one line at a time, on stdout — in whichever of its two
     * renderings the far side of the stream calls for.
     *
     * @param  array<string, Entry>  $entries
     * @param  list<string>  $ports
     * @param  array<string, Presence>|null  $daemon  What the daemon has, or null when there was none to ask.
     */
    private function tabulate(array $entries, array $ports, ?array $daemon): void
    {
        if ($this->emitter->isInteractive()) {
            $this->fitted($entries, $ports, $daemon);

            return;
        }

        [$headers, $rows] = self::table($entries, $ports, $daemon);

        foreach (Table::tsv($headers, $rows) as $line) {
            $this->emitter->emit($line);
        }
    }

    /**
     * The table a person gets: the fields that are not already on the line, in
     * the width there is.
     *
     * Two columns are dropped rather than squeezed, because squeezing them would
     * elide characters to make room for characters that say the same thing:
     *
     * - **`BRANCH`**, when every key ends with its branch. The key is `wt-` plus
     *   the repository plus the slug, so on a branch named by an agent from an
     *   issue title — the case this package exists to serve — the two together
     *   are most of the line and one piece of information. A branch that
     *   slugified differently, `feat/checkout` against `feat-checkout`, is not
     *   implied by anything and keeps its column.
     * - **the leading directories of `PATH`**, which every row of a repository
     *   shares. The root is named once above the table instead, where it is read
     *   once rather than counted past on every row.
     *
     * @param  array<string, Entry>  $entries
     * @param  list<string>  $ports
     * @param  array<string, Presence>|null  $daemon
     */
    private function fitted(array $entries, array $ports, ?array $daemon): void
    {
        $style = Style::forStream($this->emitter->isInteractive());
        $root = self::sharedRoot($entries);

        [$headers, $rows] = self::table(
            $entries,
            $ports,
            $daemon,
            ! self::impliesItsBranches($entries),
            $root,
        );

        // Whole, and never elided to fit: it is the one line here that is prose
        // rather than a row, and a path with its middle taken out is a path
        // nobody can retype. It has no columns to shear, so a narrow window
        // wrapping it costs nothing the rows would have paid.
        if ($root !== null) {
            $this->emitter->emit($style->dim('paths under '.$root));
        }

        foreach (Table::fitted($headers, $rows, (new Terminal($this->runner))->width(), $style) as $line) {
            $this->emitter->emit($line);
        }
    }

    /**
     * The headers and the rows, as both renderings build them.
     *
     * The port columns are the configured ones in their configured order, so a
     * repository that publishes a `meilisearch` port sees it here without this
     * command knowing the name — and every entry carries exactly those, because
     * the registry completes them against this configuration as it reads them.
     *
     * @param  array<string, Entry>  $entries
     * @param  list<string>  $ports
     * @param  array<string, Presence>|null  $daemon
     * @param  bool  $branches  Whether a `BRANCH` column is carried at all.
     * @param  string|null  $root  The directory paths are shown relative to, or null for absolute ones.
     * @return array{list<string>, list<list<string>>}
     */
    private static function table(
        array $entries,
        array $ports,
        ?array $daemon,
        bool $branches = true,
        ?string $root = null,
    ): array {
        $headers = [
            'KEY',
            'SLOT',
            ...array_map(strtoupper(...), $ports),
            ...($branches ? ['BRANCH'] : []),
            'PATH',
            'STATUS',
            'AGE',
        ];

        $rows = [];

        foreach ($entries as $entry) {
            $published = [];

            foreach ($ports as $name) {
                $published[] = (string) ($entry->ports[$name] ?? '-');
            }

            $rows[] = [
                $entry->key,
                (string) $entry->slot,
                ...$published,
                ...($branches ? [$entry->branch] : []),
                self::under($entry->path, $root),
                Status::of($entry, $daemon)->value,
                Age::describe(Age::seconds($entry->createdAt)),
            ];
        }

        return [$headers, $rows];
    }

    /**
     * Whether every key already ends with the branch it is checked out on, which
     * is what makes a `BRANCH` column a second copy of one.
     *
     * All of them or none: a column is per table, and blanking the redundant
     * cells of one would cost the reader more than the width it saved.
     *
     * @param  array<string, Entry>  $entries
     */
    private static function impliesItsBranches(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (! str_ends_with($entry->key, '-'.$entry->branch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The deepest directory every entry is under, or null when there is nothing
     * worth naming.
     *
     * Usually the repository's own worktrees directory, since that is where all
     * of them live; under `--all` it is wherever the checkouts on this machine
     * happen to converge, which may be nowhere — two repositories on different
     * volumes share only `/`, and a table announcing that has said nothing.
     *
     * @param  array<string, Entry>  $entries
     */
    private static function sharedRoot(array $entries): ?string
    {
        $root = null;

        foreach ($entries as $entry) {
            $directory = dirname($entry->path);
            $root = $root === null ? $directory : self::sharedPrefix($root, $directory);
        }

        return $root === null || $root === '' || $root === '/' || $root === '.' ? null : $root;
    }

    /**
     * The directories $left and $right have in common, compared segment by
     * segment: `/srv/desk-worktrees` and `/srv/desk-work` share `/srv`, not
     * `/srv/desk-work`.
     */
    private static function sharedPrefix(string $left, string $right): string
    {
        $shared = [];
        $against = explode('/', $right);

        foreach (explode('/', $left) as $depth => $segment) {
            if (($against[$depth] ?? null) !== $segment) {
                break;
            }

            $shared[] = $segment;
        }

        return implode('/', $shared);
    }

    /**
     * $path as its tail below $root, or whole when it is not under one.
     */
    private static function under(string $path, ?string $root): string
    {
        if ($root === null || ! str_starts_with($path, $root.'/')) {
            return $path;
        }

        return substr($path, strlen($root) + 1);
    }

    /**
     * What to do about the rows marked `gone`, on stderr.
     *
     * The column says which; this says what it costs and what reclaims it,
     * because a marker nobody knows the remedy for is read as decoration. Named
     * rather than listed: their paths are already on the rows above, and a
     * second copy between the table and the orphan warning would be the same
     * fifty lines twice.
     *
     * @param  array<string, Entry>  $entries
     */
    private function point(array $entries, bool $everywhere): void
    {
        $dead = array_keys(array_filter($entries, DeadEntries::isDead(...)));

        if ($dead === []) {
            return;
        }

        $sweep = "'worktree reap".($everywhere ? ' --all' : '')."'";

        $this->output->line(count($dead) === 1
            ? $dead[0]." holds a slot whose worktree directory is gone; $sweep reclaims it"
            : count($dead).' entries hold slots whose worktree directories are gone: '
              .implode(', ', $dead)."; $sweep reclaims them");
    }

    /**
     * What to do about the rows marked `degraded`, on stderr.
     *
     * The same discipline as {@see point()}: a marker whose remedy is not named
     * anywhere near it is read as decoration. The remedy is re-entry — running
     * `create` on the worktree again retries exactly the steps that degraded and
     * skips the ones that worked — and it is said with the slug rather than the
     * key, because the slug is what that command takes.
     *
     * @param  array<string, Entry>  $entries
     */
    private function retry(array $entries): void
    {
        $degraded = array_filter($entries, fn (Entry $entry): bool => $entry->degraded !== [] && ! DeadEntries::isDead($entry));

        if ($degraded === []) {
            return;
        }

        $slugs = array_map(fn (Entry $entry): string => $entry->slug, array_values($degraded));

        $this->output->line(count($slugs) === 1
            ? $slugs[0]." has bootstrap steps that did not finish; 'worktree create ".$slugs[0]."' retries them"
            : count($slugs).' worktrees have bootstrap steps that did not finish: '.implode(', ', $slugs)
              ."; 'worktree create <slug>' retries them one at a time");
    }

    /**
     * The projects on this machine that nothing claims, on stderr.
     *
     * Scoped exactly as the table above it was, $repoSlug being null for the
     * run that asked about the whole machine, and answered from the scan the
     * `STATUS` column was built from rather than from a second one.
     *
     * Silent when there is nothing to say, which includes the machine that
     * could not be asked: a warning is worth printing, a clean bill of health
     * inferred from an unanswered daemon is not.
     */
    private function warn(Orphans $scan, ?string $repoSlug): void
    {
        $orphans = $scan->under(Orphans::prefix($repoSlug));

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
     * The entries as one line of JSON, each carrying what the table's two
     * derived columns say about it.
     *
     * A superset of what `create --json` and `path --json` publish, and the same
     * object underneath: the fields go on the end, `status` as the token the
     * column prints and `age_seconds` as the subtraction rather than the word
     * for it — a script that asked for structure can render `4d` itself and
     * cannot un-render it.
     *
     * @param  array<string, Entry>  $entries
     * @param  array<string, Presence>|null  $daemon
     */
    private static function json(array $entries, ?array $daemon): string
    {
        $payload = json_encode(
            array_values(array_map(fn (Entry $entry): array => $entry->toPayload() + [
                'status' => Status::of($entry, $daemon)->value,
                'age_seconds' => Age::seconds($entry->createdAt),
            ], $entries)),
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
