<?php

use DeskHQ\LaravelWorktree\Support\ContainerEnvironment;
use Illuminate\Contracts\Console\Kernel;

function pretendToBeContainerised(bool $containerised): void
{
    app()->instance(
        ContainerEnvironment::class,
        $containerised
            ? new ContainerEnvironment('1')
            : new ContainerEnvironment('0', '/does-not-exist'),
    );
}

it('registers a delegator for each command of the host binary', function () {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('worktree:create', 'worktree:list', 'worktree:remove', 'worktree:reap');
});

it('refuses to run inside the container, and says what to run instead', function () {
    pretendToBeContainerised(true);

    $this->artisan('worktree:create', ['arguments' => ['441']])
        ->expectsOutputToContain("worktree must run on the host, not inside the container.\nUse:  ./vendor/bin/worktree create 441")
        ->assertExitCode(1);
});

it('reports the containerised refusal for every command', function (string $command, string $hostCommand) {
    pretendToBeContainerised(true);

    $this->artisan($command)
        ->expectsOutputToContain('Use:  ./vendor/bin/worktree '.$hostCommand)
        ->assertExitCode(1);
})->with([
    ['worktree:create', 'create'],
    ['worktree:list', 'list'],
    ['worktree:remove', 'remove'],
    ['worktree:reap', 'reap'],
]);

/**
 * A command the roadmap has not built yet, deliberately: this asserts that the
 * facade reaches the binary and hands back what it said, and `create` reaching
 * it would build a worktree of this repository on the machine running the suite.
 */
it('delegates to the host binary when it is on the host', function () {
    pretendToBeContainerised(false);

    $this->artisan('worktree:list')
        ->expectsOutputToContain('list is not implemented yet')
        ->assertExitCode(1);
});

/**
 * Artisan parses options itself and rejects the ones its signature does not
 * declare, so a flag the binary understands has to be declared on the facade
 * and passed back on — a facade that swallowed `--json` would print a path
 * where a script was reading an object.
 */
it('forwards the flags the host binary understands', function () {
    pretendToBeContainerised(true);

    $this->artisan('worktree:create', ['arguments' => ['441'], '--refresh' => true, '--json' => true])
        ->expectsOutputToContain('Use:  ./vendor/bin/worktree create 441 --refresh --json')
        ->assertExitCode(1);
});
