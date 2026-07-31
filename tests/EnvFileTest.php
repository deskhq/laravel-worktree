<?php

use DeskHQ\LaravelWorktree\Config\EnvFile;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Registry\Ports;
use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Symfony\Component\Process\Process;

it('offsets the ports and names the project, in place', function () {
    [$identity, $mainRoot, $root] = worktreeFixture(<<<'ENV'
        APP_NAME=Desk

        # The port the application is published on.
        APP_PORT=80
        DB_PORT=5432

        ENV);

    $target = envFile($mainRoot)->generate($identity, slotPorts(), [
        'APP_PORT' => '{{port.app}}',
        'COMPOSE_PROJECT_NAME' => '{{project}}',
        'APP_URL' => 'http://localhost:{{port.app}}',
    ]);

    expect($target)->toBe($identity->path.'/.env')
        ->and(file_get_contents($target))->toBe(<<<'ENV'
            APP_NAME=Desk

            # The port the application is published on.
            APP_PORT=20000
            DB_PORT=5432

            # added by laravel-worktree for wt-main-441-fix-login
            COMPOSE_PROJECT_NAME=wt-main-441-fix-login
            APP_URL=http://localhost:20000

            ENV);

    deleteDirectory($root);
});

it('replaces an assignment however the file makes it', function (string $line, string $expected) {
    [$identity, $mainRoot, $root] = worktreeFixture("$line\n");

    envFile($mainRoot)->generate($identity, slotPorts(), ['APP_PORT' => '{{port.app}}']);

    expect(file_get_contents($identity->path.'/.env'))->toBe("$expected\n");

    deleteDirectory($root);
})->with([
    'a plain assignment' => ['APP_PORT=80', 'APP_PORT=20000'],
    'a quoted value' => ['APP_PORT="80"', 'APP_PORT=20000'],
    'an exported one' => ['export APP_PORT=80', 'export APP_PORT=20000'],
    'a padded one' => ['  APP_PORT = 80', '  APP_PORT=20000'],
    // phpdotenv keeps the first, `source` keeps the last: replacing both is the
    // only answer that means the same thing to Sail and to Laravel.
    'the same key twice' => ["APP_PORT=80\nAPP_PORT=8080", "APP_PORT=20000\nAPP_PORT=20000"],
    'a key that merely starts the same way' => ['APP_PORTAL=80', "APP_PORTAL=80\n\n# added by laravel-worktree for wt-main-441-fix-login\nAPP_PORT=20000"],
]);

it('leaves a worktree that already has one exactly as it was', function () {
    [$identity, $mainRoot, $root] = worktreeFixture("APP_PORT=80\n");

    file_put_contents($identity->path.'/.env', "APP_PORT=31337 # debugging this all afternoon\n");

    $diagnostics = fopen('php://memory', 'w+');

    envFile($mainRoot, diagnostics: $diagnostics)->generate($identity, slotPorts(), ['APP_PORT' => '{{port.app}}']);

    expect(file_get_contents($identity->path.'/.env'))->toBe("APP_PORT=31337 # debugging this all afternoon\n")
        ->and(diagnosticsIn($diagnostics))->toContain('keeping the .env already in');

    deleteDirectory($root);
});

it('falls back to the .env.example the worktree carries, and says so', function () {
    [$identity, $mainRoot, $root] = worktreeFixture(example: "APP_NAME=Laravel\nAPP_PORT=80\n");

    $diagnostics = fopen('php://memory', 'w+');

    envFile($mainRoot, diagnostics: $diagnostics)->generate($identity, slotPorts(), ['APP_PORT' => '{{port.app}}']);

    expect(file_get_contents($identity->path.'/.env'))->toBe("APP_NAME=Laravel\nAPP_PORT=20000\n")
        ->and(diagnosticsIn($diagnostics))->toContain('has no .env to copy; starting from')->toContain('.env.example');

    deleteDirectory($root);
});

it('refuses when there is nothing to generate from', function () {
    [$identity, $mainRoot, $root] = worktreeFixture();

    expect(fn () => envFile($mainRoot)->generate($identity, slotPorts(), ['APP_PORT' => '{{port.app}}']))
        ->toThrow(WorktreeException::class, 'nothing to generate .env from');

    deleteDirectory($root);
});

it('resolves every placeholder a worktree has', function () {
    [$identity, $mainRoot, $root] = worktreeFixture("APP_NAME=Desk\n");

    envFile($mainRoot)->generate($identity, slotPorts(), [
        'COMPOSE_PROJECT_NAME' => '{{project}}',
        'WORKTREE_SLUG' => '{{slug}}',
        'WORKTREE_BRANCH' => '{{branch}}',
        'WORKTREE_PATH' => '{{path}}',
        'APP_URL' => 'http://localhost:{{ port.app }}',
        'VITE_PORT' => '{{port.vite}}',
        'FORWARD_DB_PORT' => '{{port.db}}',
    ]);

    expect(parsedEnv($identity->path.'/.env'))->toMatchArray([
        'COMPOSE_PROJECT_NAME' => 'wt-main-441-fix-login',
        'WORKTREE_SLUG' => '441-fix-login',
        'WORKTREE_BRANCH' => '441-fix-login',
        'WORKTREE_PATH' => $identity->path,
        'APP_URL' => 'http://localhost:20000',
        'VITE_PORT' => '20001',
        'FORWARD_DB_PORT' => '20003',
    ]);

    deleteDirectory($root);
});

