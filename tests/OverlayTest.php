<?php

use DeskHQ\LaravelWorktree\Compose\ComposeFile;
use DeskHQ\LaravelWorktree\Compose\ComposeVersion;
use DeskHQ\LaravelWorktree\Compose\Overlay;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Excludes;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

it('trims the app service and remaps the port .env cannot, without touching the application\'s own files', function () {
    [$identity, $root] = composeFixture(files: [
        'compose.override.yaml' => "services:\n    laravel.test:\n        environment:\n            XDEBUG_MODE: debug\n",
    ]);

    $target = overlay($root)->generate($identity, slotPorts(), [
        'keep_services' => ['pgsql', 'redis'],
        'port_overrides' => ['reverb' => ['{{port.reverb}}:8080']],
    ], $identity->path.'/.env');

    expect($target)->toBe($identity->path.'/'.Overlay::File);

    /** @var array{services: array<string, array<string, TaggedValue>>} $overlay */
    $overlay = Yaml::parseFile((string) $target, Yaml::PARSE_CUSTOM_TAGS);
    $dependsOn = $overlay['services']['laravel.test']['depends_on'];
    $ports = $overlay['services']['reverb']['ports'];

    expect($dependsOn)->toBeInstanceOf(TaggedValue::class)
        ->and($dependsOn->getTag())->toBe('override')
        ->and($dependsOn->getValue())->toBe(['pgsql', 'redis'])
        ->and($ports)->toBeInstanceOf(TaggedValue::class)
        ->and($ports->getTag())->toBe('override')
        ->and($ports->getValue())->toBe(['20002:8080'])
        ->and(file_get_contents((string) $target))->toContain('do not edit by hand')
        // Sail is what reaches it, and the application's own file has to lead
        // the list: any -f turns Compose's file discovery off.
        ->and(file_get_contents($identity->path.'/.env'))->toContain("SAIL_FILES=compose.yaml:compose.worktree.yaml\n")
        // The file Compose auto-loads is the application's, and stays the
        // application's — byte for byte.
        ->and(file_get_contents($identity->path.'/compose.override.yaml'))
        ->toBe("services:\n    laravel.test:\n        environment:\n            XDEBUG_MODE: debug\n")
        // And the whole worktree still looks untouched to whoever works in it.
        ->and(trim(runGit($identity->path, 'status', '--porcelain')->getOutput()))->toBe('');

    deleteDirectory($root);
});

it('appends itself to the SAIL_FILES an application already sets', function () {
    [$identity, $root] = composeFixture(env: "APP_NAME=Desk\nSAIL_FILES=compose.yaml:compose.ci.yaml\n");

    $diagnostics = fopen('php://memory', 'w+');

    overlay($root, diagnostics: $diagnostics)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    expect(file_get_contents($identity->path.'/.env'))
        ->toContain("SAIL_FILES=compose.yaml:compose.ci.yaml:compose.worktree.yaml\n")
        ->and(diagnosticsIn($diagnostics))->toContain('this application already sets SAIL_FILES');

    deleteDirectory($root);
});

it('hands Compose the same file once, however many times the worktree is re-entered', function () {
    [$identity, $root] = composeFixture();

    $overlay = overlay($root);

    $overlay->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');
    $overlay->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    $written = (string) file_get_contents($identity->path.'/.env');

    expect(substr_count($written, 'SAIL_FILES='))->toBe(1)
        ->and($written)->toContain("SAIL_FILES=compose.yaml:compose.worktree.yaml\n");

    deleteDirectory($root);
});

