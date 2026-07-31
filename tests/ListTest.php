<?php

use Symfony\Component\Process\Process;

/**
 * `list`, end to end through the real binary: a real repository, a real registry
 * on disk, and the fake `docker` the suite runs against — because the two
 * things this command decides are which entries belong to this checkout and
 * which projects on the daemon nothing claims, and neither needs a daemon to be
 * observable.
 *
 * The stream split is the contract under all of it: the table is stdout, every
 * word about orphans is stderr, and `worktree list | wc -l` counts rows.
 *
 * The table has two renderings, and which one a run gets turns on whether stdout
 * is a terminal — so both are exercised through the binary, the piped one by
 * {@see worktreeList()} and the other by {@see worktreeListInTerminal()}, which
 * gives the run a pty to write into.
 */
beforeEach(function () {
    harness('worktree-list');

    $this->main = $this->root.'/desk';
    $this->shop = $this->root.'/shop';

    mkdir($this->main, 0755, true);

    runGit($this->main, 'init', '--quiet', '--initial-branch=main', '.');
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('prints one tab-separated row per worktree on stdout, in slot order, and nothing else', function () {
    registryHolds([
        'wt-desk-feat-search' => slotEntry(3, 'feat-search', branch: 'feat/search'),
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
    ]);

    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        // Nothing between the fields but the tab that separates them: the
        // alignment `column -t` used to lay over this is a courtesy to a reader
        // who is not on the far side of a pipe.
        ->and(rowsOf($process))->toBe([
            "KEY\tSLOT\tAPP\tVITE\tREVERB\tDB\tREDIS\tBRANCH\tPATH",
            "wt-desk-441-fix-login\t0\t20000\t20001\t20002\t20003\t20004\t441-fix-login\t".$this->root.'/desk-worktrees/441-fix-login',
            "wt-desk-feat-search\t3\t20030\t20031\t20032\t20033\t20034\tfeat/search\t".$this->root.'/desk-worktrees/feat-search',
        ])
        ->and($process->getErrorOutput())->toBe('');
});

/**
 * The documented contract, run rather than described: it did not hold while the
 * tabs were being handed to `column -t`, which pads with spaces and emits none
 * of them — so `awk -F'\t'` saw one field per line on every machine that has
 * `column`, which is all of them.
 */
it('hands awk the fields it promises, one per column', function () {
    registryHolds([
        'wt-desk-feat-search' => slotEntry(3, 'feat-search'),
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
    ]);

    expect(fieldsFromAwk(worktreeList(), 2))->toBe(['SLOT', '0', '3'])
        ->and(fieldsFromAwk(worktreeList(), 9))->toBe([
            'PATH',
            $this->root.'/desk-worktrees/441-fix-login',
            $this->root.'/desk-worktrees/feat-search',
        ]);
});

/**
 * A row far wider than any terminal is left alone on this branch: nothing is
 * elided, and nothing wraps it. util-linux `column` before 2.41 answered a table
 * that did not fit by dropping columns off the end — the `PATH` of a real
 * worktree first — which is why it is no longer asked.
 */
it('keeps every field of a row far wider than a terminal', function () {
    $slug = 'feat-'.str_repeat('deeply-nested-', 4).'branch';

    registryHolds(['wt-desk-'.$slug => slotEntry(0, $slug)]);

    $rows = rowsOf(worktreeList());

    expect($rows)->toHaveCount(2)
        ->and(strlen($rows[1]))->toBeGreaterThan(80)
        ->and(explode("\t", $rows[1]))->toHaveCount(9)
        ->and(explode("\t", $rows[1])[8])->toBe($this->root.'/desk-worktrees/'.$slug);
});

it('takes its port columns from the configuration rather than from a list of its own', function () {
    mkdir($this->main.'/config', 0755, true);
    file_put_contents(
        $this->main.'/config/worktree.php',
        '<?php return '.var_export(['ports' => ['app', 'meilisearch']], true).";\n",
    );

    registryHolds(['wt-desk-441-fix-login' => slotEntry(1, '441-fix-login', ports: ['app' => 20010, 'meilisearch' => 20011])]);

    expect(columnsOf(worktreeList())[0])->toBe(['KEY', 'SLOT', 'APP', 'MEILISEARCH', 'BRANCH', 'PATH']);
});

it('shows this repository by default and the whole machine when asked', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->shop),
    ]);

    expect(keysListed(worktreeList()))->toBe(['wt-desk-441-fix-login'])
        ->and(keysListed(worktreeList(['--all'])))->toBe(['wt-desk-441-fix-login', 'wt-shop-feat-checkout']);
});

