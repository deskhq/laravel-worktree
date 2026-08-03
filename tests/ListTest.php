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
        'wt-desk-feat-search' => slotEntry(3, 'feat-search', branch: 'feat/search', createdAt: claimedAgo(3 * 86400)),
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo(4 * 3600)),
    ]);

    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        // Nothing between the fields but the tab that separates them: the
        // alignment `column -t` used to lay over this is a courtesy to a reader
        // who is not on the far side of a pipe.
        ->and(rowsOf($process))->toBe([
            "KEY\tSLOT\tAPP\tVITE\tREVERB\tDB\tREDIS\tBRANCH\tPATH\tSTATUS\tAGE",
            "wt-desk-441-fix-login\t0\t20000\t20001\t20002\t20003\t20004\t441-fix-login\t".$this->root.'/desk-worktrees/441-fix-login'."\tunbooted\t4h",
            "wt-desk-feat-search\t3\t20030\t20031\t20032\t20033\t20034\tfeat/search\t".$this->root.'/desk-worktrees/feat-search'."\tunbooted\t3d",
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
        'wt-desk-feat-search' => slotEntry(3, 'feat-search', createdAt: claimedAgo(90)),
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo(90)),
    ]);

    expect(fieldsFromAwk(worktreeList(), 2))->toBe(['SLOT', '0', '3'])
        ->and(fieldsFromAwk(worktreeList(), 9))->toBe([
            'PATH',
            $this->root.'/desk-worktrees/441-fix-login',
            $this->root.'/desk-worktrees/feat-search',
        ])
        // On the end, in the order they were added, where a column added later
        // does not move the ones a script is already reading by position.
        ->and(fieldsFromAwk(worktreeList(), 10))->toBe(['STATUS', 'unbooted', 'unbooted'])
        ->and(fieldsFromAwk(worktreeList(), 11))->toBe(['AGE', '1m', '1m']);
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
        ->and(explode("\t", $rows[1]))->toHaveCount(11)
        ->and(explode("\t", $rows[1])[8])->toBe($this->root.'/desk-worktrees/'.$slug);
});

it('takes its port columns from the configuration rather than from a list of its own', function () {
    mkdir($this->main.'/config', 0755, true);
    file_put_contents(
        $this->main.'/config/worktree.php',
        '<?php return '.var_export(['ports' => ['app', 'meilisearch']], true).";\n",
    );

    registryHolds(['wt-desk-441-fix-login' => slotEntry(1, '441-fix-login', ports: ['app' => 20010, 'meilisearch' => 20011])]);

    expect(columnsOf(worktreeList())[0])->toBe(['KEY', 'SLOT', 'APP', 'MEILISEARCH', 'BRANCH', 'PATH', 'STATUS', 'AGE']);
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
    $claimed = claimedAgo(7200);

    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: $claimed)]);

    $process = worktreeList(['--json']);
    $listed = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    expect($process)->toHaveSucceeded()
        ->and(substr_count($process->getOutput(), "\n"))->toBe(1)
        ->and($listed)->toHaveCount(1)
        ->and($listed[0])->toMatchArray([
            'project' => 'wt-desk-441-fix-login',
            'slot' => 0,
            'repo' => $this->main,
            'slug' => '441-fix-login',
            'branch' => '441-fix-login',
            'path' => $this->root.'/desk-worktrees/441-fix-login',
            'ports' => ['app' => 20000, 'vite' => 20001, 'reverb' => 20002, 'db' => 20003, 'redis' => 20004],
            'created_at' => $claimed,
            'degraded' => [],
            // The token the table prints, as a field rather than a rendering.
            'status' => 'unbooted',
        ])
        // The whole object, so a field added without being thought about is a
        // failing test rather than a silent change to what `--json` publishes:
        // everything `create --json` and `path --json` carry, and the two the
        // table derives, on the end.
        ->and(array_keys($listed[0]))->toBe([
            'project', 'slot', 'repo', 'slug', 'branch', 'path', 'ports', 'created_at', 'degraded',
            'status', 'age_seconds',
        ])
        // Seconds rather than `2h`: a script asked for the subtraction, and can
        // render the word from it. Bounded rather than exact, because the run
        // happens a moment after the timestamp was written.
        ->and($listed[0]['age_seconds'])->toBeGreaterThanOrEqual(7200)->toBeLessThan(7260);

    registryHolds([]);

    // A script that asked for JSON is parsing this, and an empty array is an
    // answer it can parse; the diagnostic still goes where diagnostics go.
    expect(trim(worktreeList(['--json'])->getOutput()))->toBe('[]');
});

/**
 * The mirror image of the orphan warning, and the half nothing reported before
 * (#53): a slot and a port block claimed by an entry whose directory somebody
 * deleted. It rendered identically to a healthy row, which is what made "which
 * of these fifty is real?" unanswerable.
 */
