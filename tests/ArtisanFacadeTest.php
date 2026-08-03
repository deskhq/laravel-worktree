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
        ->toContain('worktree:create', 'worktree:path', 'worktree:list', 'worktree:remove', 'worktree:reap', 'worktree:unlock');
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
    ['worktree:path', 'path'],
    ['worktree:list', 'list'],
    ['worktree:remove', 'remove'],
    ['worktree:reap', 'reap'],
    ['worktree:unlock', 'unlock'],
]);

/**
 * A call the binary refuses before it touches anything, deliberately: this
 * asserts that the facade reaches the binary and hands back what it said, and
 * every command that got as far as doing its work would do it to the machine
 * running the suite — `create` would build a worktree of this repository,
 * `list` would read the developer's own registry, and `remove` and `reap`
 * would tear down whatever they found in it.
 */
it('delegates to the host binary when it is on the host, and hands back its exit code', function () {
    pretendToBeContainerised(false);

    $this->artisan('worktree:reap', ['arguments' => ['441']])
        ->expectsOutputToContain('reap takes no arguments, only options; given 441')
        ->assertExitCode(64);
});

/**
 * Artisan parses options itself and rejects the ones its signature does not
 * declare, so a flag the binary understands has to be declared on the facade
 * and passed back on — a facade that swallowed `--json` would print a path
 * where a script was reading an object, and one that swallowed `--all` would
 * answer about this repository a question asked about the machine.
 */
it('forwards the flags the host binary understands', function (string $command, array $parameters, string $forwarded) {
    pretendToBeContainerised(true);

    $this->artisan($command, $parameters)
        ->expectsOutputToContain('Use:  ./vendor/bin/worktree '.$forwarded)
        ->assertExitCode(1);
})->with([
    ['worktree:create', ['arguments' => ['441'], '--refresh' => true, '--json' => true], 'create 441 --refresh --json'],
    ['worktree:path', ['arguments' => ['441'], '--json' => true], 'path 441 --json'],
    ['worktree:list', ['--all' => true, '--json' => true], 'list --all --json'],
    ['worktree:reap', ['--all' => true, '--dry-run' => true, '--yes' => true], 'reap --all --dry-run --yes'],
    ['worktree:unlock', ['arguments' => ['441'], '--force' => true], 'unlock 441 --force'],
]);