it('says that nothing holds a slot rather than printing an empty table', function () {
    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        // Not a header with no rows under it, and not an error: an empty
        // registry is the ordinary state of a repository nobody has started on.
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain("no worktree of desk holds a slot; create one with 'worktree create <slug>'");
});

it('emits the registry entries as one line of JSON, empty registry included', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreeList(['--json']);

    expect($process)->toHaveSucceeded()
        ->and(substr_count($process->getOutput(), "\n"))->toBe(1)
        ->and(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR))->toBe([[
            'project' => 'wt-desk-441-fix-login',
            'slot' => 0,
            'repo' => $this->main,
            'slug' => '441-fix-login',
            'branch' => '441-fix-login',
            'path' => $this->root.'/desk-worktrees/441-fix-login',
            'ports' => ['app' => 20000, 'vite' => 20001, 'reverb' => 20002, 'db' => 20003, 'redis' => 20004],
            'created_at' => '2026-01-01T00:00:00Z',
            'degraded' => [],
        ]]);

    registryHolds([]);

    // A script that asked for JSON is parsing this, and an empty array is an
    // answer it can parse; the diagnostic still goes where diagnostics go.
    expect(trim(worktreeList(['--json'])->getOutput()))->toBe('[]');
});

it('warns about orphaned projects on stderr, keeping stdout to the rows', function () {
    $this->docker = fakeDockerBinary($this->root, projects: [
        'wt-desk-441-fix-login' => ['containers' => 4, 'volumes' => 3],
        'wt-desk-feat-checkout' => ['volumes' => 3],
        'wt-desk-feat-search' => ['containers' => 1],
    ]);

    registryHolds(['wt-desk-feat-search' => slotEntry(0, 'feat-search')]);

    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        // A header and the one worktree that does hold a slot: the warning is
        // nowhere near stdout, so `list | wc -l` still counts rows.
        ->and(rowsOf($process))->toHaveCount(2)
        ->and($process->getErrorOutput())
        ->toContain('2 projects of desk still on this daemon that no worktree claims:')
        ->toContain('wt-desk-441-fix-login  4 containers, 3 volumes')
        ->toContain('wt-desk-feat-checkout  0 containers, 3 volumes')
        ->toContain("'worktree reap' removes them")
        // Registered, so not an orphan, however much of the daemon it is using.
        ->not->toContain('wt-desk-feat-search');
});

it('scopes the warning the way it scopes the table, and never past the wt- marker', function () {
    $this->docker = fakeDockerBinary($this->root, projects: [
        'wt-desk-441-fix-login' => ['volumes' => 1],
        'wt-shop-feat-checkout' => ['volumes' => 1],
        // Somebody else's Compose project, on the same daemon, carrying no
        // marker: out of scope under every flag this command has.
        'app-441' => ['containers' => 2, 'volumes' => 2],
    ]);

    expect(worktreeList()->getErrorOutput())
        ->toContain('1 project of desk')
        ->toContain('wt-desk-441-fix-login')
        ->not->toContain('wt-shop-feat-checkout')
        ->and(worktreeList(['--all'])->getErrorOutput())
        ->toContain('2 projects on this machine')
        ->toContain('wt-shop-feat-checkout')
        ->toContain("'worktree reap --all' removes them")
        ->not->toContain('app-441');
});

it('lists what the registry holds with the Docker daemon stopped, and only loses the warning', function () {
    $this->docker = fakeDockerBinary($this->root, daemon: false, projects: [
        'wt-desk-feat-checkout' => ['volumes' => 3],
    ]);

    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        ->and(keysListed($process))->toBe(['wt-desk-441-fix-login'])
        // Nothing could be asked, so nothing is claimed: an unreachable daemon
        // is not evidence that this machine is clean.
        ->and($process->getErrorOutput())->not->toContain('no worktree claims');
});

