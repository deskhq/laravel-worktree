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

it('delegates to the host binary when it is on the host', function () {
    pretendToBeContainerised(false);

    $this->artisan('worktree:create', ['arguments' => ['441']])
        ->expectsOutputToContain('create is not implemented yet')
        ->assertExitCode(1);
});