it('names the placeholder it cannot resolve', function (array $variables, string $message) {
    [$identity, $mainRoot, $root] = worktreeFixture("APP_NAME=Desk\n");

    expect(fn () => envFile($mainRoot)->generate($identity, slotPorts(), $variables))
        ->toThrow(WorktreeException::class, 'config/worktree.php: '.$message)
        // Nothing half-written: the file appears when all of it resolved.
        ->and(is_file($identity->path.'/.env'))->toBeFalse();

    deleteDirectory($root);
})->with([
    'a port nothing declares' => [
        ['FORWARD_MEILISEARCH_PORT' => '{{port.meilisearch}}'],
        "env.FORWARD_MEILISEARCH_PORT uses {{port.meilisearch}}, which is not a port this configuration declares; config/worktree.php names app, vite, reverb, db, redis in 'ports'",
    ],
    'a name that is not a placeholder' => [
        ['APP_URL' => 'http://localhost:{{prot.app}}'],
        'env.APP_URL uses {{prot.app}}, which is not a placeholder; the ones there are {{project}}, {{slug}}, {{branch}}, {{path}} and {{port.<name>}}',
    ],
]);

it('writes values phpdotenv and a shell read back the same way', function () {
    [$identity, $mainRoot, $root] = worktreeFixture("APP_NAME=Desk\n");

    $variables = [
        'MAIL_HOST' => null,
        'REVERB_PORT' => 8080,
        'DEMO_MODE' => false,
        'REGISTRATION_ENABLED' => true,
        'APP_NAME' => 'Desk 441 #worktree',
        'SSO_OIDC_SECRET' => 'a$b"c\\d',
    ];

    envFile($mainRoot)->generate($identity, slotPorts(), $variables);

    expect(parsedEnv($identity->path.'/.env'))->toMatchArray([
        'MAIL_HOST' => '',
        'REVERB_PORT' => '8080',
        'DEMO_MODE' => 'false',
        'REGISTRATION_ENABLED' => 'true',
        'APP_NAME' => 'Desk 441 #worktree',
        'SSO_OIDC_SECRET' => 'a$b"c\\d',
    ])->and(sourcedEnv($identity->path, array_keys($variables)))->toBe([
        'MAIL_HOST' => '',
        'REVERB_PORT' => '8080',
        'DEMO_MODE' => 'false',
        'REGISTRATION_ENABLED' => 'true',
        'APP_NAME' => 'Desk 441 #worktree',
        'SSO_OIDC_SECRET' => 'a$b"c\\d',
    ]);

    deleteDirectory($root);
});

it('writes the file bin/sail will actually source', function (?string $appEnv, string $name) {
    [$identity, $mainRoot, $root] = worktreeFixture("APP_PORT=80\n");

    $diagnostics = fopen('php://memory', 'w+');

    $target = envFile($mainRoot, $appEnv, $diagnostics)->generate($identity, slotPorts(), ['APP_PORT' => '{{port.app}}']);

    expect($target)->toBe($identity->path.'/'.$name)
        // The assertion that matters: run Sail's own selection over the
        // worktree and read back the port it ends up with.
        ->and(sourcedEnv($identity->path, ['APP_PORT'], $appEnv))->toBe(['APP_PORT' => '20000']);

    if ($appEnv !== null) {
        expect(diagnosticsIn($diagnostics))->toContain("APP_ENV=$appEnv is exported")->toContain($name);
    }

    deleteDirectory($root);
})->with([
    'nothing exported' => [null, '.env'],
    'APP_ENV exported' => ['production', '.env.production'],
]);

it('starts from the environment file the main checkout itself is read through', function () {
    [$identity, $mainRoot, $root] = worktreeFixture("APP_NAME=Desk\nAPP_PORT=80\n");

    file_put_contents($mainRoot.'/.env.production', "APP_NAME=Desk in production\nAPP_PORT=80\n");

    envFile($mainRoot, 'production')->generate($identity, slotPorts(), ['APP_PORT' => '{{port.app}}']);

    expect(file_get_contents($identity->path.'/.env.production'))->toBe("APP_NAME=Desk in production\nAPP_PORT=20000\n")
        ->and(is_file($identity->path.'/.env'))->toBeFalse();

    deleteDirectory($root);
});

/**
 * The user's whole point in one test: every port `laravel/sail` can publish is
 * a port a worktree can be given, so any set of services can be started here.
 *
 * Selenium is the only service in Sail's stubs that publishes nothing at all.
 */