/*
|--------------------------------------------------------------------------
| The other rendering
|--------------------------------------------------------------------------
|
| What a person gets, which is the same rows fitted to the window they are being
| read in. Every case below runs the binary against a pty, because that is the
| only thing the command asks before choosing: whether stdout is a terminal.
|
*/

it('aligns the rows, names the worktrees root once, and shows paths under it', function () {
    registryHolds([
        'wt-desk-feat-search' => slotEntry(3, 'feat-search'),
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
    ]);

    $process = worktreeListInTerminal();

    expect($process)->toHaveSucceeded()
        ->and(rowsOf($process))->toBe([
            'paths under '.$this->root.'/desk-worktrees',
            'KEY                    SLOT  APP    VITE   REVERB  DB     REDIS  PATH',
            'wt-desk-441-fix-login  0     20000  20001  20002   20003  20004  441-fix-login',
            'wt-desk-feat-search    3     20030  20031  20032   20033  20034  feat-search',
        ]);
});

/**
 * The key is `wt-` plus the repository plus the slug, so on the branch names
 * this package exists to serve — an agent's, from an issue title — a `BRANCH`
 * column is the same string a second time and most of the line. A branch that
 * slugified differently is not implied by anything, and keeps its column.
 */
it('omits the branch column when every key already ends with its branch', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    expect(headerOf(worktreeListInTerminal()))->not->toContain('BRANCH');

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-search' => slotEntry(3, 'feat-search', branch: 'feat/search'),
    ]);

    $process = worktreeListInTerminal();

    expect(headerOf($process))->toContain('BRANCH')
        ->and(rowsOf($process)[3])->toContain('feat/search');
});

/**
 * The shape this rendering exists to prevent: a row that does not fit wrapping
 * into a continuation line sitting under no header at all. It is elided
 * instead — a person can widen the window, or reach for `--json`.
 */
it('truncates rather than wraps a table wider than the terminal', function () {
    $slug = 'feat-'.str_repeat('deeply-nested-', 4).'branch';

    registryHolds(['wt-desk-'.$slug => slotEntry(0, $slug)]);

    $rows = rowsOf(worktreeListInTerminal(columns: 60));

    // The root, the header, and one worktree. Nothing under the row.
    expect($rows)->toHaveCount(3)
        ->and(array_map(visibleWidth(...), array_slice($rows, 1)))->each->toBeLessThanOrEqual(60)
        ->and($rows[2])->toStartWith('wt-desk-fea')->toEndWith('…')
        // The key was squeezed to make room, and the ports it was squeezed for
        // are all still there and all still legible.
        ->and($rows[1])->toContain('REVERB')
        ->and($rows[2])->toContain('20002');
});

it('says which worktrees root it is measuring paths against even when there is one row', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    expect(rowsOf(worktreeListInTerminal())[0])->toBe('paths under '.$this->root.'/desk-worktrees');
});

/**
 * `--all` spans checkouts, and two repositories converge wherever they happen
 * to: here on the fixture root, one level above either worktrees directory.
 */
it('names the deepest root every listed worktree is under, across repositories', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->shop),
    ]);

    $rows = rowsOf(worktreeListInTerminal(['--all']));

    expect($rows[0])->toBe('paths under '.$this->root)
        ->and($rows[2])->toContain('desk-worktrees/441-fix-login')
        ->and($rows[3])->toContain('shop-worktrees/feat-checkout');
});

it('dims the header, and writes no escape sequence at all when anything says not to', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    expect(rowsOf(worktreeListInTerminal(colour: true))[1])->toStartWith("\e[2m")->toEndWith("\e[0m")
        ->and(worktreeListInTerminal(colour: true, env: ['NO_COLOR' => '1'])->getOutput())->not->toContain("\e")
        // Presence rather than value: NO_COLOR is not a setting to be parsed.
        ->and(worktreeListInTerminal(colour: true, env: ['NO_COLOR' => 'false'])->getOutput())->not->toContain("\e")
        ->and(worktreeListInTerminal(colour: true, env: ['TERM' => 'dumb'])->getOutput())->not->toContain("\e")
        // And a pipe never gets one, whatever the environment says.
        ->and(worktreeList()->getOutput())->not->toContain("\e");
});