it('leads the list with whichever Compose filename the application uses', function (array $present, string $expected) {
    [$identity, $root] = composeFixture(files: array_fill_keys($present, "services: {}\n"), compose: false);

    overlay($root)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    expect(file_get_contents($identity->path.'/.env'))->toContain("SAIL_FILES=$expected:compose.worktree.yaml\n")
        ->and(ComposeFile::in($identity->path))->toBe($expected);

    deleteDirectory($root);
})->with([
    'compose.yaml' => [['compose.yaml'], 'compose.yaml'],
    'compose.yml' => [['compose.yml'], 'compose.yml'],
    'docker-compose.yaml' => [['docker-compose.yaml'], 'docker-compose.yaml'],
    'docker-compose.yml' => [['docker-compose.yml'], 'docker-compose.yml'],
    // Sail's own order decides when an application carries more than one.
    'both of them' => [['docker-compose.yml', 'compose.yml'], 'compose.yml'],
    // Nothing to discover is not an error here: Sail would refuse later, by
    // name, and guessing anything other than the name `sail install` writes
    // would only make that message stranger.
    'none at all' => [[], 'compose.yaml'],
]);

it('keys the overlay on the APP_SERVICE the application configures', function (string $line, string $service) {
    [$identity, $root] = composeFixture(env: "APP_NAME=Desk\n$line\n");

    $target = overlay($root)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    /** @var array{services: array<string, array<string, TaggedValue>>} $overlay */
    $overlay = Yaml::parseFile((string) $target, Yaml::PARSE_CUSTOM_TAGS);

    expect(array_keys($overlay['services']))->toBe([$service])
        ->and($overlay['services'][$service]['depends_on']->getValue())->toBe(['pgsql', 'redis']);

    deleteDirectory($root);
})->with([
    'a plain assignment' => ['APP_SERVICE=app', 'app'],
    'a quoted one' => ["APP_SERVICE='app'", 'app'],
    'an exported one' => ['export APP_SERVICE=app', 'app'],
    'a trailing comment' => ['APP_SERVICE=app # the one this application uses', 'app'],
    // Sail reads `${APP_SERVICE:-"laravel.test"}`, so an empty one is no more
    // set than an absent one.
    'an empty one' => ['APP_SERVICE=', 'laravel.test'],
    'none at all' => ['APP_DEBUG=true', 'laravel.test'],
]);

/**
 * The values behind both of those come out of the file `bin/sail` sources, so
 * they have to mean there what they mean to a shell.
 */
it('reads the SAIL_FILES an application sets however it assigns it', function (string $line, string $expected) {
    [$identity, $root] = composeFixture(env: "APP_NAME=Desk\n$line\n");

    overlay($root)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    expect(file_get_contents($identity->path.'/.env'))->toContain($expected."\n");

    deleteDirectory($root);
})->with([
    'a quoted list' => ['SAIL_FILES="compose.yaml:compose.ci.yaml"', 'SAIL_FILES=compose.yaml:compose.ci.yaml:compose.worktree.yaml'],
    'an exported one' => ['export SAIL_FILES=compose.ci.yaml', 'export SAIL_FILES=compose.ci.yaml:compose.worktree.yaml'],
    'a trailing comment' => ['SAIL_FILES=compose.ci.yaml # just the one', 'SAIL_FILES=compose.ci.yaml:compose.worktree.yaml'],
    // phpdotenv keeps the first assignment and `source` keeps the last; Sail
    // sources, so the last is the one that would reach Compose.
    'the same key twice' => ["SAIL_FILES=compose.a.yaml\nSAIL_FILES=compose.b.yaml", 'SAIL_FILES=compose.b.yaml:compose.worktree.yaml'],
]);

it('gives one service both its trimmed dependencies and its remapped ports', function () {
    [$identity, $root] = composeFixture();

    $target = overlay($root)->generate($identity, slotPorts(), [
        'keep_services' => ['pgsql'],
        'port_overrides' => ['laravel.test' => ['{{port.app}}:80', '{{port.vite}}:{{port.vite}}']],
    ], $identity->path.'/.env');

    /** @var array{services: array<string, array<string, TaggedValue>>} $overlay */
    $overlay = Yaml::parseFile((string) $target, Yaml::PARSE_CUSTOM_TAGS);

    expect($overlay['services']['laravel.test']['depends_on']->getValue())->toBe(['pgsql'])
        ->and($overlay['services']['laravel.test']['ports']->getValue())->toBe(['20000:80', '20001:20001']);

    deleteDirectory($root);
});