it('offsets every host port laravel/sail publishes', function () {
    [$identity, $mainRoot, $root] = worktreeFixture("APP_NAME=Desk\n");

    $services = [
        'app' => 'APP_PORT',
        'vite' => 'VITE_PORT',
        'db' => 'FORWARD_DB_PORT',
        'mongodb' => 'FORWARD_MONGODB_PORT',
        'redis' => 'FORWARD_REDIS_PORT',
        'valkey' => 'FORWARD_VALKEY_PORT',
        'memcached' => 'FORWARD_MEMCACHED_PORT',
        'meilisearch' => 'FORWARD_MEILISEARCH_PORT',
        'typesense' => 'FORWARD_TYPESENSE_PORT',
        'minio' => 'FORWARD_MINIO_PORT',
        'minio_console' => 'FORWARD_MINIO_CONSOLE_PORT',
        'rustfs' => 'FORWARD_RUSTFS_PORT',
        'rustfs_console' => 'FORWARD_RUSTFS_CONSOLE_PORT',
        'mailpit' => 'FORWARD_MAILPIT_PORT',
        'mailpit_dashboard' => 'FORWARD_MAILPIT_DASHBOARD_PORT',
        'rabbitmq' => 'FORWARD_RABBITMQ_PORT',
        'rabbitmq_dashboard' => 'FORWARD_RABBITMQ_DASHBOARD_PORT',
        'soketi' => 'PUSHER_PORT',
        'soketi_metrics' => 'PUSHER_METRICS_PORT',
    ];

    $configuration = configurationIn($root.'/home', [
        'port_base' => 30000,
        'port_stride' => count($services),
        'ports' => array_keys($services),
        'env' => array_map(fn (string $port): string => '{{port.'.$port.'}}', array_flip($services)),
    ]);

    $ports = Ports::fromConfiguration($configuration)->forSlot(2);

    envFile($mainRoot)->generate($identity, $ports, $configuration->env);

    $written = parsedEnv($identity->path.'/.env');
    $assigned = [];

    foreach ($services as $port => $variable) {
        expect($written)->toHaveKey($variable, (string) $ports[$port]);

        $assigned[] = $written[$variable];
    }

    // A slot's block is contiguous and its own: nineteen services, nineteen
    // distinct host ports, none of them shared with the neighbouring slot.
    expect($assigned)->toHaveCount(count(array_unique($assigned)))
        ->and(min($assigned))->toBe((string) (30000 + 2 * count($services)))
        ->and(file_get_contents($identity->path.'/.env'))->not->toContain('{{');

    deleteDirectory($root);
});

/**
 * A main checkout with $env as its `.env`, and a worktree beside it that has
 * $example as its `.env.example`. Either may be absent.
 *
 * @return array{0: Identity, 1: string, 2: string} the worktree's identity, the main root, and the directory holding both
 */
function worktreeFixture(?string $env = null, ?string $example = null): array
{
    $root = temporaryDirectory('worktree-env');
    $path = $root.'/main-worktrees/441-fix-login';

    mkdir($root.'/main', 0755, true);
    mkdir($path, 0755, true);

    if ($env !== null) {
        file_put_contents($root.'/main/.env', $env);
    }

    if ($example !== null) {
        file_put_contents($path.'/'.EnvFile::Example, $example);
    }

    return [
        new Identity('441', '441-fix-login', 'wt-main-441-fix-login', '441-fix-login', $path),
        $root.'/main',
        $root,
    ];
}

/**
 * @param  resource|null  $diagnostics
 */
function envFile(string $mainRoot, ?string $appEnv = null, $diagnostics = null): EnvFile
{
    return new EnvFile(new Output($diagnostics ?? fopen('php://memory', 'w+')), $mainRoot, $appEnv);
}

/**
 * The generated file as phpdotenv reads it — the parser Laravel itself uses.
 *
 * @return array<string, string|null>
 */
function parsedEnv(string $path): array
{
    $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();

    return Dotenv::create($repository, dirname($path), basename($path))->load();
}

/**
 * The generated file as `bin/sail` reads it: the same two lines of shell, run
 * over the worktree, answering with what a container would actually be given.
 *
 * @param  list<string>  $keys
 * @return array<string, string>
 */
function sourcedEnv(string $path, array $keys, ?string $appEnv = null): array
{
    $reads = implode("\n", array_map(fn (string $key): string => "printf '%s\\n' \"\${$key}\"", $keys));

    $process = new Process(['bash', '-c', <<<BASH
        if [ -n "\$APP_ENV" ] && [ -f ./.env."\$APP_ENV" ]; then
          source ./.env."\$APP_ENV";
        elif [ -f ./.env ]; then
          source ./.env;
        fi
        $reads
        BASH], $path, ['APP_ENV' => $appEnv]);

    $process->mustRun();

    return array_combine($keys, explode("\n", rtrim($process->getOutput(), "\n")));
}
