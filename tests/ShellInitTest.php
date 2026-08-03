<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `worktree shell-init`, and the script it prints (#60).
 *
 * The cases below run the emitted text in a real `bash` and a real `zsh`,
 * because that is the only thing worth asserting about it: a shell script that
 * is *inspected* rather than executed is a shell script whose `local status=$?`
 * dies on zsh — which this one did, once, since zsh keeps that name for itself.
 *
 * Each session sources the script the binary just printed and then runs a line
 * or two, with no rc files anywhere near it: what is under test is this script,
 * not the developer's shell.
 *
 * The two claims the feature rests on are the ones the cases spend the most
 * effort on. `wt` on a slug that resolves to nothing must not create anything —
 * a `cd` that can start a five-minute bootstrap on a typo is the trap the whole
 * design avoids. And completion must not go through the binary: it reads the
 * registry file directly, which is asserted by pointing the shim at a binary
 * that does not exist and completing anyway.
 */
beforeEach(function () {
    harness('worktree-shell-init');

    $this->main = mainCheckout($this->root.'/desk');
    $this->shop = mainCheckout($this->root.'/shop');
    $this->elsewhere = $this->root.'/elsewhere';

    mkdir($this->elsewhere, 0755, true);
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('prints a script on stdout, and nothing at all on stderr', function (string $shell, string $registration) {
    $process = worktreeShellInit([$shell]);

    expect($process)->toHaveSucceeded()
        // Not a word of prose, deliberately: the ordinary way to run this is
        // from an rc file, and a note on stderr is a note on every new terminal
        // for the rest of the machine's life.
        ->and($process->getErrorOutput())->toBe('')
        ->and($process->getOutput())
        ->toContain('wt() {')
        ->toContain($registration);
})->with([
    'bash' => ['bash', 'complete -F __worktree_complete worktree'],
    'zsh' => ['zsh', 'compdef __worktree_complete worktree'],
]);

it('writes for the shell that is running when it is told none', function (string $login, string $registration) {
    $process = worktreeShellInit(env: ['SHELL' => $login]);

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toContain($registration);
})->with([
    'zsh as a login shell' => ['/bin/zsh', 'compdef __worktree_complete worktree'],
    'bash somewhere else' => ['/opt/homebrew/bin/bash', 'complete -F __worktree_complete worktree'],
]);

it('refuses a shell it has no script for, rather than emitting one that half works', function (array $arguments, array $env) {
    $process = worktreeShellInit($arguments, env: $env);

    expect($process)->toHaveExited(64)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('bash and zsh');
})->with([
    'named' => [['fish'], []],
    'inherited from $SHELL' => [[], ['SHELL' => '/usr/local/bin/fish']],
]);

it('takes one shell', function () {
    $process = worktreeShellInit(['bash', 'zsh']);

    expect($process)->toHaveExited(64)
        ->and($process->getErrorOutput())->toContain('shell-init takes one shell; given bash zsh');
});

/**
 * The case the command exists for: an rc file runs in `~`, which is in no
 * repository, and a `not inside a git repository` on every new terminal would be
 * this package failing at the one moment it is supposed to be invisible.
 */
it('emits from outside a git repository, where an rc file runs it', function () {
    $process = worktreeShellInit(['bash'], cwd: $this->elsewhere);

    expect($process)->toHaveSucceeded()
        ->and($process->getErrorOutput())->toBe('')
        ->and($process->getOutput())->toContain('wt() {');
});

/**
 * Generated rather than shipped, and this is what that buys: the script is
 * written out of the commands the binary dispatches, so a command or a flag
 * added later completes without anybody maintaining a second list of them.
 */
it('names every command the binary dispatches, and the flags each one takes', function () {
    $script = worktreeShellInit(['bash'])->getOutput();
    $usage = worktree(['--help'])->getErrorOutput();

    foreach (['create', 'path', 'list', 'stop', 'start', 'remove', 'reap', 'unlock', 'doctor', 'init', 'shell-init'] as $command) {
        expect($script)->toContain($command)
            ->and($usage)->toContain($command);
    }

    expect($script)
        ->toContain("create) printf '%s\\n' --pr --refresh --json")
        ->toContain("stop) printf '%s\\n' --all --all-except --all-repos")
        ->toContain("reap) printf '%s\\n' --all --dry-run --yes")
        // The commands whose argument is a worktree, which is the completion
        // worth having: those names are long and generated from issue titles.
        ->toContain('create|path|stop|start|remove|unlock) __worktree_slugs')
        // And the one whose argument is a choice between literals.
        ->toContain("shell-init) printf '%s\\n' bash zsh");
});

it('cds into the worktree a slug names', function (string $argument, string $slug) {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout', branch: 'feat/checkout'),
    ]);

    $session = shellSession('bash', "wt $argument\nprintf '%s\\n' \"\$PWD\"");

    expect($session)->toHaveSucceeded()
        ->and(trim($session->getOutput()))->toBe($this->root.'/desk-worktrees/'.$slug);
})->with([
    'by the number the issue has' => ['441', '441-fix-login'],
    'by the slug itself' => ['441-fix-login', '441-fix-login'],
    'by the branch it is on' => ['feat/checkout', 'feat-checkout'],
]);

/**
 * The whole reason `wt` asks `path` and not `create`. A typo costs an exit code
 * and a table, rather than a git worktree, a slot, and five minutes of Docker.
 */
it('creates nothing for a slug that resolves to nothing, and shows what there is instead', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $registry = registryNow();
    $session = shellSession('bash', "wt 442\nprintf 'wt=%s cd=%s\\n' \"\$?\" \"\$PWD\"");

    expect($session->getErrorOutput())->toContain("is registered as '442'")
        // The list, which is the AC — and the failure, and the directory
        // unchanged, which are the point of the command it asked rather than
        // the one it did not.
        ->and($session->getOutput())->toContain('wt-desk-441-fix-login')
        ->toContain('wt=1 cd='.$this->main)
        ->and(registryNow())->toBe($registry)
        ->and(is_dir($this->root.'/desk-worktrees/442'))->toBeFalse()
        // The daemon is asked what it is holding, because the list that follows
        // is a list; nothing is ever started, torn down or bootstrapped.
        ->and(array_values(array_filter(
            dockerCalls(),
            fn (string $call): bool => ! str_starts_with($call, 'info') && ! str_contains($call, '--filter'),
        )))->toBe([]);
});

