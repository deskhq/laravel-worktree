<?php

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Registry\Allocator;
use DeskHQ\LaravelWorktree\Registry\Entry;

/**
 * Slot and port allocation: the lowest free slot, the block that follows from
 * it, the bind probe that skips a block something else already holds, and the
 * refusals. The concurrent processes are in RegistryConcurrencyTest.php.
 */
beforeEach(function () {
    $this->home = temporaryDirectory('worktree-home');
    $this->diagnostics = fopen('php://memory', 'w+');
    $this->base = freePortBase(100);
    $this->allocator = allocatorIn($this->home, $this->base, $this->diagnostics);
});

afterEach(function () {
    fclose($this->diagnostics);
    deleteDirectory($this->home);
});

it('claims the lowest free slot and derives its whole port block', function () {
    $entry = claim($this->allocator, 'wt-desk-441');

    expect($entry->slot)->toBe(0)
        ->and($entry->ports)->toBe([
            'app' => $this->base,
            'vite' => $this->base + 1,
            'reverb' => $this->base + 2,
            'db' => $this->base + 3,
            'redis' => $this->base + 4,
        ])
        ->and($entry->createdAt)->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/');
});

it('gives the next worktree the next slot, and a block that does not overlap', function () {
    $first = claim($this->allocator, 'wt-desk-441');
    $second = claim($this->allocator, 'wt-desk-512');
    $third = claim($this->allocator, 'wt-shop-checkout', repo: '/checkouts/shop');

    expect([$first->slot, $second->slot, $third->slot])->toBe([0, 1, 2])
        ->and(array_intersect(array_values($first->ports), array_values($second->ports)))->toBe([])
        ->and(array_intersect(array_values($second->ports), array_values($third->ports)))->toBe([]);
});

it('resumes the slot a key already holds instead of taking another', function () {
    $claimed = claim($this->allocator, 'wt-desk-441');
    $resumed = claim($this->allocator, 'wt-desk-441');

    expect($resumed->slot)->toBe($claimed->slot)
        ->and($resumed->ports)->toBe($claimed->ports)
        ->and($resumed->createdAt)->toBe($claimed->createdAt)
        ->and($this->allocator->registry()->claimedSlots())->toBe([0]);
});

it('resumes on the ports the entry recorded, deriving only what it does not name', function () {
    // An entry from before `redis` was one of the ports a slot publishes, on a
    // port_base that has moved since: those are the ports its containers were
    // actually published on, so they are what it resumes with.
    file_put_contents($this->home.'/registry.json', json_encode(['wt-desk-441' => [
        'slot' => 1,
        'repo' => '/checkouts/desk',
        'slug' => '441',
        'branch' => '441-fix-login',
        'path' => '/checkouts/desk-worktrees/441-fix-login',
        'ports' => ['app' => 45000, 'vite' => 45001],
    ]]));

    $entry = claim($this->allocator, 'wt-desk-441');

    expect($entry->slot)->toBe(1)
        ->and($entry->ports['app'])->toBe(45000)
        ->and($entry->ports['redis'])->toBe($this->base + 14);
});

