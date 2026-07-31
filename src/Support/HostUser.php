<?php

namespace DeskHQ\LaravelWorktree\Support;

/**
 * The host user this run is.
 *
 * Two things need it, for the same reason and with the same answer: a command
 * that hands work to a container — `docker run --user <uid>:<gid>`, the
 * throwaway Composer image that produces a worktree's first `vendor/` — and the
 * `WWWUSER`/`WWWGROUP` pair `bin/sail` exports and `compose.yaml` interpolates.
 * Get it wrong and what the container writes is owned by root: a `vendor/` the
 * person on the host cannot delete, in a directory they were told is theirs.
 *
 * `posix_*` where the extension is compiled in, and the owner of the running
 * script where it is not — the same user in the case that matters, because that
 * is who installed this package.
 */
final readonly class HostUser
{
    public static function id(): int
    {
        return function_exists('posix_getuid') ? posix_getuid() : (int) getmyuid();
    }

    public static function group(): int
    {
        return function_exists('posix_getgid') ? posix_getgid() : (int) getmygid();
    }

    private function __construct() {}
}
