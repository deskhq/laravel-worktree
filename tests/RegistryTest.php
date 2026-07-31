<?php

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Ports;
use DeskHQ\LaravelWorktree\Registry\Registry;

/**
 * The store itself: what it writes, what it tolerates reading back, and what it
 * refuses. Allocation, locking and the bind probe are in AllocatorTest.php;
 * the concurrent processes are in RegistryConcurrencyTest.php.
 */
beforeEach(function () {
    $this->home = temporaryDirectory('worktree-home');
});

afterEach(function () {
    deleteDirectory($this->home);
});

it('keeps the store in the worktree home and reads its entries back', function () {
    $registry = registryIn($this->home);

    expect($registry->entry('wt-desk-441'))->toBeNull()
        ->and($this->home.'/registry.json')->not->toBeFile();

    $registry->put(entryFor('wt-desk-441', slot: 3));

    $entry = registryIn($this->home)->entry('wt-desk-441');

    expect($this->home.'/registry.json')->toBeFile()
        ->and($entry)->not->toBeNull()
        ->and($entry->slot)->toBe(3)
        ->and($entry->repo)->toBe('/checkouts/desk')
        ->and($entry->branch)->toBe('441-fix-login')
        ->and($entry->ports)->toBe(['app' => 20030, 'vite' => 20031, 'reverb' => 20032, 'db' => 20033, 'redis' => 20034]);
});

it('derives a port the configuration declares and the entry does not', function () {
    // An entry written before `redis` was one of the ports a slot publishes:
    // the whole block follows from the slot, so it is repaired, not rejected.
    writeRegistry($this->home, ['wt-desk-441' => rawEntry(2, ['app' => 20020, 'vite' => 20021, 'reverb' => 20022, 'db' => 20023])]);

    expect(registryIn($this->home)->entry('wt-desk-441')->ports)
        ->toBe(['app' => 20020, 'vite' => 20021, 'reverb' => 20022, 'db' => 20023, 'redis' => 20024]);
});

it('keeps the ports an entry recorded when the configuration has moved since', function () {
    // These are the ports the worktree's containers were published on. A
    // port_base changed since must not re-point a running worktree.
    writeRegistry($this->home, ['wt-desk-441' => rawEntry(1, ['app' => 30010, 'vite' => 30011])]);

    expect(registryIn($this->home)->entry('wt-desk-441')->ports)
        ->toBe(['app' => 30010, 'vite' => 30011, 'reverb' => 20012, 'db' => 20013, 'redis' => 20014]);
});

it('drops a port name the configuration no longer declares', function () {
    writeRegistry($this->home, ['wt-desk-441' => rawEntry(0, ['app' => 20000, 'meilisearch' => 20009])]);

    expect(array_keys(registryIn($this->home)->entry('wt-desk-441')->ports))
        ->toBe(['app', 'vite', 'reverb', 'db', 'redis']);
});

it('scopes to one checkout while allocation still sees the whole machine', function () {
    $registry = registryIn($this->home);

    $registry->put(entryFor('wt-desk-441', slot: 0, repo: '/checkouts/desk'));
    $registry->put(entryFor('wt-shop-checkout', slot: 1, repo: '/checkouts/shop'));
    $registry->put(entryFor('wt-desk-512', slot: 2, repo: '/checkouts/desk'));

    expect(array_keys($registry->forRepo('/checkouts/desk')))->toBe(['wt-desk-441', 'wt-desk-512'])
        ->and(array_keys($registry->forRepo('/checkouts/desk/')))->toBe(['wt-desk-441', 'wt-desk-512'])
        ->and(array_keys($registry->forRepo('/checkouts/shop')))->toBe(['wt-shop-checkout'])
        ->and($registry->claimedSlots())->toBe([0, 1, 2]);
});

it('frees a slot when an entry is forgotten, and shrugs at one that was never there', function () {
    $registry = registryIn($this->home);

    $registry->put(entryFor('wt-desk-441', slot: 0));
    $registry->put(entryFor('wt-desk-512', slot: 1));

    $registry->forget('wt-desk-441');
    $registry->forget('wt-desk-never');

    expect($registry->claimedSlots())->toBe([1])
        ->and($registry->entry('wt-desk-441'))->toBeNull();
});

it('refuses a registry it cannot parse, naming the file', function () {
    file_put_contents($this->home.'/registry.json', '{"wt-desk-441": {"slot": 0');

    expect(fn () => registryIn($this->home)->all())
        ->toThrow(WorktreeException::class, 'is not valid JSON');

    expect(fn () => registryIn($this->home)->all())
        ->toThrow(WorktreeException::class, $this->home.'/registry.json');
});

it('refuses an entry that names no slot, or no path, saying which one', function (array $entry, string $missing) {
    writeRegistry($this->home, ['wt-desk-441' => $entry]);

    expect(fn () => registryIn($this->home)->all())
        ->toThrow(WorktreeException::class, "the registry entry for 'wt-desk-441' is unusable")
        ->and(fn () => registryIn($this->home)->all())
        ->toThrow(WorktreeException::class, $missing);
})->with([
    'no slot' => [['repo' => '/checkouts/desk', 'slug' => '441', 'branch' => 'b', 'path' => '/p'], 'names no usable slot'],
    'a slot that is not a number' => [['slot' => 'two', 'repo' => '/checkouts/desk', 'slug' => '441', 'branch' => 'b', 'path' => '/p'], 'names no usable slot'],
    'no path' => [['slot' => 0, 'repo' => '/checkouts/desk', 'slug' => '441', 'branch' => 'b'], 'names no path'],
    'no repo' => [['slot' => 0, 'slug' => '441', 'branch' => 'b', 'path' => '/p'], 'names no repo'],
]);

it('leaves nothing but the store behind when it writes', function () {
    $registry = registryIn($this->home);

    $registry->put(entryFor('wt-desk-441', slot: 0));
    $registry->put(entryFor('wt-desk-512', slot: 1));
    $registry->forget('wt-desk-441');

    expect(array_values(array_diff((array) scandir($this->home), ['.', '..'])))->toBe(['registry.json']);
});

/**
 * The registry of the temporary home, on the shipped defaults.
 *
 * @param  array<string, mixed>  $config
 */
function registryIn(string $home, array $config = []): Registry
{
    return Registry::fromConfiguration(configurationIn($home, $config));
}

function entryFor(string $key, int $slot, string $repo = '/checkouts/desk'): Entry
{
    return new Entry(
        $key,
        $slot,
        $repo,
        '441',
        '441-fix-login',
        $repo.'-worktrees/441-fix-login',
        Ports::fromConfiguration(configurationIn('/unused'))->forSlot($slot),
        '2026-07-30T12:00:00Z',
    );
}

/**
 * An entry as an older version of this package might have left it.
 *
 * @param  array<string, int>  $ports
 * @return array<string, mixed>
 */
function rawEntry(int $slot, array $ports): array
{
    return [
        'slot' => $slot,
        'repo' => '/checkouts/desk',
        'slug' => '441',
        'branch' => '441-fix-login',
        'path' => '/checkouts/desk-worktrees/441-fix-login',
        'ports' => $ports,
    ];
}

/**
 * @param  array<string, mixed>  $entries
 */
function writeRegistry(string $home, array $entries): void
{
    file_put_contents($home.'/registry.json', json_encode($entries, JSON_PRETTY_PRINT));
}
