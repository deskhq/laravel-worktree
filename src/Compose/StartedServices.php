<?php

namespace DeskHQ\LaravelWorktree\Compose;

use DeskHQ\LaravelWorktree\Bootstrap\Action;
use DeskHQ\LaravelWorktree\Config\Schema;

/**
 * Which services a worktree will actually run, and why each one of them.
 *
 * This is the part nobody can be expected to do in their head, and the part the
 * paragraph in `config/worktree.php` asked them to. A worktree starts:
 *
 * 1. the **app service** — `APP_SERVICE`, or Sail's `laravel.test`, which is
 *    what the runtime runs `up -d` against;
 * 2. whatever the app service depends on, which is `compose.keep_services`
 *    when the overlay trims it and `compose.yaml`'s own list when it does not;
 * 3. every service a **bootstrap step** brings up by hand — `sail up -d
 *    reverb`, which no `depends_on` anywhere mentions;
 * 4. and then the **transitive closure** of `depends_on` over all of that.
 *
 * Step 4 is the whole point. `redis` is in nothing the application can see: it
 * arrives because `reverb` depends on it, and `reverb` arrives because a
 * bootstrap step starts it. The port it publishes then collides on the *second*
 * worktree, minutes into a bootstrap, as a Docker bind error naming a port
 * nobody configured (the-desk#17).
 *
 * So the reason is carried alongside the name, and it accumulates down the
 * chain — *"reverb depends on it, and the bootstrap step 'sail up -d reverb'
 * starts it"*. A refusal that names only the service leaves the reader exactly
 * where the bind error left them.
 */
final readonly class StartedServices
{
    /**
     * How a host step can be spelled and still be Compose. `bin/sail` is a host
     * script, and an application that reaches for `docker compose` or for
     * Podman directly is doing the same thing by another name.
     *
     * @var list<string>
     */
    public const array EntryPoints = ['sail', 'compose', 'docker', 'docker-compose', 'podman', 'podman-compose'];

    /**
     * Compose's own way of saying "this one, and nothing it depends on".
     */
    public const string WithoutDependencies = '--no-deps';

    /**
     * Every service the worktree starts, keyed by name, valued by why.
     *
     * @param  array{keep_services: list<string>, port_overrides: array<string, list<string>>}  $compose  `compose`, as configured.
     * @param  list<array<string, string|bool>>  $steps  `steps`, as {@see Schema} normalised them.
     * @return array<string, string>
     */
    public static function of(Services $services, string $appService, array $compose, array $steps): array
    {
        /** @var list<array{0: string, 1: string, 2: bool}> $seeds */
        $seeds = [[$appService, 'it is the app service', true]];

        foreach ($steps as $step) {
            foreach (self::startedBy($services, $step) as $seed) {
                $seeds[] = $seed;
            }
        }

        $queue = [];
        $isolated = [];

        foreach ($seeds as [$name, $why, $withDependencies]) {
            if ($withDependencies) {
                $queue[] = [$name, $why];

                continue;
            }

            $isolated[$name] ??= $why;
        }

        $started = [];

        while ($queue !== []) {
            /** @var array{0: string, 1: string} $next */
            $next = array_shift($queue);
            [$name, $why] = $next;

            // First reason wins, and a cycle in `depends_on` terminates here
            // rather than in a Compose error nobody reads.
            if (array_key_exists($name, $started)) {
                continue;
            }

            $started[$name] = $why;

            foreach (self::dependenciesOf($services, $name, $appService, $compose) as $dependency) {
                $queue[] = [$dependency, "$name depends on it, and $why"];
            }
        }

        foreach ($isolated as $name => $why) {
            $started[$name] ??= $why;
        }

        return $started;
    }

    /**
     * What $service pulls in, with the overlay's override applied.
     *
     * Only the app service's list is ever replaced, and only when
     * `keep_services` says something: that is exactly what {@see Overlay}
     * writes, and an empty list means "as `compose.yaml` declares it" there
     * too.
     *
     * @param  array{keep_services: list<string>, port_overrides: array<string, list<string>>}  $compose
     * @return list<string>
     */
    private static function dependenciesOf(Services $services, string $service, string $appService, array $compose): array
    {
        if ($service === $appService && $compose['keep_services'] !== []) {
            return $compose['keep_services'];
        }

        return $services->dependenciesOf($service);
    }

    /**
     * The services one bootstrap step brings up by hand.
     *
     * @param  array<string, string|bool>  $step
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function startedBy(Services $services, array $step): array
    {
        foreach (Schema::StepActions as $action) {
            $command = $step[$action] ?? null;

            if (is_string($command)) {
                return self::started($services, $action, $command);
            }
        }

        return [];
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function started(Services $services, string $action, string $command): array
    {
        $arguments = self::arguments($action, $command);

        if (($arguments[0] ?? null) !== 'up') {
            return [];
        }

        // Named services are the tokens the file declares, which is also what
        // keeps an option's value — `--scale web=2`, `-t 30` — from being read
        // as one.
        $named = array_values(array_filter(
            array_slice($arguments, 1),
            fn (string $argument): bool => $services->declares($argument),
        ));

        $withDependencies = ! in_array(self::WithoutDependencies, $arguments, true);
        $step = "the bootstrap step '$action $command'";

        if ($named !== []) {
            return array_map(fn (string $name): array => [$name, "$step starts it", $withDependencies], $named);
        }

        // `up` with nothing named is `up` with everything named.
        return array_map(
            fn (string $name): array => [$name, "$step starts every service $services->file declares", $withDependencies],
            $services->unprofiled(),
        );
    }

    /**
     * The step's command line as Compose would see its arguments, or `[]` when
     * the step is not a Compose invocation at all.
     *
     * A `sail:` step's command *is* Sail's argv — the action is what supplies
     * the binary — so `up -d reverb` needs no unwrapping. A `host:` step has to
     * name its own way in, and requiring one is what keeps `npm run up` from
     * reading as a Compose `up` that starts the whole file. A `sail_root:` step
     * runs inside the container, where there is no Compose to invoke.
     *
     * @return list<string>
     */
    private static function arguments(string $action, string $command): array
    {
        if ($action === Action::SailRoot->value) {
            return [];
        }

        $tokens = array_values(array_filter(preg_split('/\s+/', trim($command)) ?: []));

        if ($action === Action::Sail->value) {
            return $tokens;
        }

        $stripped = 0;

        while ($tokens !== [] && in_array(basename($tokens[0]), self::EntryPoints, true)) {
            array_shift($tokens);
            $stripped++;
        }

        return $stripped === 0 ? [] : $tokens;
    }

    private function __construct() {}
}
