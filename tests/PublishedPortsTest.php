<?php

use DeskHQ\LaravelWorktree\Compose\PublishedPorts;
use DeskHQ\LaravelWorktree\Compose\Services;
use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * The pre-flight that refuses a create whose services would publish a host port
 * nothing offsets.
 *
 * Every case here runs without a Docker daemon and without a container, because
 * the whole check is the configuration read against the application's own
 * Compose file. That is the point: the failure it prevents costs minutes of
 * bootstrap to discover and arrives as a bind error naming a port that is in no
 * file anybody wrote.
 */
it('refuses over a service two levels down a depends_on nobody wrote', function () {
    $message = refusal(fn () => preflight()->verify(configured(
        env: offsets(['FORWARD_DB_PORT' => '{{port.db}}']),
        keep: ['pgsql'],
        overrides: ['reverb' => ['{{port.reverb}}:8080']],
        steps: [['sail' => 'up -d reverb']],
    )));

    expect($message)
        // The service, the mapping, and — because a bind error gives neither —
        // the chain that explains why this worktree runs redis at all.
        ->toContain("redis publishes '\${FORWARD_REDIS_PORT:-6379}:6379'")
        ->toContain("started because reverb depends on it, and the bootstrap step 'sail up -d reverb' starts it")
        ->toContain("add 'FORWARD_REDIS_PORT' => '{{port.redis}}' to 'env'")
        // And nothing about the services this worktree never starts.
        ->not->toContain('meilisearch')
        ->not->toContain('mailpit');
});

it('accepts the same configuration once the offset it named is there', function () {
    expect(fn () => preflight()->verify(configured(
        env: offsets(['FORWARD_DB_PORT' => '{{port.db}}', 'FORWARD_REDIS_PORT' => '{{port.redis}}']),
        keep: ['pgsql'],
        overrides: ['reverb' => ['{{port.reverb}}:8080']],
        steps: [['sail' => 'up -d reverb']],
    )))->not->toThrow(WorktreeException::class);
});

/**
 * `keep_services` is what the overlay trims the app service's `depends_on` to,
 * so it is what decides the closure — an application whose `compose.yaml` has
 * the app service depending on Meilisearch does not start Meilisearch when the
 * overlay has replaced that list.
 */
it('follows the app service\'s trimmed depends_on rather than the file\'s', function (array $keep, bool $reported) {
    // `pgsql` is deliberately left unoffset, so there is always a refusal to
    // read — the question here is only ever which services are in it.
    $message = refusal(fn () => preflight()->verify(configured(env: offsets(), keep: $keep)));

    expect($message)->toContain('pgsql publishes')
        ->and(str_contains($message, 'meilisearch'))->toBe($reported);
})->with([
    'trimmed to pgsql' => [['pgsql'], false],
    // An empty list means "as compose.yaml declares it", never "depend on
    // nothing" — so meilisearch is started, and its port has to be offset.
    'left as the file declares it' => [[], true],
]);

it('includes a service only a bootstrap step brings up, however the step spells it', function (array $step) {
    $message = refusal(fn () => preflight()->verify(configured(
        env: offsets(['FORWARD_DB_PORT' => '{{port.db}}']),
        keep: ['pgsql'],
        steps: [$step],
    )));

    expect($message)->toContain('mailpit publishes');
})->with([
    'a sail step' => [['sail' => 'up -d mailpit']],
    'the same thing on the host' => [['host' => './vendor/bin/sail up -d mailpit']],
    'compose reached directly' => [['host' => 'docker compose up -d mailpit --wait']],
]);

/**
 * The closure has to stop where Compose stops it. `--no-deps` is Compose's own
 * way of saying "this one and nothing under it", and a pre-flight that ignored
 * it would refuse a create over a service the worktree never starts.
 */
it('does not follow the dependencies of a step that asked for none', function () {
    expect(fn () => preflight()->verify(configured(
        env: offsets(),
        keep: ['pgsql'],
        overrides: ['pgsql' => ['{{port.db}}:5432'], 'reverb' => ['{{port.reverb}}:8080']],
        steps: [['sail' => 'up -d --no-deps reverb']],
    )))->not->toThrow(WorktreeException::class);
});

it('reads a bare up as the whole file, minus whatever is behind a profile', function () {
    $message = refusal(fn () => preflight()->verify(configured(env: offsets(), steps: [['sail' => 'up -d']])));

    expect($message)
        ->toContain('mailpit publishes')
        ->toContain('meilisearch publishes')
        ->toContain('starts every service compose.yaml declares')
        // Started by nothing until something names the profile, so publishing
        // nothing this worktree could collide on.
        ->not->toContain('typesense');
});

/**
 * A step's arguments are Compose's, not English: a host command that happens to
 * contain the word `up` is not a Compose invocation, and reading it as one
 * would refuse a create over every service in the file.
 */
