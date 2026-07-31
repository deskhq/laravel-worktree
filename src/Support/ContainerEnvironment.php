<?php

namespace DeskHQ\LaravelWorktree\Support;

/**
 * Whether this process is running inside the application container.
 *
 * It matters because none of this package's work can be done from in there:
 * there is no docker binary and no daemon socket, the sibling worktrees
 * directory is outside the `.:/var/www/html` bind mount and simply does not
 * exist, and `git worktree add` would write `gitdir:` paths that mean nothing
 * on the host. Detecting it up front turns a confusing failure three minutes
 * into a bootstrap into an immediate, readable one.
 */
final readonly class ContainerEnvironment
{
    /**
     * @param  string|null  $sailFlag  Overrides LARAVEL_SAIL; for tests.
     * @param  string  $dockerEnvFile  The marker Docker writes into every container.
     */
    public function __construct(
        private ?string $sailFlag = null,
        private string $dockerEnvFile = '/.dockerenv',
    ) {}

    public function isContainerised(): bool
    {
        return $this->resolveSailFlag() === '1' || file_exists($this->dockerEnvFile);
    }

    private function resolveSailFlag(): ?string
    {
        if ($this->sailFlag !== null) {
            return $this->sailFlag;
        }

        $value = $_SERVER['LARAVEL_SAIL'] ?? $_ENV['LARAVEL_SAIL'] ?? getenv('LARAVEL_SAIL');

        return is_string($value) ? $value : null;
    }
}