it('skips a slot whose ports are already bound, and says which port', function () {
    $held = stream_socket_server('tcp://0.0.0.0:'.($this->base + 3), $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

    expect($held)->not->toBeFalse();

    $entry = claim($this->allocator, 'wt-desk-441');

    fclose($held);

    expect($entry->slot)->toBe(1)
        ->and($entry->ports['app'])->toBe($this->base + 10)
        ->and(diagnostics($this->diagnostics))
        ->toContain('slot 0 skipped: port '.($this->base + 3).' (db) is already in use on this machine');
});

it('refuses when every slot is claimed, and says how to free one', function () {
    $allocator = allocatorIn($this->home, $this->base, $this->diagnostics, slots: 2);

    claim($allocator, 'wt-desk-441');
    claim($allocator, 'wt-desk-512');

    expect(fn () => claim($allocator, 'wt-desk-604'))
        ->toThrow(WorktreeException::class, 'all 2 worktree slots are in use');
});

it('refuses when every free slot has a port the machine is already using', function () {
    $allocator = allocatorIn($this->home, $this->base, $this->diagnostics, slots: 2);

    $held = [
        stream_socket_server('tcp://0.0.0.0:'.$this->base, $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN),
        stream_socket_server('tcp://0.0.0.0:'.($this->base + 12), $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN),
    ];

    $failure = null;

    try {
        claim($allocator, 'wt-desk-441');
    } catch (WorktreeException $e) {
        $failure = $e;
    }

    foreach ($held as $socket) {
        fclose($socket);
    }

    expect($failure)->toBeInstanceOf(WorktreeException::class)
        ->and($failure->getMessage())->toContain('no free slot has a free port block (2 of 2 slots skipped by the bind probe)');
});

it('draws two checkouts of the same repository from the same machine registry', function () {
    // The collision D6 exists to prevent: a per-repository registry would have
    // given both of these slot 0, and the second would have died on a bound
    // port minutes into its bootstrap.
    $first = claim($this->allocator, 'wt-desk-441', repo: '/checkouts/desk');
    $second = claim($this->allocator, 'wt-desk-clone-441', repo: '/checkouts/desk-clone');

    expect($first->slot)->toBe(0)
        ->and($second->slot)->toBe(1)
        ->and(array_intersect(array_values($first->ports), array_values($second->ports)))->toBe([])
        ->and(array_keys($this->allocator->registry()->forRepo('/checkouts/desk')))->toBe(['wt-desk-441']);
});

it('refuses a key another checkout already registered', function () {
    claim($this->allocator, 'wt-desk-441', repo: '/checkouts/desk');

    expect(fn () => claim($this->allocator, 'wt-desk-441', repo: '/checkouts/desk-clone'))
        ->toThrow(WorktreeException::class, "'wt-desk-441' is already registered to /checkouts/desk, not /checkouts/desk-clone")
        ->and(fn () => claim($this->allocator, 'wt-desk-441', repo: '/checkouts/desk-clone'))
        ->toThrow(WorktreeException::class, "set 'repo_slug'");
});

it('gives a released slot to the next worktree that asks', function () {
    claim($this->allocator, 'wt-desk-441');
    $second = claim($this->allocator, 'wt-desk-512');

    $this->allocator->release('wt-desk-441');

    $third = claim($this->allocator, 'wt-desk-604');

    expect($second->slot)->toBe(1)
        ->and($third->slot)->toBe(0)
        ->and($this->allocator->registry()->entry('wt-desk-441'))->toBeNull();
});

it('holds the machine-wide lock only while it allocates', function () {
    claim($this->allocator, 'wt-desk-441');

    // The slow work — git, Composer, Sail, npm — happens after allocation
    // returns, so the lock every other repository on the machine waits behind
    // has to be gone by then, while this worktree's own is still held.
    expect($this->allocator->locks()->registry()->isHeld())->toBeFalse()
        ->and($this->home.'/registry.lock')->not->toBeDirectory()
        ->and($this->allocator->locks()->worktree('wt-desk-441')->isHeld())->toBeTrue()
        ->and($this->home.'/locks/wt-desk-441.lock')->toBeDirectory();
});

/**
 * An allocator writing into the test's own home, on a free port window, with
 * its diagnostics captured.
 *
 * @param  resource  $diagnostics
 */
function allocatorIn(string $home, int $base, $diagnostics, int $slots = 50): Allocator
{
    $output = new Output($diagnostics);

    return Allocator::fromConfiguration(
        configurationIn($home, ['slots' => $slots, 'port_base' => $base]),
        new ShutdownHandler($output),
        $output,
    );
}

function claim(Allocator $allocator, string $key, string $repo = '/checkouts/desk'): Entry
{
    return $allocator->allocate($key, $repo, '441', '441-fix-login', $repo.'-worktrees/441-fix-login');
}

/**
 * @param  resource  $diagnostics
 */
function diagnostics($diagnostics): string
{
    rewind($diagnostics);

    return (string) stream_get_contents($diagnostics);
}
