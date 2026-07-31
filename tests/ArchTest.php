<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('it starts subprocesses through the process runner alone')
    ->expect(['exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system', 'pcntl_exec'])
    ->each->not->toBeUsed();

arch('the host binary never reaches for Laravel')
    ->expect([
        'DeskHQ\LaravelWorktree\Console',
        'DeskHQ\LaravelWorktree\Git',
        'DeskHQ\LaravelWorktree\Process',
        'DeskHQ\LaravelWorktree\Support',
    ])
    ->not->toUse('Illuminate');
