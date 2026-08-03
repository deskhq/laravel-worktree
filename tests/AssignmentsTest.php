<?php

use DeskHQ\LaravelWorktree\Config\Assignments;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
|
| What a file assigns, as whatever reads it ends up holding. Asserted here
| directly rather than only through the two writers, because this is the class
| every environment file in this package is read through — `EnvFileTest` and
| `OverlayTest` exercise the two questions their own callers ask of it, and
| between them they had never asked what `KEY='a#b'` means.
|
*/

it('reads a value however the file assigns it', function (string $line, ?string $value) {
    expect(Assignments::of($line)->value('SAIL_FILES'))->toBe($value);
})->with([
    'a plain assignment' => ['SAIL_FILES=compose.yaml', 'compose.yaml'],
    'a double-quoted one' => ['SAIL_FILES="compose.yaml"', 'compose.yaml'],
    'a single-quoted one' => ["SAIL_FILES='compose.yaml'", 'compose.yaml'],
    'an exported one' => ['export SAIL_FILES=compose.yaml', 'compose.yaml'],
    'a padded one' => ['  SAIL_FILES = compose.yaml  ', 'compose.yaml'],
    'an empty one' => ['SAIL_FILES=', ''],
    'an empty quoted one' => ['SAIL_FILES=""', ''],
    // `SAIL_FILES=compose.yaml # ours` sets one file, not two words.
    'one with a comment after it' => ['SAIL_FILES=compose.yaml # ours', 'compose.yaml'],
    'one whose value contains a hash' => ['SAIL_FILES="compose.yaml#1"', 'compose.yaml#1'],
    // Only *surrounding* quotes are quotes; a `#` inside them is a value.
    'a quoted value with a comment after it' => ['SAIL_FILES="compose.yaml" # ours', 'compose.yaml'],
    'a key that merely starts the same way' => ['SAIL_FILES_EXTRA=compose.yaml', null],
    'a key the file never assigns' => ['APP_NAME=Desk', null],
    'a key only mentioned in a comment' => ['# SAIL_FILES=compose.yaml', null],
]);

/**
 * Escapes are undone the way both readers of the file do them: phpdotenv reads
 * `\"` and `\$` inside double quotes, and a shell sourcing the same line reads
 * them identically — which is the whole point of writing them.
 */
it('undoes inside double quotes exactly what a shell and phpdotenv undo', function () {
    expect(Assignments::of('SSO_OIDC_SECRET="a\$b\"c\\\\d"')->value('SSO_OIDC_SECRET'))->toBe('a$b"c\\d')
        // Inside single quotes nothing is an escape, which is what makes them
        // the safe quotes and why they are read back as they stand.
        ->and(Assignments::of("SSO_OIDC_SECRET='a\\\$b'")->value('SSO_OIDC_SECRET'))->toBe('a\\$b');
});

/**
 * phpdotenv keeps the first assignment and `source` keeps the last, so the
 * value read is the one a container is actually given: the shell's.
 */
it('reads the last assignment, as a shell sourcing the file is left holding', function () {
    expect(Assignments::of("APP_PORT=80\nAPP_PORT=8080\n")->value('APP_PORT'))->toBe('8080');
});

/*
|--------------------------------------------------------------------------
| Writing
|--------------------------------------------------------------------------
*/

it('replaces the assignment where it stands, comment above it intact', function () {
    $content = Assignments::of(<<<'ENV'
        APP_NAME=Desk

        # The port the application is published on.
        APP_PORT=80
        ENV)->upsert(['APP_PORT' => '20000'], 'wt-desk-441');

    expect((string) $content)->toBe(<<<'ENV'
        APP_NAME=Desk

        # The port the application is published on.
        APP_PORT=20000

        ENV);
});

it('appends what the file does not assign, saying who put it there', function () {
    $content = Assignments::of("APP_NAME=Desk\n")->upsert([
        'APP_PORT' => '20000',
        'COMPOSE_PROJECT_NAME' => 'wt-desk-441',
    ], 'wt-desk-441');

    expect((string) $content)->toBe(<<<'ENV'
        APP_NAME=Desk

        # added by laravel-worktree for wt-desk-441
        APP_PORT=20000
        COMPOSE_PROJECT_NAME=wt-desk-441

        ENV);
});

