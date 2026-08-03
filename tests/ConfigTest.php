<?php

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use Symfony\Component\Process\Process;

it('runs on documented defaults when the repository ships no config file', function () {
    $root = repositoryWithConfig(null);

    expect(configurationOf($root))->toMatchArray([
        'slots' => 50,
        'portBase' => 20000,
        'portStride' => 10,
        'ports' => ['app', 'vite', 'reverb', 'db', 'redis'],
        'baseBranch' => null,
        'repoSlug' => null,
        'env' => [],
        'compose' => ['keep_services' => [], 'port_overrides' => []],
        'bootTimeout' => 3600,
        'steps' => [],
    ]);

    deleteDirectory($root);
});

it('reads what the repository actually says', function () {
    $root = repositoryWithConfig(<<<'PHP'
        <?php

        return [
            'slots' => 4,
            'port_base' => 30000,
            'port_stride' => 4,
            'ports' => ['app', 'vite'],
            'base_branch' => 'develop',
            'repo_slug' => 'the-desk',
            'env' => ['APP_PORT' => '{{port.app}}', 'REVERB_PORT' => 8080],
            'compose' => ['keep_services' => ['pgsql'], 'port_overrides' => ['reverb' => ['{{port.reverb}}:8080']]],
            'steps' => [
                ['label' => 'Installing', 'sail' => 'composer install', 'sentinel' => '.worktree-installed'],
                ['host' => 'npm ci', 'when' => 'missing:node_modules', 'allow_failure' => true, 'degrade' => 'assets were not built'],
            ],
        ];
        PHP);

    expect(configurationOf($root))->toMatchArray([
        'slots' => 4,
        'portBase' => 30000,
        'portStride' => 4,
        'ports' => ['app', 'vite'],
        'baseBranch' => 'develop',
        'repoSlug' => 'the-desk',
        'env' => ['APP_PORT' => '{{port.app}}', 'REVERB_PORT' => 8080],
        'compose' => ['keep_services' => ['pgsql'], 'port_overrides' => ['reverb' => ['{{port.reverb}}:8080']]],
    ]);

    deleteDirectory($root);
});

/**
 * Every step leaves the validator carrying the limit it will be run under,
 * declared or defaulted, so the recipe the pipeline is handed is already
 * complete — the same reason placeholders are resolved before the first step
 * starts rather than as each one comes up.
 */
it('gives every step a limit, and leaves a step that named its own alone', function () {
    $config = Configuration::fromArray(['step_timeout' => 900, 'steps' => [
        ['host' => 'npm ci'],
        ['host' => 'bin/worktree-playwright', 'timeout' => 300],
        // The one way to ask for what every step used to get.
        ['host' => 'bin/restore-the-snapshot', 'timeout' => null],
    ]]);

    expect(array_map(fn (array $step): ?int => $step['timeout'], $config->steps))->toBe([900, 300, null])
        // And a repository that wants nothing bounded can still say so.
        ->and(Configuration::fromArray(['step_timeout' => null, 'steps' => [['host' => 'npm ci']]])->steps[0]['timeout'])
        ->toBeNull();
});

it('resolves env() through the .env of the main checkout', function () {
    $root = repositoryWithConfig(<<<'PHP'
        <?php

        return ['port_base' => env('WORKTREE_PORT_BASE', 20000)];
        PHP);

    file_put_contents($root.'/.env', "APP_NAME=Worktree\nWORKTREE_PORT_BASE=30000\n");

    expect(configurationOf($root))->toMatchArray(['portBase' => 30000])
        // A variable exported for the run still beats the file it would read.
        ->and(configurationOf($root, ['WORKTREE_PORT_BASE' => '40000']))->toMatchArray(['portBase' => 40000]);

    deleteDirectory($root);
});

it('reads the same values as a booted application reads from the same file', function () {
    $root = repositoryWithConfig(<<<'PHP'
        <?php

        return [
            'slots' => env('WORKTREE_SLOTS', 50),
            'port_base' => env('WORKTREE_PORT_BASE', 20000),
            'base_branch' => env('WORKTREE_BASE'),
            'repo_slug' => env('WORKTREE_REPO_SLUG', 'the-desk'),
        ];
        PHP);

    $environment = ['WORKTREE_PORT_BASE' => '30000', 'WORKTREE_BASE' => 'develop'];

    // `php artisan worktree:list` reads this file with the application booted,
    // through Laravel's own env(); the binary reads it with nothing booted,
    // through the shim. They may not disagree.
    $underArtisan = withEnvironment($environment, fn () => Configuration::fromArray(require $root.'/config/worktree.php'));

    expect(json_decode(json_encode($underArtisan), true))->toBe(configurationOf($root, $environment));

    deleteDirectory($root);
});

