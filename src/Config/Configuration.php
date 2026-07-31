<?php

namespace DeskHQ\LaravelWorktree\Config;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use Throwable;

/**
 * The repository's settings, read on the host with no application booted.
 *
 * `vendor/bin/worktree` runs before the worktree it is building has a
 * `vendor/`, possibly with no database, no redis and no container running at
 * all, so it cannot boot the application to ask it anything. It reads the
 * `.env` through {@see Env}, defines the `env()` shim, and `require`s
 * `config/worktree.php` — no container, no service providers, nothing an
 * application's broken provider can take down.
 *
 * Which is also the constraint on that file, enforced by a test rather than a
 * sentence in the README: it may not reference application classes, container
 * bindings or facades, because none of them exist here.
 */
final readonly class Configuration
{
    private function __construct(
        /** How many worktrees this machine allocates slots for. */
        public int $slots,
        /** The first host port of slot 0. */
        public int $portBase,
        /** How many ports each slot reserves. */
        public int $portStride,
        /**
         * The ports each worktree publishes, in offset order.
         *
         * @var list<string>
         */
        public array $ports,
        /** What new worktrees fork from; null means the repository's own default branch. */
        public ?string $baseBranch,
        /** Names this repository in registry keys and project names; null means the main root's basename. */
        public ?string $repoSlug,
        /**
         * The variables written into a new worktree's `.env`.
         *
         * @var array<string, scalar|null>
         */
        public array $env,
        /**
         * @var array{keep_services: list<string>, port_overrides: array<string, list<string>>}
         */
        public array $compose,
        /**
         * The bootstrap recipe, in the order it runs.
         *
         * @var list<array<string, string|bool>>
         */
        public array $steps,
        /** Where the machine-global registry and locks live. */
        public string $home,
    ) {}

    /**
     * Read the configuration of the repository anchored at $mainRoot.
     *
     * A repository with no `config/worktree.php` is not an error — it runs on
     * the documented defaults, which is what makes `composer require` followed
     * by `worktree create` work with nothing published.
     *
     * @throws WorktreeException when the file cannot be loaded or says something this package does not understand.
     */
    public static function load(string $mainRoot): self
    {
        Env::load($mainRoot);

        return self::fromArray(self::read($mainRoot.'/'.Schema::File));
    }

    /**
     * @param  array<mixed>  $config  As `config/worktree.php` returned it.
     *
     * @throws WorktreeException
     */
    public static function fromArray(array $config): self
    {
        $settings = Schema::normalise(Overrides::apply($config));

        return new self(
            $settings['slots'],
            $settings['port_base'],
            $settings['port_stride'],
            $settings['ports'],
            $settings['base_branch'],
            $settings['repo_slug'],
            $settings['env'],
            $settings['compose'],
            $settings['steps'],
            self::home(),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $config = require $path;
        } catch (Throwable $e) {
            throw new WorktreeException(
                Schema::File.' could not be loaded ('.$e->getMessage().'); it runs on the host with no application booted, '
                .'so it may not reference application classes, container bindings or facades'
            );
        }

        if (! is_array($config)) {
            throw new WorktreeException(Schema::File.' must return an array, '.get_debug_type($config).' returned');
        }

        return $config;
    }

    /**
     * `WORKTREE_HOME`, or `~/.laravel-worktree`.
     *
     * Machine-global rather than per-repository on purpose: host ports are a
     * machine-scoped resource, so two clones of the same repository must draw
     * their slots from the same registry instead of both taking slot 0.
     */
    private static function home(): string
    {
        $home = Env::get('WORKTREE_HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        $user = Env::get('HOME');

        if (! is_string($user) || $user === '') {
            throw new WorktreeException('could not determine the home directory; set WORKTREE_HOME');
        }

        return rtrim($user, '/').'/.laravel-worktree';
    }
}