/**
 * The other half of that: creating is available, and it is available by asking
 * for it. `--create` is the one path through this function that writes anything.
 */
it('creates a worktree and cds into it when it is asked to', function () {
    $this->base = freePortBase(100);

    configureRepository();

    $session = shellSession('bash', "wt --create feat/checkout\nprintf '%s\\n' \"\$PWD\"");

    expect($session)->toHaveSucceeded()
        ->and(trim($session->getOutput()))->toBe($this->root.'/desk-worktrees/feat-checkout')
        ->and(registryNow())->toHaveKey('wt-desk-feat-checkout');
});

it('prints the list when it is given nothing at all', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $session = shellSession('bash', 'wt');

    expect($session)->toHaveSucceeded()
        ->and($session->getOutput())->toContain('wt-desk-441-fix-login');
});

/**
 * It is a `cd`, not a second command line: an option it does not know is refused
 * by name rather than passed through, because the moment it forwards one it owns
 * a copy of the binary's argument parsing.
 */
it('refuses to become a second command line', function () {
    $session = shellSession('bash', 'wt --json');

    expect($session)->toHaveExited(64)
        ->and($session->getErrorOutput())->toContain("run 'worktree' itself for --json");
});

/**
 * The binary is named absolutely in the script, so the failure from `~` is this
 * package's own message rather than `command not found` — which is the whole
 * reason the function resolves the binary instead of trusting `PATH`.
 */
it('fails from outside a repository with the package own message', function () {
    $session = shellSession('bash', 'wt 441', cwd: $this->elsewhere);

    expect($session)->toHaveFailed()
        ->and($session->getErrorOutput())
        ->toContain('error: not inside a git repository')
        ->not->toContain('command not found')
        ->and($session->getOutput())->toBe('');
});

it('says where the binary went when it is no longer there', function () {
    $session = shellSession('bash', 'wt 441', env: ['WORKTREE_BIN' => $this->root.'/moved-away']);

    expect($session)->toHaveFailed()
        ->and($session->getErrorOutput())->toContain('re-run its shell-init');
});

it('completes command names, flags, and the slugs the registry holds', function (array $words, array $expected) {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-desk-feat-checkout' => slotEntry(1, 'feat-checkout', branch: 'feat/checkout'),
    ]);

    expect(bashCompletion($words))->toBe($expected);
})->with([
    'the commands, on an empty line' => [['worktree', ''], ['create', 'path', 'list', 'stop', 'start', 'remove', 'reap', 'unlock', 'doctor', 'init', 'shell-init']],
    'the command a prefix begins' => [['worktree', 'pa'], ['path']],
    'the slugs a command takes' => [['worktree', 'path', ''], ['441-fix-login', 'feat-checkout']],
    'the slug a prefix begins' => [['worktree', 'path', '44'], ['441-fix-login']],
    'the flags of that command' => [['worktree', 'path', '--'], ['--json']],
    'the slug an option takes' => [['worktree', 'stop', '--all-except', ''], ['441-fix-login', 'feat-checkout']],
    'the choice between literals' => [['worktree', 'shell-init', ''], ['bash', 'zsh']],
    // Nothing, rather than a wrong answer: the second argument of `create` is a
    // git ref, and the commands that take no argument take none.
    'nothing where a base ref goes' => [['worktree', 'create', '441-fix-login', ''], []],
    'nothing for a command with no argument' => [['worktree', 'list', ''], []],
]);

