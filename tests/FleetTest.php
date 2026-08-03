<?php

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Fleet;
use DeskHQ\LaravelWorktree\Registry\ForeignCheckout;
use DeskHQ\LaravelWorktree\Registry\Verdict;

/**
 * The worktree lifecycle, called rather than spawned (#73).
 *
 * Every one of the rules below used to live inside a command, which meant the
 * only way to exercise it was to build a git repository, run `bin/worktree` as
 * a subprocess and race a second one against it. Those cases still exist and
 * are still right — the stdout contract and the stream discipline are only
 * provable end to end — but *lock ordering*, *the refusal a foreign checkout
 * gets* and *what a sweep does with a key it cannot act on* are not questions
 * about a subprocess, and they cost seconds each to ask that way.
 *
 * So they are asked here, in-process, against a real registry file and real
 * lock directories: a fleet is cheap, and nothing below starts a daemon, a
 * `gh`, or a second PHP.
 */
beforeEach(function () {
    harness('worktree-fleet');

    // A real repository, because the anchor is resolved with git and the
    // checkout's directory name is what the repository is called: `desk` here,
    // so keys read as `wt-desk-…` exactly as they do on a machine.
    $this->main = mainCheckout($this->root.'/desk');
    $this->diagnostics = fopen('php://memory', 'w+');

    // A window this machine has free right now: allocation probes the ports it
    // is about to claim, so a real service on the developer's laptop would
    // otherwise decide which slot the case gets.
    $this->fleet = fleetIn($this->main, $this->home, $this->diagnostics, ['port_base' => freePortBase(100)]);
});

afterEach(function () {
    fclose($this->diagnostics);
    deleteDirectory($this->root);
});

it('hands back the worktree a name holds, and nothing for a name nothing holds', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    expect($this->fleet->locate('441', starting())?->key)->toBe('wt-desk-441-fix-login')
        ->and($this->fleet->locate('441-fix-login', starting())?->key)->toBe('wt-desk-441-fix-login')
        ->and($this->fleet->locate('feat/checkout', starting()))->toBeNull();
});

/**
 * The sentence three commands used to write out. `create` is named for the two
 * that would be worth suggesting it to, and left out for `stop`, where somebody
 * who mistyped the name of a worktree they meant to stop is not asking for a
 * bootstrap.
 */
it('refuses a name nothing holds in one sentence, with the create hint as the parameter', function () {
    expect(fn () => $this->fleet->require('441', starting(), hint: 'create'))
        ->toThrow(
            WorktreeException::class,
            "no worktree of desk is registered as '441'; 'worktree create 441' makes one, "
            ."and 'worktree list' shows the ones there are",
        )
        ->and(fn () => $this->fleet->require('441', starting()))
        ->toThrow(
            WorktreeException::class,
            "no worktree of desk is registered as '441'; 'worktree list' shows the ones there are",
        );
});

/**
 * A key is a Compose project name, so an entry another checkout holds is
 * another checkout's containers. Five commands used to refuse that, in five
 * copies of one sentence — so what is asserted here is the frame around the
 * clause rather than the clause itself.
 */
it('refuses an entry another checkout holds, in one sentence around each command\'s own clause', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login', repo: $this->root.'/shop')]);

    foreach ([
        'starting it from here would bring up that checkout\'s containers',
        'stopping it from here would stop that checkout\'s containers',
        'removing it from here would tear down that checkout\'s containers',
    ] as $clause) {
        expect(fn () => $this->fleet->locate('441', ForeignCheckout::because($clause)))
            ->toThrow(
                WorktreeException::class,
                "'wt-desk-441-fix-login' is registered to ".$this->root.'/shop, not to '.$this->main.'; '
                .$clause.' — run it from there, '
                ."or set 'repo_slug' in config/worktree.php to tell the two apart",
            );
    }

    // `path`'s, which is the one that says *this*: what is in the wrong place
    // is the command being typed, not anything it would have destroyed.
    expect(fn () => $this->fleet->locate('441', ForeignCheckout::because(
        'the worktree it names belongs to that checkout', 'run this from there'
    )))->toThrow(WorktreeException::class, 'that checkout — run this from there, ');
});

it('lets an entry this checkout holds through both ways in', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $entry = $this->fleet->require('441', starting());

    expect($entry->key)->toBe('wt-desk-441-fix-login')
        ->and($this->fleet->verify($entry, starting()))->toBe($entry);
});

/**
 * The re-read three commands used to write separately: the first look at the
 * registry happened before the lock, so it is a snapshot, and acting on it
 * would boot containers a `remove` had just torn down.
 */
it('reads the entry again rather than trusting the one it was given', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $entry = $this->fleet->require('441', starting());

    $this->fleet->claim($entry->key);

    // Something else got in first — which is what the lock is for, and what the
    // re-read is for on the run that was waiting behind it.
    registryHolds([]);

    expect($this->fleet->entry($entry->key))->toBeNull()
        ->and(fn () => $this->fleet->stillHeld($entry))
        ->toThrow(
            WorktreeException::class,
            'nothing in the registry holds wt-desk-441-fix-login any more — something released it while this run '
            ."was waiting for its lock; 'worktree create 441-fix-login' makes it again",
        );
});

