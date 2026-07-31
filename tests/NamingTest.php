<?php

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Naming\Slug;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Registry;

/**
 * Naming: what one typed argument turns into, and the two things that must
 * never come out of it — a project name Docker would reject, and a name that
 * quietly means somebody else's worktree.
 *
 * The `gh` lookup is exercised against a stub on `PATH` rather than against the
 * real one, so the developer's login, network and issue tracker do not decide
 * what a test asserts. Every case restores `PATH` afterwards.
 */
beforeEach(function () {
    $this->root = temporaryDirectory('worktree-naming');
    $this->repo = $this->root.'/the-desk';
    $this->home = $this->root.'/home';
    $this->path = (string) getenv('PATH');
    $this->diagnostics = fopen('php://memory', 'w+');

    mkdir($this->repo, 0755, true);
    runGit($this->repo, 'init', '--quiet', '--initial-branch=main', '.');
});

afterEach(function () {
    putenv('PATH='.$this->path);
    fclose($this->diagnostics);
    deleteDirectory($this->root);
});

it('names a numeric argument from the issue title gh reports', function () {
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);
    stubGh($this->root, 'echo \'{"title":"Fix login"}\'');

    $identity = $identities->identify('441');

    expect($identity->name)->toBe('441')
        ->and($identity->slug)->toBe('441-fix-login')
        ->and($identity->key)->toBe('wt-the-desk-441-fix-login')
        ->and($identity->branch)->toBe('441-fix-login')
        ->and($identity->path)->toBe($this->root.'/the-desk-worktrees/441-fix-login')
        ->and(ghLog($this->root))->toContain('issue view 441 --json title');
});

it('uses a named argument verbatim, and looks nothing up', function () {
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);
    stubGh($this->root, 'echo \'{"title":"Fix login"}\'');

    $identity = $identities->identify('feat/checkout');

    // The branch keeps the slash: somebody typing `feat/checkout` means that
    // ref, and slashes are legal there but not in a directory or project name.
    expect($identity->branch)->toBe('feat/checkout')
        ->and($identity->slug)->toBe('feat-checkout')
        ->and($identity->key)->toBe('wt-the-desk-feat-checkout')
        ->and($identity->path)->toBe($this->root.'/the-desk-worktrees/feat-checkout')
        ->and(ghLog($this->root))->toBe('');
});

it('names the worktree issue-<n> whenever gh cannot answer', function (?string $script) {
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);

    $script === null ? withoutGh($this->root) : stubGh($this->root, $script);

    $identity = $identities->identify('441');

    expect($identity->slug)->toBe('issue-441')
        ->and($identity->branch)->toBe('issue-441')
        ->and($identity->key)->toBe('wt-the-desk-issue-441')
        ->and($identity->path)->toBe($this->root.'/the-desk-worktrees/issue-441')
        ->and(diagnosticsIn($this->diagnostics))->toContain('gh could not name issue 441; the worktree will be issue-441');
})->with([
    'absent' => [null],
    'logged out' => ["printf 'gh: To get started with GitHub CLI, please run: gh auth login\n' >&2; exit 4"],
    'offline' => ["printf 'error connecting to api.github.com\n' >&2; exit 1"],
    'pointed at a repository without that issue' => ["printf 'could not find any issue named 441\n' >&2; exit 1"],
    'answering something that is not JSON' => ['echo not-json'],
    'answering an empty title' => ['echo \'{"title":"   "}\''],
    'answering no title at all' => ['echo \'{"number":441}\''],
]);

it('keeps what gh says about itself out of the diagnostics', function () {
    // An optional tool refusing is an answer, not an event: printing its
    // complaint would read as a failure of a run that succeeded.
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);
    stubGh($this->root, "printf 'gh: To get started with GitHub CLI, please run: gh auth login\n' >&2; exit 4");

    $identities->identify('441');

    expect(diagnosticsIn($this->diagnostics))->not->toContain('gh auth login');
});

it('carries the wt- marker whatever the configuration says', function (array $config, string $key) {
    $identity = identitiesIn($this->repo, $this->home, $this->diagnostics, $config)->identify('feat/checkout');

    expect($identity->key)->toBe($key)
        ->and($identity->key)->toStartWith('wt-');
})->with([
    'no repo slug at all' => [[], 'wt-the-desk-feat-checkout'],
    'a configured repo slug' => [['repo_slug' => 'shop'], 'wt-shop-feat-checkout'],
    'a repo slug that looks like the marker' => [['repo_slug' => 'wt'], 'wt-wt-feat-checkout'],
    'a repo slug carrying underscores' => [['repo_slug' => 'the_desk'], 'wt-the_desk-feat-checkout'],
]);

