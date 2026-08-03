<?php

namespace DeskHQ\LaravelWorktree\Config;

use DeskHQ\LaravelWorktree\Compose\AppService;

/**
 * Which environment file a directory is read through.
 *
 * ```bash
 * if [ -n "$APP_ENV" ] && [ -f ./.env."$APP_ENV" ]; then
 *   source ./.env."$APP_ENV";
 * elif [ -f ./.env ]; then
 *   source ./.env;
 * fi
 * ```
 *
 * That is `bin/sail`, and Laravel's `LoadEnvironmentVariables` agrees with it.
 * Three things in this package have to agree with them both: the `env()`
 * `config/worktree.php` is read through ({@see Env}), the file a new worktree's
 * environment starts from ({@see EnvFile}), and what a checkout calls its app
 * service before it has a worktree to ask ({@see AppService::at()}).
 *
 * They used to answer it three times, one of them pointing at another in a
 * docblock and having drifted from it (#76): the last candidate — a repository
 * that ships an `.env.example` and nothing else — was in two of the three
 * copies, from two different directories, and absent from the third with
 * nothing to say so.
 *
 * ## The example is a candidate whose directory the caller names
 *
 * *Which* `.env.example` is the right one is genuinely the caller's question:
 * {@see EnvFile} is generating a worktree's environment and the example ships
 * with that worktree's own code, while {@see AppService::at()} is asking about
 * a checkout that has no worktree yet. So the order is here and the directory
 * is theirs — and a caller for whom the example is no answer at all
 * ({@see nameIn()}) names none.
 *
 * ## `APP_ENV` is the shell's, never the file's
 *
 * The environment this is built on is the one the process was started with
 * ({@see Env::exportedEnvironment()}). A `.env` that sets `APP_ENV=local` — as
 * almost every Laravel application does — has said nothing about which file it
 * is itself read through, and reading it as though it had was #38.
 */
final readonly class Preference
{
    /** What a repository ships when it ships no environment of its own. */
    public const string Example = '.env.example';

    /** The file the rule ends at, whether or not it is there. */
    public const string Default = '.env';

    private function __construct(private ?string $environment) {}

    /**
     * The rule as this run's shell leaves it, which is how the binary asks.
     */
    public static function exported(): self
    {
        return new self(Env::exportedEnvironment());
    }

    /**
     * The rule for a nominated environment, `null` being a shell that exported
     * none — what {@see EnvFile} holds, so that a test can ask what a machine
     * with `APP_ENV=production` would have done.
     */
    public static function of(?string $environment): self
    {
        return new self($environment);
    }

    /**
     * The names, in the order a shell and phpdotenv both consider them.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        return $this->environment === null ? [self::Default] : ['.env.'.$this->environment, self::Default];
    }

    /**
     * The name $directory is read through, whether or not the file is there.
     *
     * A name, not a path, because that is what phpdotenv is handed alongside
     * the directory. And no example: Laravel reads none, and `env()` under
     * `vendor/bin/worktree` has to mean what it means under `php artisan` — a
     * variable resolved from `.env.example` on the host and from nothing at all
     * inside the application is the divergence {@see Env} exists to prevent.
     */
    public function nameIn(string $directory): string
    {
        return $this->existingIn($directory) ?? self::Default;
    }

    /**
     * The path of the file $directory is actually read from, or null when it
     * has none and no example stands in for one.
     *
     * @param  string|null  $exampleIn  The directory whose {@see Example} is the last candidate; null leaves the example out of the search.
     */
    public function pathIn(string $directory, ?string $exampleIn = null): ?string
    {
        $name = $this->existingIn($directory);

        if ($name !== null) {
            return $directory.'/'.$name;
        }

        if ($exampleIn !== null && is_file($exampleIn.'/'.self::Example)) {
            return $exampleIn.'/'.self::Example;
        }

        return null;
    }

    /**
     * Whether a path {@see pathIn()} answered with is the shipped example
     * rather than an environment of the directory's own.
     *
     * Worth telling apart, and worth saying out loud: the example is what a
     * repository documents itself with, so the values in it are nobody's real
     * configuration.
     */
    public static function isExample(string $path): bool
    {
        return basename($path) === self::Example;
    }

    /**
     * The first candidate $directory actually has, or null when it has none.
     */
    private function existingIn(string $directory): ?string
    {
        foreach ($this->candidates() as $name) {
            if (is_file($directory.'/'.$name)) {
                return $name;
            }
        }

        return null;
    }
}
