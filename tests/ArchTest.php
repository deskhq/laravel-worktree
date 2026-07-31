<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('it starts subprocesses through the process runner alone')
    ->expect(['exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system', 'pcntl_exec'])
    ->each->not->toBeUsed();

arch('the host binary never reaches for Laravel')
    ->expect([
        'DeskHQ\LaravelWorktree\Bootstrap',
        'DeskHQ\LaravelWorktree\Compose',
        'DeskHQ\LaravelWorktree\Config',
        'DeskHQ\LaravelWorktree\Console',
        'DeskHQ\LaravelWorktree\Git',
        'DeskHQ\LaravelWorktree\Naming',
        'DeskHQ\LaravelWorktree\Process',
        'DeskHQ\LaravelWorktree\Registry',
        'DeskHQ\LaravelWorktree\Runtime',
        'DeskHQ\LaravelWorktree\Support',
    ])
    ->not->toUse('Illuminate');

/**
 * The same constraint, turned on the config file rather than on this package:
 * `config/worktree.php` is read on the host with nothing booted, so it may not
 * reference application classes, container bindings or facades. The fixture
 * loads it in a process where none of them exist to be reached.
 */
it('reads the shipped config in a process with only the package autoloaded', function () {
    $process = readsConfiguration(packagePath(), isolated: true);

    expect($process->getErrorOutput())->toBe('')
        ->and($process)->toHaveSucceeded()
        ->and(json_decode($process->getOutput(), true))->toMatchArray(['slots' => 50, 'portBase' => 20000]);
});

it('refuses a config that reaches for the application', function (string $body, string $named) {
    $root = repositoryWithConfig($body);

    $process = readsConfiguration($root, isolated: true);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain($named)
        ->toContain('it runs on the host with no application booted')
        ->toContain('may not reference application classes, container bindings or facades');

    deleteDirectory($root);
})->with([
    'a facade' => ['<?php return ["slots" => \Illuminate\Support\Facades\Config::get("worktree.slots", 50)];', 'Illuminate\Support\Facades\Config'],
    'an application class' => ['<?php return ["repo_slug" => (new \App\Support\RepositoryName)->slug()];', 'App\Support\RepositoryName'],
    'a container binding' => ['<?php return ["slots" => app("config")->get("worktree.slots")];', 'app()'],
]);
