<?php

namespace DeskHQ\LaravelWorktree\Compose;

use DeskHQ\LaravelWorktree\Config\Env;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * The one thing the overlay needs from the Docker on this machine.
 *
 * {@see Overlay} is written with the `!override` merge tag, which replaces a
 * list rather than appending to it — the whole point of the file, since a
 * `depends_on` that merges is a `depends_on` that was not trimmed. Compose
 * learned that tag in 2.24. An older one merges the two lists instead, silently
 * starts every service the application declares, and the worktree quietly
 * becomes the thing this package exists to avoid.
 *
 * So it is a pre-flight with the version named, rather than a surprise later.
 */
final readonly class ComposeVersion
{
    /** The Compose release that added the `!override` merge tag. */
    public const string Minimum = '2.24';

    public function __construct(
        private ProcessRunner $runner,
        /** `SAIL_DOCKER_BINARY`: `docker`, or `podman` on a machine that runs that instead. */
        private string $binary = 'docker',
    ) {}

    /**
     * The check as the binary makes it, honouring Sail's own `SAIL_DOCKER_BINARY`.
     */
    public static function for(ProcessRunner $runner): self
    {
        $binary = Env::get('SAIL_DOCKER_BINARY');

        return new self($runner, is_string($binary) && $binary !== '' ? $binary : 'docker');
    }

    /**
     * @throws WorktreeException when Compose is absent, or too old for `!override`.
     */
    public function verify(): void
    {
        $result = $this->runner->consult([$this->binary, 'compose', 'version', '--short']);

        if (! $result->succeeded()) {
            throw new WorktreeException(
                "Docker Compose v2 is required ('$this->binary compose' is not available): "
                .Overlay::File.' is written with the \'!override\' merge tag, which needs Compose >= '.self::Minimum
            );
        }

        $found = ltrim($result->trimmedOutput(), 'v');

        // A version that cannot be read is not a version that is too old: the
        // subcommand answered, which is the part that would have failed on v1.
        if (preg_match('/\A(\d+\.\d+)/', $found, $matches) !== 1) {
            return;
        }

        if (version_compare($matches[1], self::Minimum, '<')) {
            throw new WorktreeException(
                'Docker Compose >= '.self::Minimum." is required for the '!override' merge tag "
                .Overlay::File." is written with (found $found)"
            );
        }
    }
}