it('marks an entry whose worktree directory is gone, and names the sweep for it', function () {
    // With no daemon anywhere: an entry and a directory is the whole of the
    // test, which is what keeps this half of the answer available on a machine
    // where the orphan warning cannot be produced at all.
    $this->docker = fakeDockerBinary($this->root, daemon: false);

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-search' => slotEntry(3, 'feat-search'),
    ], gone: ['wt-desk-feat-search']);

    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        ->and(columnsOf($process)[0])->toContain('STATUS')
        // `gone` outranks what the daemon would have said, and is reached
        // without asking it: a slot with no worktree behind it is the fact
        // worth acting on, whatever containers it left on the machine.
        ->and(statusesListed($process))->toBe(['unknown', 'gone'])
        ->and($process->getErrorOutput())
        ->toContain("wt-desk-feat-search holds a slot whose worktree directory is gone; 'worktree reap' reclaims it");
});

/**
 * The rest of #53's column, which was `ok` for everything that was not `gone` —
 * a word that said only *nothing to report*, and left the questions in front of
 * somebody with five worktrees open ("which of these is up? which is idle
 * eating nothing?") to `docker ps` and reading project names by eye (#54).
 */
it('says what each row has on the daemon behind it', function () {
    $this->docker = fakeDockerBinary($this->root, projects: [
        'wt-desk-441-fix-login' => ['containers' => 4],
        'wt-desk-feat-search' => ['containers' => 4, 'running' => 1],
        'wt-desk-feat-checkout' => ['containers' => 2, 'running' => 0],
        // And a fourth with nothing on the daemon at all, declared only in the
        // registry below.
    ]);

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-search' => slotEntry(1, 'feat-search'),
        'wt-desk-feat-checkout' => slotEntry(2, 'feat-checkout'),
        'wt-desk-chore-deps' => slotEntry(3, 'chore-deps'),
    ]);

    expect(statusesListed(worktreeList()))->toBe(['running', 'partial', 'stopped', 'unbooted']);
});

/**
 * The interesting rows are the ones where the registry and the daemon disagree,
 * and one of those is an entry that recorded a bootstrap step it never
 * finished. It is a registry fact, so it survives a daemon nobody can reach,
 * and it outranks whatever the containers are doing: what wants acting on there
 * is the step, not the uptime.
 */
it('marks an entry whose bootstrap did not finish, and names the retry', function () {
    $this->docker = fakeDockerBinary($this->root, projects: ['wt-desk-441-fix-login' => ['containers' => 3]]);

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', degraded: ['Installing dependencies']),
        'wt-desk-feat-search' => slotEntry(1, 'feat-search'),
    ]);

    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        ->and(statusesListed($process))->toBe(['degraded', 'unbooted'])
        ->and($process->getErrorOutput())
        ->toContain("441-fix-login has bootstrap steps that did not finish; 'worktree create 441-fix-login' retries them");
});

/**
 * The piped rendering is a contract, and a field that comes and goes is not
 * one — and the fitted one no longer has the excuse it had while `STATUS` could
 * only say `ok`: every token it prints now is something a person acts on.
 */
it('carries both derived columns into both renderings, whatever the rows say', function () {
    $this->docker = fakeDockerBinary($this->root, projects: ['wt-desk-441-fix-login' => ['containers' => 2]]);

    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo(5 * 86400))]);

    expect(columnsOf(worktreeList())[0])->toContain('STATUS')->toContain('AGE')
        ->and(statusesListed(worktreeList()))->toBe(['running'])
        ->and(worktreeList()->getErrorOutput())->not->toContain('reclaims')
        ->and(headerOf(worktreeListInTerminal()))->toContain('STATUS')->toContain('AGE')
        ->and(rowsOf(worktreeListInTerminal())[2])->toEndWith('running  5d');
});

/**
 * `created_at` has been on every entry since the registry existed and nothing
 * ever showed it. One unit, the largest that has whole numbers of itself in the
 * answer, and days all the way out — `152d` and "from March" are the same fact,
 * and `mo` beside `m` in a column scanned rather than read is not.
 */
it('shows how long ago each slot was claimed', function (int $seconds, string $shown) {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo($seconds))]);

    expect(agesListed(worktreeList()))->toBe([$shown]);
})->with([
    'minutes' => [45 * 60, '45m'],
    'hours' => [5 * 3600, '5h'],
    'days' => [152 * 86400, '152d'],
]);

it('measures a worktree made moments ago in seconds', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo(5))]);

    // How many of them is a race with the run's own startup, and not the point:
    // the point is that the smallest unit is the second rather than a `0m` that
    // reads as no answer.
    expect(agesListed(worktreeList())[0])->toMatch('/\A\d{1,2}s\z/');
});

it('says nothing rather than guessing about an entry whose timestamp it cannot read', function () {
    $entry = slotEntry(0, '441-fix-login');
    $entry['created_at'] = 'whenever';

    registryHolds(['wt-desk-441-fix-login' => $entry]);

    // Not `0s`: an unreadable timestamp is not a worktree made a moment ago,
    // and rendering it as one puts the newest-looking row in the table on the
    // oldest entry in it.
    expect(agesListed(worktreeList()))->toBe(['-']);
});

/**
 * The data was in hand and being thrown away: `list` already queried the daemon
 * for the orphan warning, over the same `wt-` projects the table is listing. Ten
 * worktrees must not mean ten `docker` invocations.
 */
