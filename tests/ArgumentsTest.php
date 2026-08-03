<?php

use DeskHQ\LaravelWorktree\Console\Arguments;
use DeskHQ\LaravelWorktree\Console\Arity;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;

/**
 * The one layer every command parses through, tested without one.
 *
 * Each command's own suite proves that being called wrong exits 64 with its
 * sentence on stderr, which costs a subprocess and a repository apiece. What
 * the arity *is* — how many, and whether the first one has to be there — is
 * pure computation, so it is proved here at the cost of a function call, and
 * the expensive cases stay the handful that prove the wiring.
 */
it('takes the flags a command declares, and refuses the ones it does not', function () {
    $takes = Arity::nameAndBase('create');

    $invocation = Arguments::parse(['441', '--refresh'], ['refresh', 'json'], takes: $takes);

    expect($invocation->at(0))->toBe('441')
        ->and($invocation->has('refresh'))->toBeTrue()
        ->and($invocation->has('json'))->toBeFalse();

    expect(fn () => Arguments::parse(['441', '--refesh'], ['refresh', 'json'], takes: $takes))
        ->toThrow(UsageException::class, "unknown option '--refesh'; this command takes --refresh, --json");
});

it('hands back the positionals its arity allows, in the order they were given', function () {
    $invocation = Arguments::parse(['441', 'main'], [], takes: Arity::nameAndBase('create'));

    expect($invocation->positional)->toBe(['441', 'main'])
        ->and($invocation->at(0))->toBe('441')
        ->and($invocation->at(2))->toBeNull();
});

it('refuses a positional given to a command that takes only options', function () {
    $takes = Arity::options('doctor');

    expect(fn () => Arguments::parse(['441'], ['json'], takes: $takes))
        ->toThrow(UsageException::class, 'doctor takes no arguments, only options; given 441');

    // An empty one is still one: `doctor "$ISSUE"` typed at a command that has
    // no use for a name is the same mistake whatever `$ISSUE` held.
    expect(fn () => Arguments::parse([''], ['json'], takes: $takes))
        ->toThrow(UsageException::class, 'doctor takes no arguments, only options; given ');

    expect(Arguments::parse(['--json'], ['json'], takes: $takes)->positional)->toBe([]);
});

it('refuses a second name, and says the command and both of them', function () {
    expect(fn () => Arguments::parse(['441', 'main'], [], takes: Arity::name('path', 'to look up')))
        ->toThrow(UsageException::class, 'path takes one name; given 441 main');

    expect(fn () => Arguments::parse(['feat/checkout', 'extra'], [], takes: Arity::optional('stop')))
        ->toThrow(UsageException::class, 'stop takes one name; given feat/checkout extra');

    // The noun is the command's, for the one that takes something other than a
    // worktree name.
    expect(fn () => Arguments::parse(['bash', 'zsh'], [], takes: Arity::optional('shell-init', 'shell')))
        ->toThrow(UsageException::class, 'shell-init takes one shell; given bash zsh');
});

it('refuses a third positional to the one command that takes two', function () {
    expect(fn () => Arguments::parse(['441', 'main', 'extra'], [], takes: Arity::nameAndBase('create')))
        ->toThrow(UsageException::class, 'create takes a name and, at most, a base to fork from; given 441 main extra');

    expect(Arguments::parse(['441', 'main'], [], takes: Arity::nameAndBase('create'))->at(1))->toBe('main');
});

/**
 * `create "$ISSUE"` with nothing in `$ISSUE` is not a worktree named the empty
 * string: it is the command called without a name, and gets that answer rather
 * than the operational failure the naming layer would report a moment later.
 */
it('treats a name that is blank as no name at all', function (string $given) {
    expect(fn () => Arguments::parse([$given], [], takes: Arity::name('start', 'to start')))
        ->toThrow(UsageException::class, 'name the worktree to start: an issue number, or a branch name');
})->with([
    'nothing in it' => [''],
    'whitespace' => ['   '],
]);

it('says what the name is wanted for', function () {
    expect(fn () => Arguments::parse([], [], takes: Arity::name('remove', 'to remove')))
        ->toThrow(UsageException::class, 'name the worktree to remove: an issue number, or a branch name');
});

/**
 * `stop --all` and `unlock --all` name nothing on purpose, and `shell-init`
 * reads `$SHELL` when it is given nothing. What may stand in for the name is a
 * flag, so the command that owns the flag owns that refusal too.
 */
it('lets a command whose name is optional run without one', function () {
    expect(Arguments::parse(['--all'], ['all'], takes: Arity::optional('stop'))->at(0))->toBeNull()
        ->and(Arguments::parse([], [], takes: Arity::optional('shell-init', 'shell'))->at(0))->toBeNull()
        ->and(Arguments::parse([], [], takes: Arity::nameAndBase('create'))->at(0))->toBeNull();
});

/**
 * A mistyped option is the one that would have changed what the run did, so it
 * is the one worth saying first.
 */
it('answers a mistyped option before it counts the positionals', function () {
    expect(fn () => Arguments::parse(['441', 'main', '--jsn'], ['json'], takes: Arity::name('path', 'to look up')))
        ->toThrow(UsageException::class, "unknown option '--jsn'");
});
