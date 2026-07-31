<?php

use DeskHQ\LaravelWorktree\Config\Env;

/**
 * The `env()` a config file expects, for a process that has no application.
 *
 * Required on demand by {@see Env::load()} and never through Composer's `files`
 * autoloading: inside a real application this file must not be reached at all,
 * so that `config/worktree.php` is read through Laravel's own helper there and
 * this one only ever stands in when nothing else has.
 */
if (! function_exists('env')) {
    /**
     * @param  mixed  $default  Returned when the variable is absent; a closure is called.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}
