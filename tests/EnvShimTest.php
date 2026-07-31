<?php

use DeskHQ\LaravelWorktree\Config\Env;
use Illuminate\Support\Env as LaravelEnv;

/**
 * The shim exists so that `env('WORKTREE_SLOTS', 10)` in `config/worktree.php`
 * means the same thing under `vendor/bin/worktree`, which has no application,
 * as it does under `php artisan worktree:list`, which does. So it is asserted
 * against the real Laravel implementation rather than against a table of what
 * that implementation is believed to do.
 */
it('casts exactly as Laravel casts', function (string $raw) {
    withEnvironment(['WORKTREE_SHIM_VALUE' => $raw], function () {
        expect(Env::get('WORKTREE_SHIM_VALUE'))->toBe(LaravelEnv::get('WORKTREE_SHIM_VALUE'));
    });
})->with([
    'true', '(true)', 'TRUE',
    'false', '(false)', 'False',
    'null', '(null)',
    'empty', '(empty)',
    '"quoted"', "'quoted'", '"20000"',
    'a string that just looks like true (true)',
    '20000', '', 'develop', 'sail up -d',
]);

it('falls back to the default only when nothing defines the variable', function () {
    expect(Env::get('WORKTREE_SHIM_ABSENT', 20000))->toBe(20000)
        ->and(Env::get('WORKTREE_SHIM_ABSENT'))->toBeNull()
        ->and(Env::get('WORKTREE_SHIM_ABSENT', fn () => 'called'))->toBe('called');

    // A variable set to `null` is set: it says the value is nothing, which is
    // not the same as saying nothing at all.
    withEnvironment(['WORKTREE_SHIM_VALUE' => 'null'], function () {
        expect(Env::get('WORKTREE_SHIM_VALUE', 20000))->toBeNull()
            ->and(Env::get('WORKTREE_SHIM_VALUE', 20000))->toBe(LaravelEnv::get('WORKTREE_SHIM_VALUE', 20000));
    });
});

it('reads the sources Laravel reads, in the same order', function () {
    $_ENV['WORKTREE_SHIM_VALUE'] = 'from-env';
    putenv('WORKTREE_SHIM_VALUE=from-putenv');

    expect(Env::get('WORKTREE_SHIM_VALUE'))->toBe('from-env');

    $_SERVER['WORKTREE_SHIM_VALUE'] = 'from-server';

    expect(Env::get('WORKTREE_SHIM_VALUE'))->toBe('from-server');

    unset($_SERVER['WORKTREE_SHIM_VALUE'], $_ENV['WORKTREE_SHIM_VALUE']);

    expect(Env::get('WORKTREE_SHIM_VALUE'))->toBe('from-putenv');

    putenv('WORKTREE_SHIM_VALUE');
});

it('leaves the application helper alone when there is one', function () {
    require_once packagePath('src/Config/env-shim.php');

    $reflection = new ReflectionFunction('env');

    expect($reflection->getFileName())->toContain('laravel/framework');
});
