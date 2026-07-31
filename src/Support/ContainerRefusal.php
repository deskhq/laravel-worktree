<?php

namespace DeskHQ\LaravelWorktree\Support;

/**
 * The message shown when worktree is asked to run inside the container.
 *
 * Shared by the host binary and the artisan facade so both refusals read the
 * same, and so the suggested command is built from what the user actually
 * typed rather than from a fixed example.
 */
final readonly class ContainerRefusal
{
    /**
     * @param  list<string>  $arguments
     */
    public static function message(?string $command = null, array $arguments = []): string
    {
        $message = 'worktree must run on the host, not inside the container.';

        if ($command === null) {
            return $message;
        }

        $invocation = trim($command.' '.implode(' ', $arguments));

        return $message."\n".'Use:  ./vendor/bin/worktree '.$invocation;
    }

    private function __construct() {}
}