it('writes a file that had nothing in it at all', function () {
    expect((string) Assignments::of('')->upsert(['APP_PORT' => '20000'], 'wt-desk-441'))->toBe(<<<'ENV'
        # added by laravel-worktree for wt-desk-441
        APP_PORT=20000

        ENV);
});

it('quotes a value only when it has to', function (string $value, string $written) {
    expect((string) Assignments::of('')->upsert(['SECRET' => $value], 'wt-desk-441'))
        ->toContain("SECRET=$written\n");
})->with([
    'a word' => ['20000', '20000'],
    'a url' => ['http://localhost:20000', 'http://localhost:20000'],
    'a path' => ['/Users/x/main-worktrees/441', '/Users/x/main-worktrees/441'],
    'nothing' => ['', ''],
    'something with a space' => ['Desk 441', '"Desk 441"'],
    // `$` unescaped is a variable to both readers, and usually an empty one.
    'something with a dollar' => ['a$b', '"a\$b"'],
    'something with a quote' => ['a"b', '"a\"b"'],
    'something with a backslash' => ['a\\b', '"a\\\\b"'],
    'something with a hash' => ['Desk #441', '"Desk #441"'],
]);

/**
 * The property the quoting exists for, asserted as a round trip rather than as
 * a spelling: whatever is written can be read back, by this class and — as
 * `EnvFileTest` asserts of the generated file — by a shell and by phpdotenv.
 */
it('reads back every value it writes', function (string $value) {
    $written = Assignments::of("APP_NAME=Desk\n")->upsert(['SECRET' => $value], 'wt-desk-441');

    expect(Assignments::of((string) $written)->value('SECRET'))->toBe($value);
})->with([
    'a word' => ['20000'],
    'nothing' => [''],
    'a sentence' => ['Desk 441 #worktree'],
    'shell metacharacters' => ['a$b"c\\d'],
    'a quoted-looking value' => ['"quoted"'],
    'one that starts with a hash' => ['#441'],
]);

it('replaces every occurrence, keeping the padding and the export of each', function () {
    $content = Assignments::of(<<<'ENV'
        export APP_PORT=80
          APP_PORT = 8080
        APP_PORT=9090
        ENV)->upsert(['APP_PORT' => '20000'], 'wt-desk-441');

    expect((string) $content)->toBe(<<<'ENV'
        export APP_PORT=20000
          APP_PORT=20000
        APP_PORT=20000

        ENV);
});

it('leaves a key that merely starts the same way alone', function () {
    expect((string) Assignments::of("APP_PORTAL=80\n")->upsert(['APP_PORT' => '20000'], 'wt-desk-441'))
        ->toBe("APP_PORTAL=80\n\n# added by laravel-worktree for wt-desk-441\nAPP_PORT=20000\n");
});

it('does not touch the file it was not asked to change', function () {
    expect((string) Assignments::of("APP_NAME=Desk\n")->upsert([], 'wt-desk-441'))->toBe("APP_NAME=Desk\n");
});

/*
|--------------------------------------------------------------------------
| The disk
|--------------------------------------------------------------------------
*/

it('reads and writes a file, and names the one it cannot', function () {
    $root = temporaryDirectory('worktree-assignments');

    file_put_contents($root.'/.env', "APP_NAME=Desk\n");

    Assignments::read($root.'/.env')->upsert(['APP_PORT' => '20000'], 'wt-desk-441')->save($root.'/.env.written');

    expect(Assignments::read($root.'/.env.written')->value('APP_PORT'))->toBe('20000')
        ->and(fn () => Assignments::read($root.'/.env.absent'))
        ->toThrow(WorktreeException::class, 'could not read '.$root.'/.env.absent')
        ->and(fn () => Assignments::of('')->save($root.'/nowhere/.env'))
        ->toThrow(WorktreeException::class, 'could not write '.$root.'/nowhere/.env');

    deleteDirectory($root);
});