it('lets the documented environment overrides beat the file', function () {
    $root = repositoryWithConfig(<<<'PHP'
        <?php

        return ['slots' => 4, 'port_base' => 30000, 'base_branch' => 'develop'];
        PHP);

    expect(configurationOf($root, ['WORKTREE_SLOTS' => '8', 'WORKTREE_PORT_BASE' => '40000', 'WORKTREE_BASE' => 'main']))
        ->toMatchArray(['slots' => 8, 'portBase' => 40000, 'baseBranch' => 'main']);

    deleteDirectory($root);
});

it('keeps the registry in WORKTREE_HOME, or in ~/.laravel-worktree', function () {
    $root = repositoryWithConfig(null);

    expect(configurationOf($root)['home'])->toBe(getenv('HOME').'/.laravel-worktree')
        ->and(configurationOf($root, ['WORKTREE_HOME' => '/tmp/worktrees/'])['home'])->toBe('/tmp/worktrees');

    deleteDirectory($root);
});

it('ships a config file that is itself valid', function () {
    // The published default configures nothing, but it is the first file every
    // consumer opens — one that the validator rejected would be a poor start.
    expect(Configuration::fromArray(require __DIR__.'/../config/worktree.php'))
        ->toBeInstanceOf(Configuration::class);
});

it('ships a worked example that is still legal', function () {
    // Documentation that cannot be executed rots silently, and this example is
    // the one a consumer copies from. Loaded through the same validator the
    // binary uses, so a key that changed shape fails here rather than in
    // somebody else's create.
    $example = Configuration::fromArray(require __DIR__.'/../stubs/worktree-example.php');

    expect($example)->toBeInstanceOf(Configuration::class)
        ->and($example->steps)->not->toBeEmpty()
        ->and($example->compose['keep_services'])->not->toBeEmpty()
        ->and($example->env)->toHaveKey('REVERB_PORT');
});

it("does not mistake the .env's own APP_ENV for one the shell exported", function (array $environment, ?string $exported, string $appEnv) {
    $root = repositoryWithConfig(null);
    file_put_contents($root.'/.env', "APP_ENV=local\nAPP_NAME=Worktree\n");

    $process = new Process(
        [PHP_BINARY, packagePath('tests/Fixtures/reports-the-exported-environment.php'), $root],
        null,
        $environment,
    );
    $process->setTimeout(60);
    $process->run();

    // Almost every Laravel application sets APP_ENV in its .env and exports it
    // from no shell at all. Reading the file's value as the shell's writes the
    // worktree's ports into `.env.local` — a file bin/sail only sources when
    // APP_ENV really is exported, so it sources nothing, every port falls back
    // to its compose.yaml default, and the first worktree collides with the
    // main checkout on `Bind for 0.0.0.0:5432 failed`.
    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['exported' => $exported, 'app_env' => $appEnv]);

    deleteDirectory($root);
})->with([
    'set by the file alone' => [['APP_ENV' => false], null, 'local'],
    'exported over the file' => [['APP_ENV' => 'production'], 'production', 'production'],
]);

it('names the key it does not recognise', function (array $config, string $message) {
    expect(fn () => Configuration::fromArray($config))
        ->toThrow(WorktreeException::class, 'config/worktree.php: '.$message);
})->with([
    'a misspelled top-level key' => [['slot' => 10], "unknown key 'slot' (did you mean 'slots'?)"],
    'an invented top-level key' => [['workers' => 10], "unknown key 'workers'"],
    'a misspelled step key' => [[
        'steps' => [['host' => 'true'], ['sail' => 'artisan migrate', 'sentinal' => '.seeded']],
    ], "steps.1: unknown key 'sentinal' (did you mean 'sentinel'?)"],
    'a misspelled compose key' => [['compose' => ['keep_service' => []]], "compose: unknown key 'keep_service' (did you mean 'keep_services'?)"],
]);