it('names the placeholder it cannot resolve, and writes nothing', function () {
    [$identity, $root] = composeFixture();

    expect(fn () => overlay($root)->generate($identity, slotPorts(), [
        'keep_services' => [],
        'port_overrides' => ['soketi' => ['{{port.soketi}}:6001']],
    ], $identity->path.'/.env'))
        ->toThrow(
            WorktreeException::class,
            'config/worktree.php: compose.port_overrides.soketi.0 uses {{port.soketi}}, which is not a port this '
            ."configuration declares; config/worktree.php names app, vite, reverb, db, redis in 'ports'",
        )
        ->and(is_file($identity->path.'/'.Overlay::File))->toBeFalse()
        ->and(file_get_contents($identity->path.'/.env'))->not->toContain('SAIL_FILES');

    deleteDirectory($root);
});

/**
 * The shipped defaults, which have to leave an application exactly as it was:
 * an empty `keep_services` means "as compose.yaml declares it", never "depend
 * on nothing".
 */
it('writes no overlay at all when nothing is overridden', function () {
    [$identity, $root] = composeFixture();

    $target = overlay($root)->generate($identity, slotPorts(), [
        'keep_services' => [],
        'port_overrides' => [],
    ], $identity->path.'/.env');

    expect($target)->toBeNull()
        ->and(is_file($identity->path.'/'.Overlay::File))->toBeFalse()
        ->and(file_get_contents($identity->path.'/.env'))->toBe("APP_NAME=Desk\n");

    deleteDirectory($root);
});