it('puts the worktrees directory beside the checkout, named after the repository', function () {
    $identity = identitiesIn($this->repo, $this->home, $this->diagnostics, ['repo_slug' => 'shop'])->identify('feat/checkout');

    expect($identity->path)->toBe($this->root.'/shop-worktrees/feat-checkout');
});

it('names the repository from the checkout directory when repo_slug says nothing', function () {
    $repo = $this->root.'/The Desk (2)';
    mkdir($repo, 0755, true);
    runGit($repo, 'init', '--quiet', '--initial-branch=main', '.');

    $identity = identitiesIn($repo, $this->home, $this->diagnostics)->identify('feat/checkout');

    expect($identity->key)->toBe('wt-the-desk-2-feat-checkout')
        ->and($identity->path)->toBe($this->root.'/the-desk-2-worktrees/feat-checkout');
});

it('refuses a checkout directory that names nothing, and says which key to set', function () {
    $repo = $this->root.'/%%%';
    mkdir($repo, 0755, true);
    runGit($repo, 'init', '--quiet', '--initial-branch=main', '.');

    expect(fn () => identitiesIn($repo, $this->home, $this->diagnostics)->identify('feat/checkout'))
        ->toThrow(WorktreeException::class, "set 'repo_slug' in config/worktree.php");
});

it('cuts a long slug to 50 characters without leaving it ending in a dash', function (string $argument, string $slug) {
    $identity = identitiesIn($this->repo, $this->home, $this->diagnostics)->identify($argument);

    expect($identity->slug)->toBe($slug)
        ->and(strlen($identity->slug))->toBeLessThanOrEqual(Slug::Length)
        ->and($identity->slug)->not->toEndWith('-');
})->with([
    // The cut lands on the dash this collapsed to, and the dash is stripped again.
    'cut onto a separator' => [str_repeat('a', 49).' the rest', str_repeat('a', 49)],
    'cut mid-word' => [str_repeat('ab', 40), str_repeat('ab', 25)],
    'exactly fifty' => [str_repeat('a', 50), str_repeat('a', 50)],
]);

it('cannot be talked out of the worktrees directory by a name full of separators', function (string $argument, string $slug) {
    $identity = identitiesIn($this->repo, $this->home, $this->diagnostics)->identify($argument);

    expect($identity->slug)->toBe($slug)
        ->and($identity->path)->toBe($this->root.'/the-desk-worktrees/'.$slug)
        ->and($identity->path)->not->toContain('..');
})->with([
    'a relative escape' => ['../../etc/passwd', 'etc-passwd'],
    'an absolute path' => ['/etc/passwd', 'etc-passwd'],
    'a hidden file' => ['.ssh/authorized_keys', 'ssh-authorized-keys'],
]);

it('refuses an argument with nothing in it to name a worktree with', function (string $argument) {
    expect(fn () => identitiesIn($this->repo, $this->home, $this->diagnostics)->identify($argument))
        ->toThrow(WorktreeException::class);
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'separators only' => ['///'],
    'punctuation only' => ['-.-'],
]);

it('refuses a second argument that slugifies onto an existing worktree, naming both', function () {
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);

    register($this->home, $this->repo, $identities->identify('feat/checkout'));

    // `feat-checkout` derives the same key, the same path and the same project
    // as `feat/checkout` — but a different branch, so re-entering it would put
    // this run's commits on somebody else's line of work.
    expect(fn () => $identities->identify('feat-checkout'))
        ->toThrow(
            WorktreeException::class,
            "'feat-checkout' and 'feat/checkout' both name 'wt-the-desk-feat-checkout', but they are different branches"
        );
});

it('re-enters a worktree created under the same argument', function () {
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);

    register($this->home, $this->repo, $identities->identify('feat/checkout'));

    expect($identities->identify('feat/checkout')->key)->toBe('wt-the-desk-feat-checkout');
});