it('holds a claimed lock for the rest of the run, and gives back a swept one', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout'),
    ]);

    $this->fleet->claim('wt-desk-441-fix-login');

    expect(lockDirectory('wt-desk-441-fix-login'))->toBeDirectory();

    $held = [];

    $this->fleet->sweep(
        $this->fleet->here(),
        function (Entry $entry, string $key) use (&$held): Verdict {
            // One key's lock at a time: the key being acted on is locked, and
            // the one after it is not — a sweep that held all of them would
            // block every unrelated create on the machine for its duration.
            $held[$key] = array_map(lockDirectory(...), array_keys($this->fleet->here()));
            $held[$key] = array_values(array_filter($held[$key], is_dir(...)));

            return Verdict::worked();
        },
    );

    expect($held['wt-desk-441-fix-login'])->toBe([lockDirectory('wt-desk-441-fix-login')])
        // 441's is still there because the run claimed it above and claims are
        // held to process exit; feat-checkout's went back as its work returned.
        ->and($held['wt-desk-feat-checkout'])
        ->toBe([lockDirectory('wt-desk-441-fix-login'), lockDirectory('wt-desk-feat-checkout')])
        ->and(lockDirectory('wt-desk-feat-checkout'))->not->toBeDirectory()
        ->and(lockDirectory('wt-desk-441-fix-login'))->toBeDirectory();
});

/**
 * The loop `stop` and `reap` both ran, three times between them: skip the keys
 * the work declined, keep the ones it finished with apart from the ones it did
 * not, and say what failed — once.
 */
it('sorts a sweep into what worked, what survived and what it left alone', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout'),
        'wt-desk-feat-search' => slotEntry(2, 'feat-search'),
    ]);

    $swept = $this->fleet->sweep($this->fleet->here(), fn (Entry $entry, string $key): ?Verdict => match ($key) {
        'wt-desk-441-fix-login' => Verdict::worked(),
        'wt-desk-feat-checkout' => Verdict::failed("$key survived teardown"),
        // Something claimed it between the scan and now, and has already said
        // so in the words of the command that asked.
        default => null,
    });

    expect($swept->succeeded)->toBe(['wt-desk-441-fix-login'])
        ->and($swept->survived)->toBe(['wt-desk-feat-checkout'])
        ->and(diagnosticsIn($this->diagnostics))->toContain('wt-desk-feat-checkout survived teardown');
});

/**
 * A `stop` reports its own failures as they happen — the runtime writes the
 * Compose output itself — so a verdict carrying no diagnostic must not produce
 * a second line about the same failure.
 */
it('says nothing extra for a failure the work already reported', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $swept = $this->fleet->sweep($this->fleet->here(), fn (): Verdict => Verdict::failed());

    expect($swept->survived)->toBe(['wt-desk-441-fix-login'])
        ->and(diagnosticsIn($this->diagnostics))->toBe('');
});

it('scopes the fleet to this checkout, and widens it to the machine when asked', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-feat-checkout' => slotEntry(1, 'feat-checkout', repo: $this->root.'/shop'),
    ]);

    expect(array_keys($this->fleet->here()))->toBe(['wt-desk-441-fix-login'])
        ->and(array_keys($this->fleet->everywhere()))
        ->toBe(['wt-desk-441-fix-login', 'wt-shop-feat-checkout']);
});

it('allocates a slot against this checkout, and gives it back', function () {
    $entry = $this->fleet->allocate('wt-desk-feat-checkout', 'feat-checkout', 'feat/checkout', $this->root.'/w');

    expect($entry->slot)->toBe(0)
        ->and($entry->repo)->toBe($this->main)
        ->and(array_keys($this->fleet->here()))->toBe(['wt-desk-feat-checkout']);

    $this->fleet->release($entry->key);

    expect($this->fleet->entry($entry->key))->toBeNull();
});

/**
 * `unlock` is the odd one out: it breaks locks rather than taking them, and the
 * only thing it wants from the fleet is the key a name implies — derived
 * exactly as `create` derives it, so that `unlock 441` and `create 441` are
 * unambiguously about the same directory.
 */
it('names the lock a slug implies, without taking it', function () {
    $lock = $this->fleet->lockOn('feat/checkout');

    expect($lock->path())->toBe(lockDirectory('wt-desk-feat-checkout'))
        ->and($lock->exists())->toBeFalse()
        ->and($this->fleet->locksTaken())->toBe([]);

    $this->fleet->claim('wt-desk-feat-checkout');

    expect(array_map(fn ($taken) => $taken->path(), $this->fleet->locksTaken()))
        ->toBe([lockDirectory('wt-desk-feat-checkout')]);
});

/**
 * A fleet over the checkout at $repo, with its diagnostics captured.
 *
 * @param  resource  $diagnostics
 * @param  array<string, mixed>  $config  As `config/worktree.php` would have returned it.
 */
function fleetIn(string $repo, string $home, $diagnostics, array $config = []): Fleet
{
    $output = new Output($diagnostics);
    $runner = new ProcessRunner($output);

    return Fleet::fromConfiguration(
        configurationIn($home, $config),
        Anchor::resolve($runner, $repo),
        $runner,
        new ShutdownHandler($output),
        $output,
    );
}

/**
 * Where the lock over $key lives — a directory, which is the whole of what a
 * lock is here.
 */
function lockDirectory(string $key): string
{
    return test()->home.'/locks/'.$key.'.lock';
}

/**
 * `start`'s clause, for the cases that are about the frame around it rather
 * than about which command is refusing.
 */
function starting(): ForeignCheckout
{
    return ForeignCheckout::because('starting it from here would bring up that checkout\'s containers');
}
