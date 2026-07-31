<?php

namespace DeskHQ\LaravelWorktree\Compose;

use DeskHQ\LaravelWorktree\Config\Assignments;

/**
 * What this application calls its app service.
 *
 * `laravel.test` is Sail's default, not its rule: `bin/sail` reads
 * `${APP_SERVICE:-"laravel.test"}` from the file it sources, and an application
 * that renamed the service is entitled to have everything else follow. The bash
 * original hardcoded the name twice — in the list of services a worktree
 * starts, and in the Compose override it generated — so on such an application
 * the override trimmed a service that did not exist and the bootstrap started
 * one that did not either, each of them silently.
 *
 * Read from the *worktree's* environment file, because that is the file
 * `bin/sail` will source when it runs there. This process's environment is the
 * main checkout's, and the two are allowed to differ.
 */
final readonly class AppService
{
    /** Sail's name for the variable, and the name we read it under. */
    public const string Variable = 'APP_SERVICE';

    /** What Sail falls back to, so what we fall back to. */
    public const string Default = 'laravel.test';

    public static function in(Assignments $environment): string
    {
        $service = $environment->value(self::Variable);

        // `${APP_SERVICE:-"laravel.test"}` — to a shell, an empty assignment is
        // no more set than an absent one.
        return $service === null || $service === '' ? self::Default : $service;
    }

    private function __construct() {}
}