it('leaves a key held by another checkout to the allocator', function () {
    // Two clones sharing a project name is a different failure, with a message
    // of its own — this layer only knows about one repository's names.
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);

    register($this->home, '/checkouts/elsewhere', $identities->identify('feat/checkout'));

    expect($identities->identify('feat-checkout')->key)->toBe('wt-the-desk-feat-checkout');
});

it('derives a valid Compose project name from anything typed at it', function () {
    $identities = identitiesIn($this->repo, $this->home, $this->diagnostics);

    // A numeric argument would otherwise consult the developer's own gh.
    withoutGh($this->root);

    $arguments = [
        '441', '0', 'feat/checkout', 'FEAT/Checkout', '  spaced  out  ', '--leading', 'trailing--',
        'ünïcødé', 'UPPER_snake.case', '.hidden', '../escape', 'emoji 🎉 here', 'release/v1.2.3',
        'wt-already-prefixed', "tabs\tand\nnewlines", str_repeat('long-', 40), 'a'.str_repeat('-', 60).'b',
    ];

    foreach (range(1, 200) as $seed) {
        $arguments[] = awkwardArgument($seed);
    }

    foreach ($arguments as $argument) {
        if (preg_match('/[a-zA-Z0-9]/', $argument) !== 1) {
            expect(fn () => $identities->identify($argument))->toThrow(WorktreeException::class);

            continue;
        }

        $identity = $identities->identify($argument);

        expect(Slug::isProjectName($identity->key))->toBeTrue("'$argument' derived the project name '$identity->key'")
            ->and($identity->key)->toStartWith(Identities::Marker)
            ->and($identity->key)->toBe('wt-the-desk-'.$identity->slug)
            ->and($identity->slug)->not->toEndWith('-')
            ->and(strlen($identity->slug))->toBeLessThanOrEqual(Slug::Length)
            ->and($identity->path)->toBe($this->root.'/the-desk-worktrees/'.$identity->slug);
    }
});

/**
 * The naming layer for a checkout, with a registry of the test's own.
 *
 * @param  resource  $diagnostics
 * @param  array<string, mixed>  $config  As `config/worktree.php` would have returned it.
 */
function identitiesIn(string $repo, string $home, $diagnostics, array $config = []): Identities
{
    $output = new Output($diagnostics);
    $runner = new ProcessRunner($output);

    return Identities::fromConfiguration(
        configurationIn($home, $config),
        Anchor::resolve($runner, $repo),
        $runner,
        $output,
    );
}

/**
 * A stub `gh` at the front of `PATH`, logging what it was asked before it
 * answers, so a test can assert that the lookup did — or did not — happen.
 */
function stubGh(string $root, string $script): void
{
    $bin = $root.'/bin';

    mkdir($bin, 0755, true);
    file_put_contents($bin.'/gh', "#!/bin/sh\necho \"\$@\" >> ".escapeshellarg($root.'/gh.log')."\n".$script."\n");
    chmod($bin.'/gh', 0755);

    putenv('PATH='.$bin.':'.getenv('PATH'));
}

/**
 * A `PATH` with no `gh` on it at all — the machine of somebody who never
 * installed it, which is the case the fallback exists for.
 */
function withoutGh(string $root): void
{
    $empty = $root.'/empty-path';

    mkdir($empty, 0755, true);

    putenv('PATH='.$empty);
}

function ghLog(string $root): string
{
    return is_file($root.'/gh.log') ? (string) file_get_contents($root.'/gh.log') : '';
}

/**
 * The registry as an earlier run would have left it, holding $identity for the
 * checkout at $repo.
 */
function register(string $home, string $repo, Identity $identity): void
{
    Registry::fromConfiguration(configurationIn($home))->put(new Entry(
        $identity->key,
        0,
        $repo,
        $identity->slug,
        $identity->branch,
        $identity->path,
        [],
        gmdate('Y-m-d\TH:i:s\Z'),
    ));
}

/**
 * An argument nobody would type deliberately, but somebody eventually will.
 *
 * Seeded rather than random: a failing case has to be reproducible from the
 * test output alone.
 */
function awkwardArgument(int $seed): string
{
    mt_srand($seed);

    $alphabet = ['a', 'Z', '9', '-', '_', '/', ' ', '.', '#', '..', '--', 'é', '🎉', "\t"];
    $argument = '';

    for ($character = 0, $length = mt_rand(1, 80); $character < $length; $character++) {
        $argument .= $alphabet[mt_rand(0, count($alphabet) - 1)];
    }

    return $argument;
}