it('refuses before the worktree has an environment file to point at it', function () {
    [$identity, $root] = composeFixture();

    unlink($identity->path.'/.env');

    expect(fn () => overlay($root)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env'))
        ->toThrow(WorktreeException::class, 'cannot write compose.worktree.yaml')
        ->and(is_file($identity->path.'/'.Overlay::File))->toBeFalse();

    deleteDirectory($root);
});

it('excludes the file it generates, so the worktree keeps a clean git status', function () {
    [$identity, $root] = composeFixture();

    $diagnostics = fopen('php://memory', 'w+');

    overlay($root, diagnostics: $diagnostics)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    $exclude = (string) file_get_contents($root.'/main/.git/info/exclude');

    expect(ignoredInGit($identity->path, Overlay::File))->toBeTrue()
        ->and($exclude)->toContain(Excludes::Marker)->toContain(Overlay::File)
        ->and(diagnosticsIn($diagnostics))->toContain('excluding compose.worktree.yaml from git')
        // Written once for the repository — `info/` is a path git keeps in the
        // common directory, so every worktree reaches the same file.
        ->and(substr_count($exclude, Overlay::File))->toBe(1);

    overlay($root)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    expect((string) file_get_contents($root.'/main/.git/info/exclude'))->toBe($exclude);

    deleteDirectory($root);
});

it('leaves the exclude file alone when the application already ignores the overlay', function () {
    [$identity, $root] = composeFixture(files: ['.gitignore' => ".env\n".Overlay::File."\n"]);

    $before = (string) @file_get_contents($root.'/main/.git/info/exclude');

    overlay($root)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    expect((string) @file_get_contents($root.'/main/.git/info/exclude'))->toBe($before)
        ->and(ignoredInGit($identity->path, Overlay::File))->toBeTrue();

    deleteDirectory($root);
});

it('refuses a Docker Compose too old for the merge tag the overlay is written with', function (?string $version, string $message) {
    [$identity, $root] = composeFixture();

    expect(fn () => overlay($root, $version)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env'))
        ->toThrow(WorktreeException::class, $message)
        // The pre-flight is a pre-flight: nothing on disk, nothing in .env.
        ->and(is_file($identity->path.'/'.Overlay::File))->toBeFalse()
        ->and(file_get_contents($identity->path.'/.env'))->not->toContain('SAIL_FILES');

    deleteDirectory($root);
})->with([
    'a Compose that predates !override' => ['2.23.3', "Docker Compose >= 2.24 is required for the '!override' merge tag compose.worktree.yaml is written with (found 2.23.3)"],
    'no Compose v2 at all' => [null, 'Docker Compose v2 is required'],
]);

it('accepts a Compose new enough, or one that will not say', function (string $version) {
    [$identity, $root] = composeFixture();

    $target = overlay($root, $version)->generate($identity, slotPorts(), keepServices(), $identity->path.'/.env');

    expect($target)->toBe($identity->path.'/'.Overlay::File);

    deleteDirectory($root);
})->with([
    'the release that added the tag' => ['2.24.0'],
    'a v-prefixed one, as Compose prints it' => ['v2.31.1'],
    'a build that reports no version we can read' => ['devel'],
]);

/**
 * A repository with a Compose file and a worktree of it — the state a worktree
 * is in when the overlay is generated, git and all, because the generated file
 * has to end up excluded from that repository rather than merely written.
 *
 * @param  array<string, string>  $files  Committed alongside it, path => contents.
 * @param  bool  $compose  Whether to commit a `compose.yaml`; false when $files provides its own.
 * @return array{0: Identity, 1: string} the worktree's identity, and the directory holding the repository
 */
function composeFixture(array $files = [], string $env = "APP_NAME=Desk\n", bool $compose = true): array
{
    $root = temporaryDirectory('worktree-compose');
    $main = $root.'/main';

    mkdir($main, 0755, true);
    runGit($main, 'init', '--quiet', '--initial-branch=main', '.');

    $committed = $files + ['.gitignore' => ".env\n"] + ($compose ? ['compose.yaml' => "services: {}\n"] : []);

    foreach ($committed as $name => $contents) {
        file_put_contents($main.'/'.$name, $contents);
    }

    runGit($main, 'add', '-A');
    runGit($main, 'commit', '--quiet', '-m', 'init');

    $path = $root.'/main-worktrees/441-fix-login';

    runGit($main, 'worktree', 'add', '--quiet', '-b', '441-fix-login', $path);

    file_put_contents($path.'/.env', $env);

    return [new Identity('441', '441-fix-login', 'wt-main-441-fix-login', '441-fix-login', $path), $root];
}

/**
 * The overlay generator, driven against a stub Docker rather than the
 * developer's — the version pre-flight is a case worth asserting, and a machine
 * with no daemon running is not a reason for these to fail.
 *
 * @param  string|null  $composeVersion  What `docker compose version --short` answers; null for a Docker with no v2 at all.
 * @param  resource|null  $diagnostics
 */
function overlay(string $root, ?string $composeVersion = '2.31.0', $diagnostics = null): Overlay
{
    $output = new Output($diagnostics ?? fopen('php://memory', 'w+'));
    $runner = new ProcessRunner($output);

    return new Overlay($output, new ComposeVersion($runner, fakeDocker($root, $composeVersion)), new Excludes($runner, $output));
}

/**
 * A `docker` that answers the one question the pre-flight asks it.
 */
function fakeDocker(string $root, ?string $version): string
{
    $path = $root.'/docker';

    $answer = $version === null
        ? 'exit 1'
        : "if [ \"\$1\" = compose ] && [ \"\$2\" = version ]; then printf '%s\\n' '$version'; exit 0; fi\nexit 1";

    file_put_contents($path, "#!/bin/sh\n$answer\n");
    chmod($path, 0755);

    return $path;
}

/**
 * The commonest configuration there is, and the one most of these cases only
 * need to have written something.
 *
 * @return array{keep_services: list<string>, port_overrides: array<string, list<string>>}
 */
function keepServices(): array
{
    return ['keep_services' => ['pgsql', 'redis'], 'port_overrides' => []];
}
