<?php

namespace DeskHQ\LaravelWorktree\Config;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Slug;

/**
 * What `config/worktree.php` may say, and what it means when it says nothing.
 *
 * Validation here is deliberately unforgiving about names: an unknown or
 * misspelled key is an error naming the offender, never a silent no-op. A
 * mistyped `sentinel` would otherwise mean a bootstrap step re-runs on every
 * re-entry with nothing on screen to explain why, and a mistyped `port_base`
 * would mean a carefully chosen port block quietly wasn't used.
 *
 * The defaults are the whole of the documented behaviour for a repository that
 * ships no config file at all.
 */
final readonly class Schema
{
    /**
     * The config file, relative to the main working tree.
     */
    public const string File = 'config/worktree.php';

    /**
     * How long a step may run for when it declares no `timeout` of its own.
     *
     * Thirty minutes is far above any legitimate step — the slowest real ones,
     * a cold `composer install` and a first `npm ci`, are minutes — and far
     * below "forever", which is what this used to be. A number rather than
     * `null` on purpose: `null` would preserve today's behaviour and help only
     * the people who went looking for the key, and a package whose position is
     * that consumers should not have to configure their way out of a trap does
     * not get to leave the trap on by default. A step that genuinely needs
     * longer says so; a step that wants no ceiling at all says `null`.
     */
    public const int DefaultStepTimeout = 1800;

    /**
     * How long the runtime's `boot()` may take to get a worktree from an
     * attached directory to a running app service.
     *
     * Its own limit rather than the step default, because it is the package's
     * call rather than the application's and it is doing something no step
     * does: resolving a `vendor/` through a throwaway Composer container, then
     * pulling and building images. A first boot on a slow connection is the
     * legitimate case, and an hour is what it takes to be sure a run that hit
     * this ceiling is wedged rather than working.
     */
    public const int DefaultBootTimeout = 3600;

    /**
     * Two defaults differ from the bash original on purpose: `slots` rises from
     * 10 to 50 because the registry is machine-global rather than per-repo, and
     * `base_branch` is null — meaning "the repository's own default branch" —
     * rather than a hardcoded `master`.
     *
     * @var array<string, mixed>
     */
    public const array Defaults = [
        'slots' => 50,
        'port_base' => 20000,
        'port_stride' => 10,
        'ports' => ['app', 'vite', 'reverb', 'db', 'redis'],
        'base_branch' => null,
        'repo_slug' => null,
        'env' => [],
        'compose' => ['keep_services' => [], 'port_overrides' => []],
        'step_timeout' => self::DefaultStepTimeout,
        'boot_timeout' => self::DefaultBootTimeout,
        'steps' => [],
    ];

    /**
     * The bounded step DSL. `host`, `sail` and `sail_root` are the three ways
     * to run something; the rest describe when, how, and for how long.
     *
     * @var list<string>
     */
    public const array StepKeys = ['label', 'host', 'sail', 'sail_root', 'sentinel', 'when', 'allow_failure', 'degrade', 'timeout'];

    /** @var list<string> */
    public const array StepActions = ['host', 'sail', 'sail_root'];

    /** @var list<string> */
    public const array ComposeKeys = ['keep_services', 'port_overrides'];

    /**
     * The three questions `when` may ask. Public because {@see Condition} is
     * what answers them, and a vocabulary validated in one place and
     * interpreted in another is a vocabulary that drifts.
     *
     * @var list<string>
     */
    public const array Conditions = ['missing', 'exists', 'env_empty'];

    /**
     * Validate a raw config array and fill in everything it left out.
     *
     * @param  array<mixed>  $config
     * @return array{
     *     slots: int,
     *     port_base: int,
     *     port_stride: int,
     *     ports: list<string>,
     *     base_branch: string|null,
     *     repo_slug: string|null,
     *     env: array<string, scalar|null>,
     *     compose: array{keep_services: list<string>, port_overrides: array<string, list<string>>},
     *     step_timeout: int|null,
     *     boot_timeout: int|null,
     *     steps: list<array<string, string|bool|int|null>>,
     * }
     *
     * @throws WorktreeException on any key this package does not recognise.
     */
    public static function normalise(array $config): array
    {
        foreach (array_keys($config) as $key) {
            if (! array_key_exists($key, self::Defaults)) {
                throw self::error("unknown key '$key'".self::suggestion((string) $key, array_keys(self::Defaults)));
            }
        }

        $merged = array_replace(self::Defaults, $config);

        $slots = self::integer($merged['slots'], 'slots', 1);
        $portBase = self::integer($merged['port_base'], 'port_base', 1024, 65535);
        $portStride = self::integer($merged['port_stride'], 'port_stride', 1);
        $ports = self::ports($merged['ports']);

        if (count($ports) > $portStride) {
            throw self::error('ports names '.count($ports)." ports but port_stride is $portStride; neighbouring slots would overlap");
        }

        $highest = $portBase + $slots * $portStride - 1;

        if ($highest > 65535) {
            throw self::error("slots ($slots) at stride $portStride from port_base $portBase runs past 65535");
        }

        $stepTimeout = self::timeout($merged['step_timeout'], 'step_timeout');

        return [
            'slots' => $slots,
            'port_base' => $portBase,
            'port_stride' => $portStride,
            'ports' => $ports,
            'base_branch' => self::optionalString($merged['base_branch'], 'base_branch'),
            'repo_slug' => self::repoSlug($merged['repo_slug']),
            'env' => self::environment($merged['env']),
            'compose' => self::compose($merged['compose']),
            'step_timeout' => $stepTimeout,
            'boot_timeout' => self::timeout($merged['boot_timeout'], 'boot_timeout'),
            'steps' => self::steps($merged['steps'], $stepTimeout),
        ];
    }

    private static function integer(mixed $value, string $key, int $minimum, ?int $maximum = null): int
    {
        // A value that came from env() is a string: `env('WORKTREE_SLOTS', 50)`
        // reads 50 from the file and '50' from the environment.
        if (! is_int($value) && ! (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1)) {
            throw self::error("$key must be an integer, ".self::describe($value).' given');
        }

        $number = (int) $value;

        if ($number < $minimum || ($maximum !== null && $number > $maximum)) {
            $range = $maximum === null ? "at least $minimum" : "between $minimum and $maximum";

            throw self::error("$key must be $range, $number given");
        }

        return $number;
    }

    /**
     * @return list<string>
     */
    private static function ports(mixed $value): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw self::error('ports must be a non-empty list of names, '.self::describe($value).' given');
        }

        $names = [];

        foreach ($value as $index => $name) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw self::error("ports.$index must be a lowercase name like 'app', ".self::describe($name).' given');
            }

            if (in_array($name, $names, true)) {
                throw self::error("ports names '$name' twice; each port takes its own offset within a slot");
            }

            $names[] = $name;
        }

        return $names;
    }

    private static function optionalString(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw self::error("$key must be a string or null, ".self::describe($value).' given');
        }

        return $value;
    }

    /**
     * The repo slug becomes part of the Compose project name, so it has to be a
     * legal one — Docker would otherwise reject it much later, when the first
     * container is started. It never becomes the *whole* of one: every project
     * name carries the literal `wt-` marker, which nothing set here can remove.
     */
    private static function repoSlug(mixed $value): ?string
    {
        $slug = self::optionalString($value, 'repo_slug');

        if ($slug !== null && ! Slug::isProjectName($slug)) {
            throw self::error("repo_slug must be a valid Compose project name ([a-z0-9][a-z0-9_-]*), '$slug' given");
        }

        return $slug;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function environment(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::error('env must be a map of variable names to values, '.self::describe($value).' given');
        }

        $variables = [];

        foreach ($value as $name => $entry) {
            // A name a shell cannot assign is caught here rather than written
            // into a `.env` that then fails to parse, in a container, later.
            if (! is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
                throw self::error('env keys must be variable names; '.self::describe($name).' is not one');
            }

            if ($entry !== null && ! is_scalar($entry)) {
                throw self::error("env.$name must be a scalar or null, ".self::describe($entry).' given');
            }

            $variables[$name] = $entry;
        }

        return $variables;
    }

    /**
     * @return array{keep_services: list<string>, port_overrides: array<string, list<string>>}
     */
    private static function compose(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::error('compose must be an array, '.self::describe($value).' given');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::ComposeKeys, true)) {
                throw self::error("compose: unknown key '$key'".self::suggestion((string) $key, self::ComposeKeys));
            }
        }

        $services = $value['keep_services'] ?? [];

        if (! is_array($services) || ! array_is_list($services)) {
            throw self::error('compose.keep_services must be a list of service names, '.self::describe($services).' given');
        }

        foreach ($services as $index => $service) {
            if (! is_string($service) || $service === '') {
                throw self::error("compose.keep_services.$index must be a service name, ".self::describe($service).' given');
            }
        }

        $overrides = $value['port_overrides'] ?? [];

        if (! is_array($overrides)) {
            throw self::error('compose.port_overrides must be a map of service names to port mappings, '.self::describe($overrides).' given');
        }

        $mappings = [];

        foreach ($overrides as $service => $ports) {
            if (! is_string($service) || $service === '') {
                throw self::error('compose.port_overrides keys must be service names; '.self::describe($service).' is not one');
            }

            if (! is_array($ports) || ! array_is_list($ports)) {
                throw self::error("compose.port_overrides.$service must be a list of port mappings, ".self::describe($ports).' given');
            }

            foreach ($ports as $index => $mapping) {
                if (! is_string($mapping) || $mapping === '') {
                    throw self::error("compose.port_overrides.$service.$index must be a port mapping like '{{port.reverb}}:8080', ".self::describe($mapping).' given');
                }
            }

            $mappings[$service] = $ports;
        }

        return ['keep_services' => $services, 'port_overrides' => $mappings];
    }

    /**
     * @param  int|null  $timeout  `step_timeout`: what a step that declares none of its own gets.
     * @return list<array<string, string|bool|int|null>>
     */
    private static function steps(mixed $value, ?int $timeout): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::error('steps must be a list of steps, '.self::describe($value).' given');
        }

        $steps = [];

        foreach ($value as $index => $step) {
            if (! is_array($step)) {
                throw self::error("steps.$index must be an array of step keys, ".self::describe($step).' given');
            }

            $steps[] = self::step($step, "steps.$index", $timeout);
        }

        return $steps;
    }

    /**
     * @param  array<mixed>  $step
     * @return array<string, string|bool|int|null>
     */
    private static function step(array $step, string $where, ?int $timeout): array
    {
        foreach (array_keys($step) as $key) {
            if (! in_array($key, self::StepKeys, true)) {
                throw self::error("$where: unknown key '$key'".self::suggestion((string) $key, self::StepKeys));
            }
        }

        $actions = array_values(array_intersect(self::StepActions, array_keys($step)));

        if (count($actions) !== 1) {
            $named = implode(', ', self::StepActions);

            throw self::error(count($actions) === 0
                ? "$where runs nothing; give it one of $named"
                : "$where names ".implode(' and ', $actions).", but a step runs one command; use one of $named");
        }

        $validated = [];

        foreach ($step as $key => $entry) {
            $validated[$key] = match ($key) {
                'allow_failure' => is_bool($entry)
                    ? $entry
                    : throw self::error("$where.allow_failure must be true or false, ".self::describe($entry).' given'),
                'when' => self::condition($entry, $where),
                'timeout' => self::timeout($entry, "$where.timeout"),
                default => is_string($entry) && $entry !== ''
                    ? $entry
                    : throw self::error("$where.$key must be a non-empty string, ".self::describe($entry).' given'),
            };
        }

        // Resolved here rather than in the pipeline, so that every step of the
        // recipe carries the limit it will actually be run under before the
        // first one starts — and so that a step which says `'timeout' => null`
        // keeps its own answer rather than being handed the default back.
        if (! array_key_exists('timeout', $validated)) {
            $validated['timeout'] = $timeout;
        }

        // `degrade` is what makes `allow_failure` honest — the notice a skipped
        // step announces itself with at the end of the run. On a step that
        // aborts the bootstrap it is never reached, so the message would be
        // written, read as covered, and never printed by anything.
        if (isset($validated['degrade']) && ($validated['allow_failure'] ?? false) !== true) {
            throw self::error("$where.degrade is only printed for a step that was allowed to fail; add 'allow_failure' => true, or drop the message");
        }

        return $validated;
    }

    /**
     * A number of seconds something may run for, or null for no ceiling at all.
     *
     * An empty value is read as null for the same reason {@see optionalString()}
     * does: `env('WORKTREE_STEP_TIMEOUT')` with nothing exported is `''`, and
     * "the variable is not set" is an opinion about limits rather than a
     * malformed one.
     */
    private static function timeout(mixed $value, string $key): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1)) {
            throw self::error("$key must be a whole number of seconds, or null for no limit; ".self::describe($value).' given');
        }

        $seconds = (int) $value;

        if ($seconds < 1) {
            throw self::error("$key must be at least 1 second, or null for no limit; $seconds given");
        }

        return $seconds;
    }

    private static function condition(mixed $value, string $where): string
    {
        $conditions = implode(', ', array_map(fn (string $condition) => $condition.':', self::Conditions));

        if (! is_string($value) || preg_match('/\A('.implode('|', self::Conditions).'):(.+)\z/', $value) !== 1) {
            throw self::error("$where.when must be one of $conditions, ".self::describe($value).' given');
        }

        return $value;
    }

    /**
     * @param  list<string>  $known
     */
    private static function suggestion(string $key, array $known): string
    {
        $closest = null;
        $distance = PHP_INT_MAX;

        foreach ($known as $candidate) {
            $measured = levenshtein($key, $candidate);

            if ($measured < $distance) {
                $distance = $measured;
                $closest = $candidate;
            }
        }

        return $closest !== null && $distance <= 3 ? " (did you mean '$closest'?)" : '';
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? "'$value'" : get_debug_type($value);
    }

    private static function error(string $message): WorktreeException
    {
        return new WorktreeException(self::File.': '.$message);
    }

    private function __construct() {}
}
