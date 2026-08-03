<?php

use DeskHQ\LaravelWorktree\Compose\PublishedPorts;
use DeskHQ\LaravelWorktree\Compose\Services;
use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\Derivation;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Config\Stencil;
use Symfony\Component\Yaml\Yaml;

/**
 * `worktree init`, which writes `config/worktree.php` from the repository's own
 * `compose.yaml` (#58).
 *
 * Everything here runs without a Docker daemon and without a container, because
 * the whole generation is the Compose file read with the parser the pre-flight
 * already uses. That is the claim worth pinning: this is not a second
 * implementation of the rule that refuses a create, it is the same walk over the
 * same file spent on a configuration instead of on an error message — so the
 * strongest case below is the one that hands what was generated straight back to
 * the pre-flight it came from.
 */
beforeEach(function () {
    harness('worktree-init');

    $this->main = $this->root.'/desk';
    $this->base = freePortBase(100);

    mainCheckout($this->main);

    file_put_contents($this->main.'/compose.yaml', sailCompose());
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('derives the ports, the offsets and the trimmed depends_on from the file', function () {
    $derived = derivation();

    expect(array_keys($derived->started))->toBe(['laravel.test', 'pgsql', 'meilisearch'])
        // In the order the closure reaches them: the app service's own two
        // mappings, then what it depends on.
        ->and($derived->ports)->toBe(['app', 'vite', 'db', 'meilisearch'])
        ->and($derived->env)->toBe([
            'APP_PORT' => '{{port.app}}',
            'VITE_PORT' => '{{port.vite}}',
            'FORWARD_DB_PORT' => '{{port.db}}',
            'FORWARD_MEILISEARCH_PORT' => '{{port.meilisearch}}',
            'COMPOSE_PROJECT_NAME' => '{{project}}',
            'APP_URL' => 'http://localhost:{{port.app}}',
        ])
        // Seeded from what the app service actually depends on, which is what
        // makes a worktree start those services and no others.
        ->and($derived->keepServices)->toBe(['pgsql', 'meilisearch'])
        ->and($derived->portOverrides)->toBe([])
        // The services this application declares and never starts are in none
        // of it.
        ->and(implode(' ', $derived->ports))->not->toContain('mailpit')
        ->not->toContain('typesense');
});

/**
 * Trap 1, generated rather than explained. `REVERB_PORT` is the host side of
 * `'${REVERB_PORT:-8080}:8080'` *and* the port the application dials at
 * `reverb:<port>`, so offsetting it in `.env` points the broadcaster at nothing:
 * the fix is to pin the inner value and remap the published side in the overlay,
 * and that is a pair of entries rather than one.
 */
it('pins a trap-1 variable and remaps its published side, rather than offsetting it', function () {
    $derived = derivation(['reverb']);

    expect($derived->env['REVERB_PORT'])->toBe(8080)
        ->and($derived->pinned)->toBe(['REVERB_PORT' => 8080])
        ->and($derived->portOverrides)->toBe(['reverb' => ['{{port.reverb}}:8080']])
        // And trap 2 alongside it: redis is in nothing the application wrote,
        // and publishes a port the second worktree would collide on.
        ->and($derived->env['FORWARD_REDIS_PORT'])->toBe('{{port.redis}}')
        ->and($derived->ports)->toBe(['app', 'vite', 'reverb', 'redis']);
});

/**
 * A literal host port has no variable for `env` to assign, so an `env` key would
 * be a line that does nothing. It gets the one entry that can move it — and
 * because `!override` replaces a service's whole `ports:` list, so does every
 * other mapping that service publishes.
 */
it('gives a literal host port an override entry, and no env key that would do nothing', function () {
    $derived = derivation(['mailpit']);

    expect($derived->portOverrides)->toBe([
        'mailpit' => ['{{port.mailpit}}:1025', '{{port.mailpit_8025}}:8025'],
    ])
        ->and($derived->ports)->toBe(['app', 'vite', 'mailpit', 'mailpit_8025'])
        ->and($derived->env)->not->toHaveKey('FORWARD_MAILPIT_PORT');
});

it('names a port the way the refusal asks for it to be named', function () {
    $message = null;

    try {
        PublishedPorts::of($this->main)->verify(configurationIn($this->home));
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    // The pre-flight's sentence and the generated file have to contain the same
    // name, or somebody follows the first and cannot find it in the second.
    expect($message)->toContain("add 'meilisearch' to 'ports', and 'FORWARD_MEILISEARCH_PORT' => '{{port.meilisearch}}'")
        ->and(derivation()->ports)->toContain('meilisearch');
});

it('keeps port_stride at least as large as the list of ports it wrote', function () {
    $services = ['app' => ['ports' => []]];

    for ($index = 0; $index < 12; $index++) {
        $services['app']['ports'][] = '${FORWARD_S'.$index.'_PORT:-100'.$index.'}:100'.$index;
    }

    $derived = Derivation::of(Services::of(Yaml::dump(['services' => $services], 6)), 'app');

    expect($derived->ports)->toHaveCount(12)
        ->and($derived->portStride)->toBe(12)
        // Which is the rule Schema would otherwise refuse the file over.
        ->and(fn () => configurationIn($this->home, $derived->toArray()))->not->toThrow(Throwable::class);
});

it('gives two mappings of the same variable one port between them', function () {
    $yaml = <<<'YAML'
    services:
        app:
            ports:
                - '${FORWARD_DB_PORT:-5432}:5432'
            depends_on: [replica]
        replica:
            ports:
                - '${FORWARD_DB_PORT:-5432}:5432'
    YAML;

    $derived = Derivation::of(Services::of($yaml), 'app');

    expect($derived->ports)->toBe(['db'])
        ->and($derived->env['FORWARD_DB_PORT'])->toBe('{{port.db}}');
});

it('names a literal after the service that publishes it, in a form ports accepts', function () {
    $derived = Derivation::of(Services::of("services:\n    laravel.test:\n        ports:\n            - '8025:8025'\n"), 'laravel.test');

    expect($derived->ports)->toBe(['laravel_test'])
        ->and($derived->portOverrides)->toBe(['laravel.test' => ['{{port.laravel_test}}:8025']])
        ->and($derived->env['APP_URL'])->toBe('http://localhost:{{port.laravel_test}}');
});

/**
 * The values are the repository's; the explanations are the package's. A
 * generated file that dropped them would be an array somebody could not edit
 * without going back to the README — and the comments are the part of that file
 * nobody can re-derive.
 */
it('carries every comment block of the published config into what it generates', function () {
    $published = (string) file_get_contents(packagePath(Schema::File));
    $generated = Stencil::render(derivation(['reverb']));

    preg_match_all('/\/\*(?:.*?)\*\//s', $published, $blocks);

    expect($blocks[0])->not->toBeEmpty();

    foreach ($blocks[0] as $block) {
        expect($generated)->toContain($block);
    }

    expect($generated)
        // And the one block that is not the published file's: what this run
        // could not know, said where the file it wrote will be read.
        ->toContain('Generated by `worktree init` from compose.yaml')
        ->toContain('laravel.test, reverb, redis')
        ->toContain('`steps` is empty')
        ->toContain('is invisible')
        // The recipe is left exactly as published: empty, with its examples.
        ->toContain("'steps' => [],");
});

/**
 * The acceptance criterion that matters most, and the loop this closes: what
 * `init` writes is what `create` would have refused to run without.
 */
it('generates a configuration that passes the pre-flight it was derived from', function (array $dependsOn) {
    $root = $this->root.'/generated';

    mkdir($root.'/config', 0755, true);
    file_put_contents($root.'/compose.yaml', sailComposeDependingOn($dependsOn));

    $derived = Derivation::at($root);

    file_put_contents($root.'/'.Schema::File, Stencil::render($derived));

    // Loaded the way the binary loads it — `require`d in a process with no
    // application booted — rather than trusted as the array it was built from.
    $config = Configuration::load($root);

    expect($config->ports)->toBe($derived->ports)
        ->and($config->env)->toBe($derived->env)
        ->and($config->compose['port_overrides'])->toBe($derived->portOverrides)
        ->and(PublishedPorts::of($root)->problem($config))->toBeNull();
})->with([
    'the services the app service declares' => [['pgsql', 'meilisearch']],
    'a trap-1 service, and what it drags in' => [['pgsql', 'reverb']],
    'a literal host port' => [['mailpit']],
    'all of them at once' => [['pgsql', 'redis', 'reverb', 'meilisearch', 'mailpit', 'selenium']],
    'an app service that depends on nothing' => [[]],
]);

/*
|--------------------------------------------------------------------------
| Through the binary
|--------------------------------------------------------------------------
*/

it('writes the file, prints where it went, and says what it derived', function () {
    $process = worktreeInit();

    expect($process)->toHaveSucceeded()
        // The path on stdout, where every answer a script reads goes.
        ->and(trim($process->getOutput()))->toBe($this->main.'/'.Schema::File)
        ->and($process->getErrorOutput())
        ->toContain('worktree init — '.$this->main)
        ->toContain('laravel.test, pgsql, meilisearch')
        ->toContain('app, vite, db, meilisearch')
        ->toContain('none — the bootstrap recipe is the one part nothing can derive')
        ->and(file_get_contents($this->main.'/'.Schema::File))
        ->toContain("'ports' => ['app', 'vite', 'db', 'meilisearch'],");
});

it('makes a config directory that is not there yet', function () {
    expect(is_dir($this->main.'/config'))->toBeFalse();

    expect(worktreeInit())->toHaveSucceeded()
        ->and(is_file($this->main.'/'.Schema::File))->toBeTrue();
});

it('refuses to overwrite a config that is already there, and changes nothing when it does', function () {
    expect(worktreeInit())->toHaveSucceeded();

    $written = (string) file_get_contents($this->main.'/'.Schema::File);

    $process = worktreeInit();

    expect($process)->toHaveExited(1)
        ->and($process->getErrorOutput())
        ->toContain(Schema::File.' is already there')
        ->toContain("'steps' recipe it carries, which nothing derived can reproduce")
        ->toContain('--dry-run')
        ->toContain('--force')
        ->and(file_get_contents($this->main.'/'.Schema::File))->toBe($written);
});

it('replaces it when told to', function () {
    file_put_contents($this->main.'/compose.yaml', sailComposeDependingOn(['redis']));

    mkdir($this->main.'/config', 0755, true);
    file_put_contents($this->main.'/'.Schema::File, "<?php return ['ports' => ['app']];\n");

    $process = worktreeInit(['--force']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain(Schema::File.' replaced')
        ->and(file_get_contents($this->main.'/'.Schema::File))
        ->toContain("'ports' => ['app', 'vite', 'redis'],");
});

it('prints what it would write, and writes nothing, under --dry-run', function () {
    $process = worktreeInit(['--dry-run']);

    expect($process)->toHaveSucceeded()
        ->and(is_file($this->main.'/'.Schema::File))->toBeFalse()
        ->and($process->getOutput())
        ->toContain('<?php')
        ->toContain("'ports' => ['app', 'vite', 'db', 'meilisearch'],")
        ->and($process->getErrorOutput())->toContain('nothing was written');
});

/**
 * `--dry-run` is the run after the first, so it has to work when the file it
 * would replace is already there — that is the whole of what makes the two
 * diffable.
 */
it('prints rather than refuses when the file is already there', function () {
    expect(worktreeInit())->toHaveSucceeded();

    $process = worktreeInit(['--dry-run']);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toContain('<?php');
});

/**
 * A `config/worktree.php` that will not load is a reason to generate one, not a
 * reason to refuse — so `init` declines the configuration every other command is
 * handed, exactly as `doctor` does.
 */
it('runs against a repository whose configuration does not load', function () {
    mkdir($this->main.'/config', 0755, true);
    file_put_contents($this->main.'/'.Schema::File, "<?php return ['portz' => 10];\n");

    $process = worktreeInit(['--force']);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toContain("unknown key 'portz'")
        ->and(file_get_contents($this->main.'/'.Schema::File))->toContain("'ports' => ['app', 'vite', 'db', 'meilisearch'],");
});

it('takes options rather than arguments', function () {
    $process = worktreeInit(['441']);

    expect($process)->toHaveExited(64)
        ->and($process->getErrorOutput())->toContain('init takes no arguments, only options; given 441');
});

/**
 * The end of the loop, through both binaries' worth of real code: a repository
 * with no configuration at all, `init`, and then the command whose job is to say
 * whether a create would work here.
 */
it('generates a config that doctor then passes on the published ports', function () {
    file_put_contents($this->main.'/compose.yaml', sailComposeDependingOn(['pgsql', 'reverb', 'mailpit']));

    expect(worktreeInit())->toHaveSucceeded();

    $doctor = worktreeDoctor(['--json']);
    $verdicts = [];

    foreach (json_decode($doctor->getOutput(), true, flags: JSON_THROW_ON_ERROR)['checks'] as $check) {
        $verdicts[$check['name']] = $check['verdict'];
    }

    expect($verdicts)->toMatchArray(['config' => 'ok', 'ports' => 'ok']);
});

/**
 * The derivation for the fixture application, with the app service depending on
 * whichever of its services a case is about — which is what decides the closure,
 * and therefore everything generated from it.
 *
 * @param  list<string>|null  $dependsOn  Null leaves the fixture's own depends_on alone.
 */
function derivation(?array $dependsOn = null): Derivation
{
    $yaml = $dependsOn === null ? sailCompose() : sailComposeDependingOn($dependsOn);

    return Derivation::of(Services::of($yaml), 'laravel.test');
}

/**
 * @param  list<string>  $dependsOn
 */
function sailComposeDependingOn(array $dependsOn): string
{
    /** @var array{services: array<string, array<string, mixed>>} $parsed */
    $parsed = Yaml::parse(sailCompose());

    $parsed['services']['laravel.test']['depends_on'] = $dependsOn;

    return Yaml::dump($parsed, 6);
}