it('refuses a config it cannot act on', function (array $config, string $message) {
    expect(fn () => Configuration::fromArray($config))
        ->toThrow(WorktreeException::class, 'config/worktree.php: '.$message);
})->with([
    'slots that is not a number' => [['slots' => 'plenty'], "slots must be an integer, 'plenty' given"],
    'a port base below the unprivileged range' => [['port_base' => 80], 'port_base must be between 1024 and 65535, 80 given'],
    'a block that runs past the last port' => [['port_base' => 60000, 'slots' => 1000], 'slots (1000) at stride 10 from port_base 60000 runs past 65535'],
    'more ports than the stride can hold' => [['port_stride' => 2], 'ports names 5 ports but port_stride is 2; neighbouring slots would overlap'],
    'a port named twice' => [['ports' => ['app', 'app']], "ports names 'app' twice; each port takes its own offset within a slot"],
    'a repo slug Docker would reject' => [['repo_slug' => 'The Desk'], "repo_slug must be a valid Compose project name ([a-z0-9][a-z0-9_-]*), 'The Desk' given"],
    'an env key no shell could assign' => [['env' => ['MAIL HOST' => '']], "env keys must be variable names; 'MAIL HOST' is not one"],
    'an env value that is not a scalar' => [['env' => ['MAIL_HOST' => ['smtp']]], 'env.MAIL_HOST must be a scalar or null, array given'],
    'a step that runs nothing' => [['steps' => [['label' => 'Nothing']]], 'steps.0 runs nothing; give it one of host, sail, sail_root'],
    'a step that runs two things' => [['steps' => [['host' => 'true', 'sail' => 'true']]], 'steps.0 names host and sail, but a step runs one command; use one of host, sail, sail_root'],
    'a condition the DSL cannot express' => [['steps' => [['host' => 'true', 'when' => 'shell_fails:probe']]], "steps.0.when must be one of missing:, exists:, env_empty:, 'shell_fails:probe' given"],
    'an allow_failure that is not a boolean' => [['steps' => [['host' => 'true', 'allow_failure' => 'yes']]], "steps.0.allow_failure must be true or false, 'yes' given"],
    'a step timeout that is not a number of seconds' => [
        ['steps' => [['host' => 'npm ci', 'timeout' => 'a while']]],
        "steps.0.timeout must be a whole number of seconds, or null for no limit; 'a while' given",
    ],
    // Zero is the shape of "no limit" somebody guessed at, and it would
    // otherwise mean a step that is killed before it has started.
    'a step timeout of zero' => [
        ['steps' => [['host' => 'npm ci', 'timeout' => 0]]],
        'steps.0.timeout must be at least 1 second, or null for no limit; 0 given',
    ],
    'a package default that is not a number of seconds' => [
        ['step_timeout' => 'half an hour'],
        "step_timeout must be a whole number of seconds, or null for no limit; 'half an hour' given",
    ],
    'a negative boot timeout' => [['boot_timeout' => -1], 'boot_timeout must be at least 1 second, or null for no limit; -1 given'],
    // A notice on a step that aborts the bootstrap is a notice nothing ever
    // prints — written, read as covered, and silent.
    'a degrade notice nothing would reach' => [
        ['steps' => [['host' => 'npm run build', 'degrade' => 'assets were not built']]],
        "steps.0.degrade is only printed for a step that was allowed to fail; add 'allow_failure' => true, or drop the message",
    ],
]);

it('says so when the file does not return an array', function () {
    $root = repositoryWithConfig('<?php return "settings";');

    $process = readsConfiguration($root);

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())->toContain('config/worktree.php must return an array, string returned');

    deleteDirectory($root);
});

it('reports a broken config through the binary, before doing any work', function () {
    $repository = temporaryRepository();

    mkdir($repository.'/config');
    file_put_contents($repository.'/config/worktree.php', '<?php return ["slot" => 10];');

    $process = worktree(['create', '441'], cwd: $repository);

    expect($process)->toHaveExited(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain("error: config/worktree.php: unknown key 'slot' (did you mean 'slots'?)")
        ->and($process->getErrorOutput())->not->toContain('not implemented yet');

    deleteDirectory($repository);
});