it('completes the slugs of the repository the cursor is in, out of a machine-global registry', function () {
    registryHolds([
        'wt-desk-441-fix-login' => slotEntry(0, '441-fix-login'),
        'wt-shop-99-cart' => slotEntry(1, '99-cart', repo: $this->shop),
    ]);

    expect(bashCompletion(['worktree', 'path', '']))->toBe(['441-fix-login'])
        ->and(bashCompletion(['worktree', 'path', ''], cwd: $this->shop))->toBe(['99-cart'])
        // From no repository at all there is nothing to scope by, so everything
        // is offered rather than nothing.
        ->and(bashCompletion(['worktree', 'path', ''], cwd: $this->elsewhere))->toBe(['441-fix-login', '99-cart']);
});

it('completes worktrees for wt itself, which is the only thing it takes', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    expect(bashCompletion(['wt', ''], function: '__worktree_complete_wt'))->toBe(['441-fix-login', '--create']);
});

/**
 * The performance claim, asserted as a structural one: completion reads
 * `registry.json` and never the binary, so it still answers when the binary is
 * not there at all. A round trip through PHP, an anchor, a config read and a
 * daemon query on every press of the Tab key is the completion people turn off.
 */
it('takes its slugs from the registry file rather than from the binary', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $session = shellSession('bash', '__worktree_slugs', env: ['WORKTREE_BIN' => $this->root.'/not-a-binary']);

    expect($session)->toHaveSucceeded()
        ->and(trim($session->getOutput()))->toBe('441-fix-login');
});

it('says nothing when there is no registry to read, rather than failing a completion', function () {
    $session = shellSession('bash', "__worktree_slugs\nprintf 'done=%s\\n' \"\$?\"");

    expect($session)->toHaveSucceeded()
        ->and($session->getOutput())->toBe("done=0\n");
});

/**
 * zsh gets the same function and the same registry read; only the completion
 * registration differs, and that is the half a bash-only test cannot reach.
 */
it('runs under zsh as well, which is the shell most of this is typed in', function () {
    registryHolds(['wt-desk-441-fix-login' => slotEntry(0, '441-fix-login')]);

    $session = shellSession('zsh', "wt 441\nprintf '%s\\n' \"\$PWD\"\n__worktree_slugs");

    expect($session)->toHaveSucceeded()
        ->and($session->getOutput())
        ->toContain($this->root.'/desk-worktrees/441-fix-login')
        ->toContain('441-fix-login');
})->skip(fn (): bool => (new ExecutableFinder)->find('zsh') === null, 'this machine has no zsh to run the script in');

/**
 * Sourced before `compinit`, which is where plenty of rc files will put it: the
 * function has to work and the completion has to be skipped, rather than every
 * new shell opening on an error.
 */
it('binds nothing under zsh when the completion system has not been started', function () {
    $session = shellSession('zsh', "wt --json\nprintf 'lived=%s\\n' \"\$?\"");

    expect($session->getErrorOutput())->not->toContain('compdef')
        ->and($session->getOutput())->toBe("lived=64\n");
})->skip(fn (): bool => (new ExecutableFinder)->find('zsh') === null, 'this machine has no zsh to run the script in');

/**
 * A shell session with the integration sourced, and no rc file anywhere: what is
 * under test is the script the binary just printed, not the developer's shell.
 *
 * @param  string  $body  What to run once it has been sourced.
 * @param  array<string, string|false>  $env
 */
function shellSession(string $shell, string $body, ?string $cwd = null, array $env = []): Process
{
    $emitted = worktree(['shell-init', $shell]);

    expect($emitted)->toHaveSucceeded();

    $script = test()->root.'/session.'.$shell;

    file_put_contents(test()->root.'/init.'.$shell, $emitted->getOutput());
    file_put_contents($script, 'source '.escapeshellarg(test()->root.'/init.'.$shell)."\n".$body."\n");

    $process = new Process(
        [$shell, ...($shell === 'zsh' ? ['-f'] : ['--noprofile', '--norc']), $script],
        $cwd ?? test()->main,
        worktreeEnvironment($env),
    );

    $process->setTimeout(60);
    $process->run();

    return $process;
}

/**
 * What bash would have offered, driven the way the shell drives it: the words on
 * the line so far, the last of them being what the cursor is on.
 *
 * @param  list<string>  $words
 * @return list<string>
 */
function bashCompletion(array $words, ?string $cwd = null, string $function = '__worktree_complete'): array
{
    $session = shellSession($shell = 'bash', implode("\n", [
        'COMP_WORDS=('.implode(' ', array_map(escapeshellarg(...), $words)).')',
        'COMP_CWORD='.(count($words) - 1),
        $function,
        'printf \'%s\n\' "${COMPREPLY[@]}"',
    ]), $cwd);

    expect($session)->toHaveSucceeded();

    return array_values(array_filter(explode("\n", trim($session->getOutput())), fn (string $line): bool => $line !== ''));
}