it('does not read a host command that merely contains the word up as a Compose up', function () {
    expect(fn () => preflight()->verify(configured(
        env: offsets(),
        keep: ['pgsql'],
        overrides: ['pgsql' => ['{{port.db}}:5432']],
        steps: [['host' => 'npm run up'], ['sail_root' => 'up -d mailpit']],
    )))->not->toThrow(WorktreeException::class);
});

/**
 * The case the issue left open, answered with its own message: a literal host
 * port has no variable for `env` to assign, so pointing the reader at `env` is
 * pointing them somewhere with no fix in it.
 */
it('tells a literal host port apart from an unoffset variable', function () {
    $message = refusal(fn () => preflight()->verify(configured(
        env: offsets(['FORWARD_MAILPIT_PORT' => '{{port.db}}']),
        keep: ['mailpit'],
    )));

    expect($message)
        ->toContain("mailpit publishes '8025:8025'")
        ->toContain("the host side is a literal, so there is no variable for 'env' to offset")
        ->toContain("give mailpit a 'compose.port_overrides' entry")
        // The mapping alongside it is offset, so it is not reported.
        ->not->toContain('FORWARD_MAILPIT_PORT');
});

/**
 * Trap 1, caught by the same pass. `REVERB_PORT` is pinned in `env` because the
 * application dials `reverb:<port>` with it — which offsets nothing on the host
 * side, so without the `port_overrides` entry beside it every worktree publishes
 * 8080 and the second one to start reverb collides.
 */
it('refuses a variable env pins rather than offsets', function () {
    $message = refusal(fn () => preflight()->verify(configured(
        env: offsets(['REVERB_PORT' => 8080]),
        keep: ['pgsql'],
        overrides: ['pgsql' => ['{{port.db}}:5432']],
        steps: [['sail' => 'up -d --no-deps reverb']],
    )));

    expect($message)
        ->toContain("reverb publishes '\${REVERB_PORT:-8080}:8080'")
        ->toContain("'env' assigns 'REVERB_PORT', but a value with no {{port.*}} placeholder in it is the same value in every worktree")
        ->toContain("remap the published side with a 'compose.port_overrides' entry for reverb");

    // Which is exactly what the documented fix for trap 1 does.
    expect(fn () => preflight()->verify(configured(
        env: offsets(['REVERB_PORT' => 8080]),
        keep: ['pgsql'],
        overrides: ['pgsql' => ['{{port.db}}:5432'], 'reverb' => ['{{port.reverb}}:8080']],
        steps: [['sail' => 'up -d --no-deps reverb']],
    )))->not->toThrow(WorktreeException::class);
});

/**
 * `port_overrides` is written with the `!override` merge tag, which replaces
 * the service's whole `ports:` list — so what `compose.yaml` publishes for it
 * is no longer published, mapping by mapping.
 */
it('treats a port_overrides entry as covering everything that service published', function () {
    expect(fn () => preflight()->verify(configured(
        env: offsets(),
        keep: ['mailpit'],
        overrides: ['mailpit' => ['{{port.db}}:1025']],
    )))->not->toThrow(WorktreeException::class);
});

it('names a port the configuration has not declared as one to declare', function () {
    $message = refusal(fn () => preflight()->verify(configured(env: offsets(), keep: ['meilisearch'])));

    expect($message)->toContain("add 'meilisearch' to 'ports', and 'FORWARD_MEILISEARCH_PORT' => '{{port.meilisearch}}' to 'env'");
});

it('counts every mapping of every started service in what it refuses', function () {
    $message = refusal(fn () => preflight()->verify(configured(steps: [['sail' => 'up -d mailpit']])));

    // APP_PORT, VITE_PORT, the two Meilisearch and Mailpit publish, and the
    // literal — every one of them, in one refusal rather than one per run.
    expect($message)->toContain('config/worktree.php: 6 published host ports would be the same in every worktree');
});

it('has nothing to say about a repository that declares no services at all', function () {
    expect(fn () => new PublishedPorts(Services::of("services: {}\n"), 'laravel.test')
        ->verify(configured()))->not->toThrow(WorktreeException::class);
});

/**
 * Compose's port grammar, which a split on `:` gets wrong on the one mapping
 * that matters most: `${FORWARD_REDIS_PORT:-6379}` carries a colon of its own.
 */