it('keeps --json and the orphan warning exactly as they are, terminal or not', function () {
    $this->docker = fakeDockerBinary($this->root, projects: ['wt-desk-feat-checkout' => ['volumes' => 3]]);

    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $process = worktreeListInTerminal(['--json']);

    expect($process)->toHaveSucceeded()
        // Structure was asked for, so structure is what comes back — unfitted,
        // undimmed, and with the absolute path a script came here for.
        ->and(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR))
        ->toBe([[
            'project' => 'wt-desk-441-fix-login',
            'slot' => 0,
            'repo' => $this->main,
            'slug' => '441-fix-login',
            'branch' => '441-fix-login',
            'path' => $this->root.'/desk-worktrees/441-fix-login',
            'ports' => ['app' => 20000, 'vite' => 20001, 'reverb' => 20002, 'db' => 20003, 'redis' => 20004],
            'created_at' => '2026-01-01T00:00:00Z',
            'degraded' => [],
        ]])
        // Still stderr, still the same words, still nowhere near the rows.
        ->and($process->getErrorOutput())->toContain('1 project of desk still on this daemon that no worktree claims:')
        ->and(rowsOf(worktreeListInTerminal()))->toHaveCount(3);
});

it('treats being called wrong as a usage error, not a failed run', function (array $arguments, string $said) {
    $process = worktreeList($arguments);

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())
        ->toContain($said)
        ->toContain('usage: worktree list [--all] [--json]');
})->with([
    'an argument it has no use for' => [['441'], 'list takes no arguments, only options; given 441'],
    'an option it does not have' => [['--everything'], "unknown option '--everything'; this command takes --all, --json"],
]);

/**
 * @return list<string> Everything the run put on stdout, line by line.
 *
 * The carriage returns are the terminal driver's, not the table's: a pty
 * translates every newline written to it into a CRLF, and no assertion here is
 * about that.
 */
function rowsOf(Process $process): array
{
    $lines = array_map(fn (string $line): string => rtrim($line, "\r"), explode("\n", $process->getOutput()));

    return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
}

/**
 * The header row as it reads, escape sequences and all.
 */
function headerOf(Process $process): string
{
    return rowsOf($process)[1];
}

/**
 * How wide a line is on screen, which is not how many bytes it carries: the
 * escapes that dim it occupy none of the window.
 */
function visibleWidth(string $line): int
{
    $visible = (string) preg_replace('/\e\[[0-9;]*m/', '', $line);

    return (int) preg_match_all('/./us', $visible);
}

/**
 * One field of every row, read the way the README says it can be.
 *
 * Through the real `awk`, because the promise is about `awk` and not about a
 * regex that resembles it — the previous rendering satisfied every test here
 * while `awk -F'\t'` saw one field per line.
 *
 * @return list<string>
 */
function fieldsFromAwk(Process $process, int $field): array
{
    $awk = new Process(['awk', '-F', "\t", '{print $'.$field.'}']);
    $awk->setInput($process->getOutput());
    $awk->mustRun();

    return array_values(array_filter(
        explode("\n", $awk->getOutput()),
        fn (string $line): bool => $line !== '',
    ));
}

/**
 * The table as fields, so a case asserts on what a row says rather than on how
 * wide `column` made it.
 *
 * @return list<list<string>>
 */
function columnsOf(Process $process): array
{
    return array_map(
        fn (string $row): array => preg_split('/\t|\s{2,}/', $row) ?: [],
        rowsOf($process),
    );
}

/**
 * @return list<string> The keys the table listed, header excluded.
 */
function keysListed(Process $process): array
{
    return array_map(fn (array $columns): string => $columns[0], array_slice(columnsOf($process), 1));
}
