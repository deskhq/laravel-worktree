<?php

use DeskHQ\LaravelWorktree\Config\Env;
use DeskHQ\LaravelWorktree\Config\Preference;

it('considers the same names bin/sail does, in its order', function (?string $environment, array $candidates) {
    expect(Preference::of($environment)->candidates())->toBe($candidates);
})->with([
    'nothing exported' => [null, ['.env']],
    'APP_ENV exported' => ['production', ['.env.production', '.env']],
]);

it('reads a directory through the file it has, preferring the environment the shell exported', function (
    array $files,
    ?string $environment,
    string $name,
) {
    $root = preferenceFixture($files);

    expect(Preference::of($environment)->nameIn($root))->toBe($name)
        // The same answer as a path, and null rather than a name when the
        // directory has no environment file at all.
        ->and(Preference::of($environment)->pathIn($root))->toBe(
            array_key_exists($name, $files) ? $root.'/'.$name : null
        );

    deleteDirectory($root);
})->with([
    'only a .env' => [['.env' => ''], null, '.env'],
    'a .env.<APP_ENV> nobody exported' => [['.env' => '', '.env.production' => ''], null, '.env'],
    'a .env.<APP_ENV> the shell exported' => [['.env' => '', '.env.production' => ''], 'production', '.env.production'],
    // The `-f` half of the rule: an exported environment with no file of its
    // own reads `.env`, exactly as the shell falls through to it.
    'an exported environment with no file' => [['.env' => ''], 'production', '.env'],
    // What phpdotenv is given for a repository that has neither: a name it
    // tolerates being unable to open, rather than an example nobody asked for.
    'nothing at all' => [[], null, '.env'],
    'nothing at all, with APP_ENV exported' => [[], 'staging', '.env'],
]);

/**
 * The third candidate, and the whole of why it is a parameter: `EnvFile` means
 * the worktree's example and `AppService::at()` means the checkout's, and the
 * order they reach it by is the same one.
 */
it('falls back to the example of whichever directory the caller names', function () {
    $root = preferenceFixture([]);
    $elsewhere = preferenceFixture(['.env.example' => "APP_SERVICE=desk.test\n"]);

    expect(Preference::of(null)->pathIn($root, exampleIn: $elsewhere))->toBe($elsewhere.'/.env.example')
        // Named nowhere, the example is no candidate at all.
        ->and(Preference::of(null)->pathIn($root))->toBeNull()
        // And a directory with an example of its own is not searched for one
        // unless it was the directory named.
        ->and(Preference::of(null)->pathIn($elsewhere))->toBeNull();

    deleteDirectory($elsewhere);
    deleteDirectory($root);
});

it('reaches the example only after every environment file there is', function (?string $environment, string $expected) {
    $root = preferenceFixture(['.env' => '', '.env.production' => '', '.env.example' => '']);

    expect(Preference::of($environment)->pathIn($root, exampleIn: $root))->toBe($root.'/'.$expected);

    deleteDirectory($root);
})->with([
    'nothing exported' => [null, '.env'],
    'APP_ENV exported' => ['production', '.env.production'],
]);

it('tells an example apart from an environment of the directory\'s own', function () {
    expect(Preference::isExample('/tmp/main-worktrees/441/.env.example'))->toBeTrue()
        ->and(Preference::isExample('/tmp/main/.env'))->toBeFalse()
        ->and(Preference::isExample('/tmp/main/.env.production'))->toBeFalse();
});

/**
 * #38, as a property of the rule rather than of one caller: the `.env` being
 * chosen between is not allowed a vote on which file it is.
 */
it('takes the environment from the shell, never from the file it is choosing', function () {
    $root = preferenceFixture(['.env' => "APP_ENV=production\n", '.env.production' => "APP_ENV=production\n"]);

    expect(Preference::of(null)->nameIn($root))->toBe('.env')
        // And the only environment `exported()` can be built on is the captured
        // one, which is read before any file is loaded over it.
        ->and(Preference::exported()->candidates())->toBe(Preference::of(Env::exportedEnvironment())->candidates());

    deleteDirectory($root);
});

/**
 * A directory carrying $files, each named by the environment file it is.
 *
 * @param  array<string, string>  $files
 */
function preferenceFixture(array $files): string
{
    $root = temporaryDirectory('worktree-preference');

    foreach ($files as $name => $contents) {
        file_put_contents($root.'/'.$name, $contents);
    }

    return $root;
}