it('reads the host side of a port mapping the way Compose does', function (string $ports, ?string $variable, ?string $mapping) {
    $published = Services::of("services:\n    one:\n        ports:\n$ports")->publishedBy('one');

    if ($variable === null && $mapping === null) {
        expect($published)->toBe([]);

        return;
    }

    expect($published)->toHaveCount(1)
        ->and($published[0]->variable)->toBe($variable)
        ->and($published[0]->mapping)->toBe($mapping);
})->with([
    'a default inside the variable' => ['            - \'${FORWARD_REDIS_PORT:-6379}:6379\'', 'FORWARD_REDIS_PORT', '${FORWARD_REDIS_PORT:-6379}:6379'],
    'a bare variable' => ['            - \'$APP_PORT:80\'', 'APP_PORT', '$APP_PORT:80'],
    'a literal' => ['            - \'8025:8025\'', null, '8025:8025'],
    'a host address in front of it' => ['            - \'127.0.0.1:${APP_PORT:-80}:80\'', 'APP_PORT', '127.0.0.1:${APP_PORT:-80}:80'],
    'an IPv6 one' => ['            - \'[::1]:8080:80\'', null, '[::1]:8080:80'],
    'a protocol behind it' => ['            - \'${FORWARD_DNS_PORT:-53}:53/udp\'', 'FORWARD_DNS_PORT', '${FORWARD_DNS_PORT:-53}:53/udp'],
    'the long syntax' => [
        '            - target: 80'."\n".'              published: \'${APP_PORT:-80}\'',
        'APP_PORT',
        '{ target: 80, published: \'${APP_PORT:-80}\' }',
    ],
    // Nothing to collide over: Docker picks the host port, so every worktree
    // gets one of its own.
    'a container port alone' => ['            - \'3000\'', null, null],
    'a range of them' => ['            - \'3000-3005\'', null, null],
    'a bare number' => ['            - 3000', null, null],
    'a long syntax that publishes nothing' => ['            - target: 80'."\n".'              protocol: tcp', null, null],
]);

it('reads the file the checkout has, and the app service that checkout names', function () {
    $root = temporaryDirectory('worktree-preflight');

    file_put_contents($root.'/docker-compose.yml', "services:\n    app:\n        ports:\n            - '\${APP_PORT:-80}:80'\n");
    file_put_contents($root.'/.env', "APP_SERVICE=app\n");

    $message = refusal(fn () => PublishedPorts::of($root)->verify(configured()));

    expect($message)
        ->toContain("app publishes '\${APP_PORT:-80}:80'")
        ->toContain('started because it is the app service');

    deleteDirectory($root);
});

it('refuses a Compose file it cannot read rather than reading no services out of it', function () {
    expect(fn () => Services::of("services:\n  one:\n   ports:\n  - broken\n"))
        ->toThrow(WorktreeException::class, 'compose.yaml could not be parsed');
});

/**
 * The pre-flight over the Sail-shaped fixture below, for the app service Sail
 * falls back to.
 */
function preflight(string $appService = 'laravel.test'): PublishedPorts
{
    return new PublishedPorts(Services::of(sailCompose()), $appService);
}

/**
 * @param  array<string, scalar|null>  $env
 * @param  list<string>  $keep
 * @param  array<string, list<string>>  $overrides
 * @param  list<array<string, string|bool>>  $steps
 */
function configured(array $env = [], array $keep = [], array $overrides = [], array $steps = []): Configuration
{
    return configurationIn(test()->home, [
        'env' => $env,
        'compose' => ['keep_services' => $keep, 'port_overrides' => $overrides],
        'steps' => $steps,
    ]);
}

/**
 * What every Sail application has to offset before anything else: the two the
 * app service itself publishes.
 *
 * @param  array<string, scalar|null>  $extra
 * @return array<string, scalar|null>
 */
function offsets(array $extra = []): array
{
    return ['APP_PORT' => '{{port.app}}', 'VITE_PORT' => '{{port.vite}}', ...$extra];
}

/**
 * What the pre-flight said when it refused — reported as a failure of its own
 * when it did not refuse at all, because `toThrow` on its own would leave a
 * silently accepted configuration looking like a passing case.
 */
function refusal(Closure $work): string
{
    try {
        $work();
    } catch (WorktreeException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('the pre-flight accepted a configuration that publishes a host port nothing offsets');
}

/**
 * An application shaped like the one `sail install` writes, carrying every case
 * the pre-flight has to tell apart: both `depends_on` spellings, a service
 * reached only through another's, a literal host port, a service that publishes
 * nothing, and one behind a profile.
 */
function sailCompose(): string
{
    return <<<'YAML'
    services:
        laravel.test:
            ports:
                - '${APP_PORT:-80}:80'
                - '${VITE_PORT:-5173}:${VITE_PORT:-5173}'
            depends_on:
                - pgsql
                - meilisearch
        pgsql:
            ports:
                - '${FORWARD_DB_PORT:-5432}:5432'
        redis:
            ports:
                - '${FORWARD_REDIS_PORT:-6379}:6379'
        reverb:
            ports:
                - '${REVERB_PORT:-8080}:8080'
            depends_on:
                redis:
                    condition: service_started
        meilisearch:
            ports:
                - '${FORWARD_MEILISEARCH_PORT:-7700}:7700'
        mailpit:
            ports:
                - '${FORWARD_MAILPIT_PORT:-1025}:1025'
                - '8025:8025'
        selenium:
            image: selenium/standalone-chromium
        typesense:
            profiles:
                - typesense
            ports:
                - '${FORWARD_TYPESENSE_PORT:-8108}:8108'
    YAML;
}