it('fills the whole column with the one query the orphan scan already made', function () {
    $this->docker = fakeDockerBinary($this->root, projects: [
        'wt-desk-441-fix-login' => ['containers' => 2],
        'wt-desk-feat-search' => ['containers' => 2],
        'wt-desk-chore-deps' => ['containers' => 2, 'volumes' => 1],
    ]);

    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-search' => slotEntry(1, 'feat-search'),
    ]);

    $process = worktreeList();

    $census = array_filter(dockerCalls(), fn (string $call): bool => str_starts_with($call, 'ps -a --filter'));

    expect(statusesListed($process))->toBe(['running', 'running'])
        // One census for two rows and the warning under them, and no per-row
        // query at all.
        ->and($census)->toHaveCount(1)
        ->and(dockerCalls())->not->toContain('ps -aq --filter label=com.docker.compose.project=wt-desk-441-fix-login')
        ->and($process->getErrorOutput())->toContain('wt-desk-chore-deps');
});

/**
 * `--all` is what reaches an entry another checkout holds, so the remedy it
 * names has to be the run that would actually find it.
 */
it('points at the sweep that can reach the entry it marked', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->shop),
    ], gone: ['wt-shop-feat-checkout']);

    expect(worktreeList()->getErrorOutput())->not->toContain('reclaims')
        ->and(worktreeList(['--all'])->getErrorOutput())
        ->toContain("wt-shop-feat-checkout holds a slot whose worktree directory is gone; 'worktree reap --all' reclaims it");
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

    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo(3 * 86400))]);

    $process = worktreeList();

    expect($process)->toHaveSucceeded()
        ->and(keysListed($process))->toBe(['wt-desk-441-fix-login'])
        // The table is whole, and the column says it could not be asked rather
        // than inferring a clean bill of health from silence.
        ->and(statusesListed($process))->toBe(['unknown'])
        ->and(agesListed($process))->toBe(['3d'])
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
        'wt-desk-feat-search' => slotEntry(3, 'feat-search', createdAt: claimedAgo(3 * 86400)),
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo(4 * 3600)),
    ]);

    $process = worktreeListInTerminal();

    expect($process)->toHaveSucceeded()
        ->and(rowsOf($process))->toBe([
            'paths under '.$this->root.'/desk-worktrees',
            'KEY                    SLOT  APP    VITE   REVERB  DB     REDIS  PATH           STATUS    AGE',
            'wt-desk-441-fix-login  0     20000  20001  20002   20003  20004  441-fix-login  unbooted  4h',
            'wt-desk-feat-search    3     20030  20031  20032   20033  20034  feat-search    unbooted  3d',
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

    // A pull request's worktree is the case the key alone cannot answer: it is
    // named `441-fix-login` like any numeric slug and checked out on the head
    // branch, `fix-login`, so the key ends with the branch while implying a
    // different one (#59).
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', branch: 'fix-login')]);

    $reviewing = worktreeListInTerminal();

    expect(headerOf($reviewing))->toContain('BRANCH')
        ->and(rowsOf($reviewing)[2])->toContain('fix-login');
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
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: claimedAgo(3 * 86400)),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->shop, createdAt: claimedAgo(3 * 86400)),
    ]);

    // Wide enough for both paths in full: what this case is about is which root
    // they are measured against, and a window that elides them is the other
    // rendering rule being exercised by accident.
    $rows = rowsOf(worktreeListInTerminal(['--all'], columns: 120));

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

    $claimed = claimedAgo(3 * 86400);

    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', createdAt: $claimed)]);

    $process = worktreeListInTerminal(['--json']);

    expect($process)->toHaveSucceeded()
        // Structure was asked for, so structure is what comes back — unfitted,
        // undimmed, and with the absolute path a script came here for.
        ->and(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR)[0])
        ->toMatchArray([
            'project' => 'wt-desk-441-fix-login',
            'slot' => 0,
            'repo' => $this->main,
            'slug' => '441-fix-login',
            'branch' => '441-fix-login',
            'path' => $this->root.'/desk-worktrees/441-fix-login',
            'ports' => ['app' => 20000, 'vite' => 20001, 'reverb' => 20002, 'db' => 20003, 'redis' => 20004],
            'created_at' => $claimed,
            'degraded' => [],
            'status' => 'unbooted',
        ])
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

/**
 * @return list<string> The status of every row, in the order the table gave them.
 */
function statusesListed(Process $process): array
{
    return fieldsListed($process, 'STATUS');
}

/**
 * @return list<string> The age of every row, likewise.
 */
function agesListed(Process $process): array
{
    return fieldsListed($process, 'AGE');
}

/**
 * One named column of every row, found by its header rather than by its
 * position — the position is what a script reads and what the tests about the
 * contract assert on, and a case about what a column *says* should not fail
 * because a column was added beside it.
 *
 * @return list<string>
 */
function fieldsListed(Process $process, string $header): array
{
    $columns = columnsOf($process);
    $field = array_search($header, $columns[0], true);

    expect($field)->not->toBeFalse("the table carried no $header column");

    return array_map(fn (array $row): string => $row[$field], array_slice($columns, 1));
}
