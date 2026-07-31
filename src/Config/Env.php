<?php

namespace DeskHQ\LaravelWorktree\Config;

use Closure;
use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\RepositoryInterface;

/**
 * The `env()` the host binary reads `config/worktree.php` through.
 *
 * The config file lives in `config/` and looks like Laravel config, so people
 * will reach for `env()` in it — but the binary runs with no application
 * booted, where the helper does not exist. `vlucas/phpdotenv` ships with
 * `laravel/framework` and is therefore already in the main checkout's vendor,
 * so the `.env` is loaded exactly the way `LoadEnvironmentVariables` loads it:
 * the same repository (immutable, with the putenv adapter), the same
 * `.env.<APP_ENV>` preference, the same tolerance of a missing file.
 *
 * The casting below is Laravel's `Illuminate\Support\Env` reproduced value for
 * value, and a test asserts that against the real thing. Anything less and
 * `env('WORKTREE_SLOTS', 10)` would mean one thing under `vendor/bin/worktree`
 * and another under `php artisan worktree:list` — a divergence that costs an
 * afternoon to find because both readings look right.
 */
final class Env
{
    /**
     * `APP_ENV` as this process inherited it, or null.
     *
     * Captured, rather than read when it is wanted, because reading a `.env`
     * puts its own `APP_ENV` into `$_SERVER`, `$_ENV` and `putenv` — after
     * which the two are indistinguishable, and they mean opposite things:
     * `bin/sail` and `LoadEnvironmentVariables` both prefer `.env.<APP_ENV>`
     * over `.env` when the *shell* set it, and neither looks at what the file
     * says. {@see EnvFile} decides which file to generate on this value.
     */
    private static ?string $exported;

    /**
     * Make `env()` available and populate it from the main checkout's `.env`.
     *
     * Safe to call more than once, and safe to call from inside an application:
     * the shim defines nothing if Laravel's helper is already there.
     */
    public static function load(string $root): void
    {
        require_once __DIR__.'/env-shim.php';

        self::exportedEnvironment();

        if (! class_exists(Dotenv::class)) {
            // No phpdotenv — a repository with no Laravel installed yet. Real
            // environment variables still resolve; the file simply has nothing
            // to contribute.
            return;
        }

        Dotenv::create(self::repository(), $root, self::fileIn($root))->safeLoad();
    }

    /**
     * Read an environment variable, cast the way Laravel casts it.
     *
     * @param  mixed  $default  Returned when the variable is absent; a closure is called.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::read($key);

        if ($value === null) {
            return $default instanceof Closure ? $default() : $default;
        }

        return self::cast($value);
    }

    /**
     * The environment this run was started in, from before any `.env` was read.
     *
     * Captured on first use, which the binary does at the top of the run: it
     * loads the configuration before it does anything else, and loading it
     * comes through here.
     */
    public static function exportedEnvironment(): ?string
    {
        if (! isset(self::$exported)) {
            $environment = self::get('APP_ENV');

            self::$exported = is_string($environment) && $environment !== '' ? $environment : null;
        }

        return self::$exported;
    }

    /**
     * `bin/sail` and `LoadEnvironmentVariables` agree on this: when `APP_ENV`
     * is set in the real environment and the matching file exists, it is read
     * *instead of* `.env`, not on top of it.
     */
    private static function fileIn(string $root): string
    {
        $environment = self::exportedEnvironment();

        if ($environment !== null && is_file($root.'/.env.'.$environment)) {
            return '.env.'.$environment;
        }

        return '.env';
    }

    /**
     * Laravel's repository: `$_SERVER` and `$_ENV` for reading, plus `putenv`,
     * and immutable — so a variable exported on the command line beats the
     * `.env`, which is what makes the documented overrides overrides.
     */
    private static function repository(): RepositoryInterface
    {
        $builder = RepositoryBuilder::createWithDefaultAdapters();

        if (class_exists(PutenvAdapter::class)) {
            $builder = $builder->addAdapter(PutenvAdapter::class);
        }

        return $builder->immutable()->make();
    }

    /**
     * The raw string a variable holds, or null when nothing defines it.
     *
     * The adapter order and the scalar filtering are phpdotenv's; `$_SERVER`
     * before `$_ENV` before `getenv()`, non-scalars ignored rather than
     * stringified.
     */
    private static function read(string $key): ?string
    {
        foreach ([$_SERVER, $_ENV] as $source) {
            if (array_key_exists($key, $source) && is_scalar($source[$key])) {
                /** @var scalar $value */
                $value = $source[$key];

                return match (true) {
                    $value === true => 'true',
                    $value === false => 'false',
                    default => (string) $value,
                };
            }
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }

    /**
     * The six special values and surrounding-quote stripping, as
     * {@see \Illuminate\Support\Env} does them.
     *
     * Note that `null` casts to null rather than falling through to the
     * default: the variable *is* set, and it says the value is nothing.
     */
    private static function cast(string $value): string|bool|null
    {
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        if (preg_match('/\A([\'"])(.*)\1\z/', $value, $matches) === 1) {
            return $matches[2];
        }

        return $value;
    }
}
