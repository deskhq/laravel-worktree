<?php

namespace DeskHQ\LaravelWorktree\Compose;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Runtime\Docker;

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
 *
 * ## Asked once, and answerable without throwing
 *
 * A create wants a refusal, and `worktree doctor` wants the same sentence as a
 * line of a report it keeps going from — so the message is built in exactly one
 * place ({@see answer()}) and {@see verify()} is the caller that throws it.
 * Nothing about the check differs between the two, which is the point: a report
 * that reworded the refusal would send somebody looking for a sentence that
 * appears nowhere else.
 */
final class ComposeVersion
{
    /** The Compose release that added the `!override` merge tag. */
    public const string Minimum = '2.24';

    /**
     * What the subcommand said, what version that names, and what is wrong with
     * it — resolved once, because it costs a subprocess and cannot change
     * usefully within one run.
     *
     * @var array{string|null, string|null, string|null}|null
     */
    private ?array $answer = null;

    public function __construct(
        private readonly ProcessRunner $runner,
        /** `SAIL_DOCKER_BINARY`: `docker`, or `podman` on a machine that runs that instead. */
        private readonly string $binary = 'docker',
    ) {}

    /**
     * The check as the binary makes it, honouring Sail's own `SAIL_DOCKER_BINARY`.
     */
    public static function for(ProcessRunner $runner): self
    {
        return new self($runner, Docker::configuredBinary());
    }

    /**
     * @throws WorktreeException when Compose is absent, or too old for `!override`.
     */
    public function verify(): void
    {
        $problem = $this->problem();

        if ($problem !== null) {
            throw new WorktreeException($problem);
        }
    }

    /**
     * What is wrong with this machine's Compose, in the words {@see verify()}
     * refuses with — or null when there is nothing wrong with it.
     */
    public function problem(): ?string
    {
        return $this->answer()[2];
    }

    /**
     * What `compose version --short` answered with, or null when it would not
     * answer at all.
     */
    public function found(): ?string
    {
        return $this->answer()[0];
    }

    /**
     * The `major.minor` read out of that answer, or null when it named no
     * version this could read — which is not the same as being too old, and is
     * why {@see verify()} lets it through.
     */
    public function version(): ?string
    {
        return $this->answer()[1];
    }

    /**
     * @return array{string|null, string|null, string|null}
     */
    private function answer(): array
    {
        if ($this->answer !== null) {
            return $this->answer;
        }

        $result = $this->runner->consult([$this->binary, 'compose', 'version', '--short']);

        if (! $result->succeeded()) {
            return $this->answer = [null, null, "Docker Compose v2 is required ('$this->binary compose' is not available): "
                .Overlay::File.' is written with the \'!override\' merge tag, which needs Compose >= '.self::Minimum];
        }

        $found = ltrim($result->trimmedOutput(), 'v');

        // A version that cannot be read is not a version that is too old: the
        // subcommand answered, which is the part that would have failed on v1.
        if (preg_match('/\A(\d+\.\d+)/', $found, $matches) !== 1) {
            return $this->answer = [$found, null, null];
        }

        if (version_compare($matches[1], self::Minimum, '<')) {
            return $this->answer = [$found, $matches[1], 'Docker Compose >= '.self::Minimum
                ." is required for the '!override' merge tag ".Overlay::File." is written with (found $found)"];
        }

        return $this->answer = [$found, $matches[1], null];
    }
}
