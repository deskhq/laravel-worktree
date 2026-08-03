<?php

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Git\BaseRefs;
use DeskHQ\LaravelWorktree\Git\Worktrees;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Lock;
use DeskHQ\LaravelWorktree\Registry\Locks;
use DeskHQ\LaravelWorktree\Registry\Owner;
use DeskHQ\LaravelWorktree\Support\Machine;
use DeskHQ\LaravelWorktree\Tests\TestCase;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Isolation
|--------------------------------------------------------------------------
|
| Everything this package shares between runs — the registry, the global lock
| directory, the per-key locks — hangs off `$HOME`. So `$HOME` is pinned before
| every case, to a directory of the worker's own: that is what keeps parallel
| cases out of each other's state, and what keeps any case at all out of the
| developer's real `~/.laravel-worktree`.
|
| A fixture that wants its own — most of them do, so they can look at what a run
| wrote — re-pins it through {@see harness()}. This is the floor under the cases
| that never asked.
|
*/

// Read before anything moves it, because everything below moves it.
developerHome();

uses(TestCase::class)
    ->beforeEach(function () {
        pinHome(workerHome());

        // What a case that declares no fixture of its own runs against: a home
        // this worker owns, a `docker` that cannot be reached, and no checkout
        // to run from. {@see harness()} replaces the first two; a fixture that
        // has a repository sets the third.
        $this->home = workerHome();
        $this->docker = packagePath('tests/Fixtures/unreachable-docker');
        $this->main = null;
    })
    ->afterEach(fn () => pinHome(developerHome()))
    ->in(__DIR__);

function packagePath(string $path = ''): string
{
    return rtrim(dirname(__DIR__).'/'.ltrim($path, '/'), '/');
}

/**
 * The home this process was started with, remembered before anything moves it.
 */
function developerHome(): string
{
    static $home;

    return $home ??= (string) ($_ENV['HOME'] ?? $_SERVER['HOME'] ?? getenv('HOME'));
}

/**
 * Where a case that declared no home of its own writes: one directory per test
 * process, so a parallel run gives each worker its own.
 */
function workerHome(): string
{
    static $home;

    if ($home !== null) {
        return $home;
    }

    $home = temporaryDirectory('worktree-worker');

    register_shutdown_function(fn () => deleteDirectory($home));

    return $home;
}

/**
 * Point `$HOME` at $home, for this process and for every subprocess it starts.
 *
 * All three places, because they are read by different things: `$_SERVER` is
 * the first place this package's `env()` looks — as Laravel's does — and a
 * subprocess started without an explicit environment inherits the union of
 * `putenv()` and `$_ENV`, with `$_ENV` winning. Leaving either behind would let
 * a run land somewhere its own test cannot see.
 */
function pinHome(string $home): void
{
    is_dir($home) || mkdir($home, 0755, true);

    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;
    putenv('HOME='.$home);
}

/**
 * The fixture the command cases are built on: a temporary root, a machine-global
 * home inside it, and a `docker` that is not this machine's.
 *
 * Sets `$this->root`, `$this->home` and `$this->docker`, which is what
 * {@see worktreeEnvironment()} and {@see dockerCalls()} then read.
 */
function harness(string $prefix): void
{
    test()->root = temporaryDirectory($prefix);
    test()->home = test()->root.'/home';
    test()->docker = fakeDockerBinary(test()->root);

    pinHome(test()->home);
}

/**
 * The environment every run of the host binary gets.
 *
 * One declaration, because a case that spelled it out for itself is a case that
 * can leave a variable off — and the two that matter here decide whether the run
 * writes to the developer's registry and whether it reaches the developer's
 * daemon.
 *
 * @param  array<string, string|false>  $overrides
 * @return array<string, string|false>
 */
function worktreeEnvironment(array $overrides = []): array
{
    $home = test()->home;

    return [
        // Both, deliberately: `WORKTREE_HOME` is what this package reads, and
        // `HOME` is where it falls back when nothing set that — so a case that
        // unsets the first still lands inside its own fixture.
        'WORKTREE_HOME' => $home,
        'HOME' => $home,
        // A `docker` that is not this machine's. The fallback fails loudly
        // rather than quietly resolving to the real binary on `PATH`, so a case
        // that forgot to declare a fake cannot start containers on a laptop.
        'SAIL_DOCKER_BINARY' => test()->docker,
        // Unset, because this suite runs under Testbench, which exports
        // APP_ENV=testing — and both `bin/sail` and Laravel then read
        // `.env.testing` in preference to `.env`. Which file that lands in is
        // EnvFileTest's question; the rest of the suite is about the ordinary
        // one.
        'APP_ENV' => false,
        ...$overrides,
    ];
}

/**
 * Run the host binary the way a shell would, and hand back the finished process
 * so a test can assert on each stream separately.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function worktree(array $arguments = [], ?string $cwd = null, array $env = [], float $timeout = 60): Process
{
    $process = startWorktree($arguments, $cwd, $env, $timeout);
    $process->wait();

    return $process;
}

/**
 * A started one, for the cases that look at a run while it is still working.
 *
 * The timeout is generous because those runs are deliberately held mid-pipeline
 * and a loaded machine should not turn that into a failure. It is this test's
 * own patience, and unrelated to the limits the binary runs its own steps
 * under — `step_timeout` is half an hour, because Composer, npm and image
 * pulls take minutes.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 * @param  bool  $terminal  Give the run a pty, so that what it writes goes to something that is a terminal.
 */
function startWorktree(
    array $arguments = [],
    ?string $cwd = null,
    array $env = [],
    float $timeout = 120,
    bool $terminal = false,
): Process {
    $process = new Process(
        [PHP_BINARY, packagePath('bin/worktree'), ...$arguments],
        $cwd ?? test()->main,
        worktreeEnvironment($env),
    );

    $process->setTimeout($timeout);
    $process->setPty($terminal);
    $process->start();

    return $process;
}

/**
 * Why a run failed, as a message an assertion can carry.
 *
 * Every diagnostic this tool emits goes to stderr, and asserting on the exit
 * code alone throws all of it away. *"Failed asserting that 1 is identical to
 * 0"* is what left the-desk#619 undiagnosable for as long as it was.
 */
function worktreeFailure(Process $process): string
{
    return sprintf(
        "worktree exited %s; its stderr was:\n%s",
        $process->getExitCode() ?? 'without a status',
        $process->getErrorOutput() === '' ? '(it wrote nothing)' : $process->getErrorOutput(),
    );
}

/**
 * A run that worked — reported, when it did not, with everything it said about
 * why.
 */
expect()->extend('toHaveSucceeded', function () {
    expect($this->value->getExitCode())->toBe(0, worktreeFailure($this->value));

    return $this;
});

/**
 * A run that failed the way it was supposed to. Same reason for the message:
 * exiting 1 where 2 was expected says nothing on its own.
 */
expect()->extend('toHaveExited', function (int $code) {
    expect($this->value->getExitCode())->toBe($code, worktreeFailure($this->value));

    return $this;
});

/**
 * A run that refused, where which non-zero code it refused with is not the
 * point.
 */
expect()->extend('toHaveFailed', function () {
    expect($this->value->getExitCode())->not->toBe(0, worktreeFailure($this->value));

    return $this;
});

/*
|--------------------------------------------------------------------------
| The commands
|--------------------------------------------------------------------------
|
| One way to run each of them, so that the environment a run gets is declared
| once — and so that a case in any file can set up through a command it is not
| itself about. `remove` needs a worktree to remove, and `reap` needs the
| registry a `create` writes.
|
*/

/**
 * @param  list<string>  $arguments
 */
function worktreeCreate(array $arguments = ['feat/checkout']): Process
{
    $process = startWorktreeCreate($arguments);
    $process->wait();

    return $process;
}

/**
 * @param  list<string>  $arguments
 */
function startWorktreeCreate(array $arguments = ['feat/checkout']): Process
{
    return startWorktree(['create', ...$arguments]);
}

/**
 * @param  list<string>  $arguments
 */
function worktreeRemove(array $arguments = ['feat/checkout']): Process
{
    $process = startWorktreeRemove($arguments);
    $process->wait();

    return $process;
}

/**
 * @param  list<string>  $arguments
 */
function startWorktreeRemove(array $arguments = ['feat/checkout']): Process
{
    return startWorktree(['remove', ...$arguments]);
}

/**
 * @param  list<string>  $arguments
 */
function worktreeStop(array $arguments = ['feat/checkout']): Process
{
    $process = startWorktreeStop($arguments);
    $process->wait();

    return $process;
}

/**
 * @param  list<string>  $arguments
 */
function startWorktreeStop(array $arguments = ['feat/checkout']): Process
{
    return startWorktree(['stop', ...$arguments]);
}

/**
 * @param  list<string>  $arguments
 */
function worktreeStart(array $arguments = ['feat/checkout']): Process
{
    $process = startWorktreeStart($arguments);
    $process->wait();

    return $process;
}

/**
 * @param  list<string>  $arguments
 */
function startWorktreeStart(array $arguments = ['feat/checkout']): Process
{
    return startWorktree(['start', ...$arguments]);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function worktreePath(array $arguments = ['441'], ?string $cwd = null, array $env = []): Process
{
    return worktree(['path', ...$arguments], cwd: $cwd, env: $env);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function worktreeList(array $arguments = [], array $env = []): Process
{
    return worktree(['list', ...$arguments], env: $env);
}

/**
 * The same run with a terminal on the far side of its stdout, which is the
 * other of `list`'s two renderings and the only thing that chooses between them.
 *
 * The width is passed as `COLUMNS` rather than left to the pty: a terminal a
 * test allocated is not this process's *controlling* terminal, which is what
 * `stty size` answers for, so the run would otherwise measure the developer's
 * own window — or nothing at all, on CI.
 *
 * Colour is off unless a case asks for it, so that the cases about the table are
 * about the table; the ones about colour turn it on and say so.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function worktreeListInTerminal(array $arguments = [], array $env = [], int $columns = 100, bool $colour = false): Process
{
    if (! Process::isPtySupported()) {
        test()->markTestSkipped('this PHP cannot allocate a pty to put a terminal on the far side of stdout');
    }

    $process = startWorktree(['list', ...$arguments], env: [
        'COLUMNS' => (string) $columns,
        'TERM' => 'xterm-256color',
        'NO_COLOR' => $colour ? false : '1',
        ...$env,
    ], terminal: true);

    $process->wait();

    return $process;
}

/**
 * @param  list<string>  $arguments
 */
function worktreeReap(array $arguments = []): Process
{
    return worktree(['reap', ...$arguments]);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function worktreeDoctor(array $arguments = [], array $env = []): Process
{
    return worktree(['doctor', ...$arguments], env: $env);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function worktreeInit(array $arguments = [], array $env = []): Process
{
    return worktree(['init', ...$arguments], env: $env);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function worktreeShellInit(array $arguments = [], ?string $cwd = null, array $env = []): Process
{
    return worktree(['shell-init', ...$arguments], cwd: $cwd, env: $env);
}

/**
 * An application shaped like the one `sail install` writes, carrying every case
 * the derivation and the pre-flight have to tell apart: both `depends_on`
 * spellings, a service reached only through another's, a literal host port, a
 * service that publishes nothing, and one behind a profile.
 *
 * Declared here rather than beside the pre-flight cases because `worktree init`
 * derives a configuration from exactly the file the pre-flight then checks, and
 * the two are only worth asserting against the same application — a fixture only
 * one file declares is a fixture that is missing whenever that file is not the
 * one being run.
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

/**
 * The main checkout the command cases run from: a repository with a compose
 * file, one commit, and a `.env` of the shape a Laravel application has.
 */
function mainCheckout(string $path): string
{
    mkdir($path, 0755, true);

    runGit($path, 'init', '--quiet', '--initial-branch=main', '.');
    file_put_contents($path.'/compose.yaml', "services:\n  laravel.test:\n    image: laravel\n");
    runGit($path, 'add', '-A');
    runGit($path, 'commit', '--quiet', '-m', 'initial');

    // Written after the commit, because an application's `.env` is not tracked
    // — and a worktree that got one from git would keep it, generating nothing.
    file_put_contents($path.'/.env', "APP_NAME=Desk\nAPP_KEY=\n");

    return $path;
}

/**
 * What the main checkout configures.
 *
 * Written rather than published, because what a repository configures is what
 * `create` has to wire together — the ports its `.env` gets, the overlay its
 * services need, and the recipe it runs.
 *
 * @param  array<string, mixed>  $config
 */
function configureRepository(array $config = []): void
{
    $config = array_replace([
        'slots' => 5,
        // A window this machine has free right now, so a real service on the
        // developer's laptop cannot decide which slot the test gets.
        'port_base' => test()->base,
        'env' => [
            'APP_PORT' => '{{port.app}}',
            'COMPOSE_PROJECT_NAME' => '{{project}}',
        ],
        'steps' => [],
    ], $config);

    is_dir(test()->main.'/config') || mkdir(test()->main.'/config', 0755, true);

    file_put_contents(test()->main.'/config/worktree.php', '<?php return '.var_export($config, true).";\n");
}

/**
 * A step that sits in the middle of the pipeline until the example lets it go —
 * a stand-in for the minutes of Composer, npm and image pulls that a second run
 * has to wait out, that a signal arrives in the middle of, or that a `remove`
 * would otherwise be free to run straight through.
 *
 * @return array<string, string>
 */
function gatedStep(): array
{
    return ['label' => 'Waiting', 'host' => 'until [ -f '.test()->gate.' ]; do sleep 0.1; done'];
}

/**
 * A directory under the system temp dir, for a test to work in.
 */
function temporaryDirectory(string $prefix): string
{
    $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));

    mkdir($path, 0755, true);

    return (string) realpath($path);
}

/**
 * What is at $path right now: every entry below it, keyed by relative path, a
 * file carrying a hash of its contents — or `null` when there is nothing there.
 *
 * Absent and empty are deliberately different answers. Comparing two of these
 * across a run asserts the directory is *unchanged*, which is what a case that
 * looks at the developer's own registry can assert: it may well exist, since
 * creating one is what this package is for.
 *
 * @return array<string, string>|null
 */
function directorySnapshot(string $path): ?array
{
    if (! is_dir($path)) {
        return null;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $snapshot = [];

    /** @var SplFileInfo $entry */
    foreach ($entries as $entry) {
        $relative = substr($entry->getPathname(), strlen($path) + 1);

        $snapshot[$relative] = $entry->isFile() && ! $entry->isLink()
            ? (string) hash_file('sha256', $entry->getPathname())
            : (string) $entry->getType();
    }

    ksort($snapshot);

    return $snapshot;
}

function deleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    /** @var SplFileInfo $entry */
    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($path);
}

/**
 * A configuration whose machine-global home is $home, so a test allocates slots
 * in a directory of its own rather than in the developer's real one.
 *
 * @param  array<string, mixed>  $config  As `config/worktree.php` would have returned it.
 */
function configurationIn(string $home, array $config = []): Configuration
{
    $restore = $_SERVER['WORKTREE_HOME'] ?? null;
    $_SERVER['WORKTREE_HOME'] = $home;

    try {
        return Configuration::fromArray($config);
    } finally {
        if ($restore === null) {
            unset($_SERVER['WORKTREE_HOME']);
        } else {
            $_SERVER['WORKTREE_HOME'] = $restore;
        }
    }
}

/**
 * A repository directory whose `config/worktree.php` is $body, or which has no
 * config file at all when $body is null.
 *
 * Declared here rather than beside the config cases, because the arch test asks
 * the same question of the same fixture — and a helper only one file declares is
 * a helper that is missing whenever that file is not the one being run.
 */
function repositoryWithConfig(?string $body): string
{
    $root = temporaryDirectory('worktree-config');

    if ($body !== null) {
        mkdir($root.'/config');
        file_put_contents($root.'/config/worktree.php', $body);
    }

    return $root;
}

/**
 * The configuration as the host binary reads it: in its own process, with no
 * application booted.
 *
 * @param  array<string, string|false>  $environment
 * @return array<string, mixed>
 */
function configurationOf(string $root, array $environment = []): array
{
    $process = readsConfiguration($root, $environment);

    expect($process->getErrorOutput())->toBe('');

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, string|false>  $environment
 */
function readsConfiguration(string $root, array $environment = [], bool $isolated = false): Process
{
    $fixture = $isolated ? 'loads-a-config-in-isolation.php' : 'dumps-the-config.php';

    $process = new Process([PHP_BINARY, packagePath('tests/Fixtures/'.$fixture), $root], null, $environment);
    $process->setTimeout(60);
    $process->run();

    return $process;
}

/**
 * The default port block of slot 0 — the ports `.env` and the Compose overlay
 * are both written from.
 *
 * @return array<string, int>
 */
function slotPorts(): array
{
    return ['app' => 20000, 'vite' => 20001, 'reverb' => 20002, 'db' => 20003, 'redis' => 20004];
}

/**
 * A `port_base` whose whole window is free on this machine right now.
 *
 * Allocation probes the ports it is about to claim, so a real service on the
 * developer's machine would otherwise decide which slot a test gets.
 */
function freePortBase(int $span = 100): int
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $base = random_int(30000, 60000 - $span);

        if (portsAreFree(range($base, $base + $span - 1))) {
            return $base;
        }
    }

    throw new RuntimeException("could not find $span consecutive free ports to test against");
}

/**
 * @param  list<int>  $ports
 */
function portsAreFree(array $ports): bool
{
    $sockets = [];
    $free = true;

    foreach ($ports as $port) {
        $socket = @stream_socket_server("tcp://0.0.0.0:$port", $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if ($socket === false) {
            $free = false;

            break;
        }

        $sockets[] = $socket;
    }

    foreach ($sockets as $socket) {
        fclose($socket);
    }

    return $free;
}

/**
 * A repository with one commit, so `git worktree add` has something to attach to.
 */
function temporaryRepository(): string
{
    $path = temporaryDirectory('worktree-repo');

    foreach ([
        ['git', 'init', '--initial-branch=main', $path],
        ['git', '-C', $path, 'config', 'user.email', 'tests@example.com'],
        ['git', '-C', $path, 'config', 'user.name', 'Tests'],
        ['git', '-C', $path, 'commit', '--allow-empty', '-m', 'initial'],
    ] as $command) {
        (new Process($command))->mustRun();
    }

    return $path;
}

/**
 * An upstream carrying `master` and `develop`, cloned so that the clone knows
 * `develop` only as `remotes/origin/develop` — the state that made `create
 * <slug> develop` land on `develop` itself (the-desk#619).
 *
 * `develop` carries a commit `master` does not, so a fork from the wrong base
 * is detectable by SHA rather than only by branch name; that is what makes the
 * stale-local-branch case (the-desk#639) assertable at all.
 *
 * @return array{0: string, 1: string} the clone, and the directory holding both it and the upstream
 */
function temporaryClone(): array
{
    $root = temporaryDirectory('worktree-clone');

    mkdir($root.'/upstream', 0755, true);

    runGit($root.'/upstream', 'init', '--quiet', '--initial-branch=master', '.');
    file_put_contents($root.'/upstream/README.md', "fixture\n");
    runGit($root.'/upstream', 'add', '-A');
    runGit($root.'/upstream', 'commit', '--quiet', '-m', 'init');
    runGit($root.'/upstream', 'checkout', '--quiet', '-b', 'develop');
    file_put_contents($root.'/upstream/README.md', "fixture on develop\n");
    runGit($root.'/upstream', 'commit', '--quiet', '-am', 'develop only');
    runGit($root.'/upstream', 'checkout', '--quiet', 'master');

    runGit($root, 'clone', '--quiet', $root.'/upstream', 'main');

    return [$root.'/main', $root];
}

/**
 * A git command a test needs to have worked, with an identity of its own so it
 * does not depend on the developer's.
 */
function runGit(string $cwd, string ...$arguments): Process
{
    $process = new Process(['git', '-c', 'user.email=tests@example.com', '-c', 'user.name=Tests', ...$arguments], $cwd);
    $process->mustRun();

    return $process;
}

/**
 * Whether git itself considers $file ignored inside the worktree at $path —
 * which is the only thing an exclude is for.
 */
function ignoredInGit(string $path, string $file): bool
{
    return (new Process(['git', 'check-ignore', '--quiet', '--', $file], $path))->run() === 0;
}

/**
 * The commit $ref points at, so a fork can be asserted by SHA rather than by
 * the branch name that was asked for.
 */
function gitRevision(string $cwd, string $ref): string
{
    return trim(runGit($cwd, 'rev-parse', $ref)->getOutput());
}

/**
 * The base-ref resolver anchored at $cwd — the main checkout of a repository,
 * or any of its worktrees.
 *
 * @param  resource|null  $diagnostics  Where the git the layer runs writes; a memory stream by default.
 */
function baseRefsIn(string $cwd, $diagnostics = null): BaseRefs
{
    $runner = new ProcessRunner(new Output($diagnostics ?? fopen('php://memory', 'w+')));

    return new BaseRefs($runner, Anchor::resolve($runner, $cwd));
}

/**
 * A lock over $path, with its diagnostics captured.
 *
 * Declared here rather than beside the lock cases because more than one file
 * needs a lock built the same way, and the two dependencies a lock has beyond
 * its path — where it says what it is waiting for, and the machine it judges a
 * holder against — are not what any of those cases are about.
 *
 * @param  resource|null  $diagnostics
 */
function lockAt(string $path, int $attempts = 2, string $contended = 'contended', $diagnostics = null): Lock
{
    $output = new Output($diagnostics ?? fopen('php://memory', 'w+'));

    return new Lock($path, $attempts, $contended, $output, machine());
}

/**
 * This machine, as a lock reads it.
 */
function machine(): Machine
{
    return new Machine(new ProcessRunner(new Output(fopen('php://memory', 'w+'))));
}

/**
 * Both locks of the home at $home, with their diagnostics thrown away.
 */
function locksIn(string $home): Locks
{
    $output = new Output(fopen('php://memory', 'w+'));

    return new Locks($home, new ShutdownHandler($output), $output);
}

/**
 * A lock directory whose owner record says $record, written the way a run that
 * won the `mkdir` would have written it.
 *
 * @param  array<string, mixed>  $record
 */
function lockTakenBy(string $path, array $record): void
{
    is_dir($path) || mkdir($path, 0755, true);

    file_put_contents($path.'/'.Owner::File, json_encode($record)."\n");
}

/**
 * This run, as an owner record — the holder that is unquestionably alive, which
 * every case that wants a dead one varies from.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ownerRecord(array $overrides = []): array
{
    $pid = (int) getmypid();

    return array_replace([
        'pid' => $pid,
        'host' => machine()->host(),
        'boot' => machine()->boot(),
        'started_at' => machine()->startedAt($pid),
        'command' => 'worktree create 441',
        'taken_at' => '2026-08-01T09:12:44Z',
    ], $overrides);
}

/**
 * A pid this machine really had and really does not have any more — which is
 * the thing a stale lock records, and which no made-up number is.
 */
function deadPid(): int
{
    $process = new Process([PHP_BINARY, '-r', 'usleep(1000);']);
    $process->start();

    $pid = $process->getPid();

    $process->wait();

    expect($pid)->not->toBeNull();

    return (int) $pid;
}

/**
 * @param  resource|null  $diagnostics
 */
function worktreesIn(string $cwd, $diagnostics = null): Worktrees
{
    $output = new Output($diagnostics ?? fopen('php://memory', 'w+'));
    $runner = new ProcessRunner($output);
    $anchor = Anchor::resolve($runner, $cwd);

    return new Worktrees($runner, $output, $anchor, new BaseRefs($runner, $anchor));
}

/**
 * Run $work with $environment set the way a subprocess would have seen it, and
 * put the environment back afterwards however it ends.
 *
 * `$_SERVER` rather than `putenv`, because that is the first place the package's
 * own `env()` looks — as Laravel's does.
 *
 * @param  array<string, string>  $environment
 */
function withEnvironment(array $environment, Closure $work): mixed
{
    $restore = [];

    foreach ($environment as $key => $value) {
        $restore[$key] = $_SERVER[$key] ?? null;
        $_SERVER[$key] = $value;
    }

    try {
        return $work();
    } finally {
        foreach ($restore as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);

                continue;
            }

            $_SERVER[$key] = $value;
        }
    }
}

/**
 * The registry as this machine holds it, and the worktrees it says are there.
 *
 * Written rather than created by `create`: what `list` reads and what `reap`
 * checks itself against is a file, and a case that had to bootstrap five
 * worktrees to show five rows would be testing `create` again.
 *
 * The directories are made because an entry claims one, and `list` and `reap`
 * both now ask the disk whether it is really there (#53) — so a fixture that
 * wrote only the file would be declaring five dead entries without meaning to.
 * A case that wants one says which, by key, and gets exactly the state a
 * `rm -rf` leaves: the row, with nothing behind it.
 *
 * @param  array<string, array<string, mixed>>  $entries
 * @param  list<string>  $gone  Keys whose worktree directory is not to exist.
 */
function registryHolds(array $entries, array $gone = []): void
{
    file_put_contents(
        test()->home.'/registry.json',
        json_encode($entries === [] ? new stdClass : $entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    foreach ($entries as $key => $entry) {
        $path = $entry['path'] ?? null;

        if (! is_string($path)) {
            continue;
        }

        // Removed rather than merely not made, so that a case declaring the
        // same registry twice — once whole, once with a worktree gone — gets
        // the state it asked for both times.
        if (in_array($key, $gone, true)) {
            deleteDirectory($path);

            continue;
        }

        is_dir($path) || mkdir($path, 0755, true);
    }
}

/**
 * The registry as this machine now holds it — what a run that was supposed to
 * free a slot, or to leave one alone, is asserted against.
 *
 * @return array<string, mixed>
 */
function registryNow(): array
{
    $registry = test()->home.'/registry.json';

    return is_file($registry)
        ? (array) json_decode((string) file_get_contents($registry), true, flags: JSON_THROW_ON_ERROR)
        : [];
}

/**
 * One entry, as an earlier `create` would have written it.
 *
 * @param  array<string, int>|null  $ports
 * @param  string|null  $createdAt  When the slot was claimed; a fixed date unless a case is about the `AGE` column.
 * @param  list<string>  $degraded  Bootstrap steps that failed and were allowed to.
 * @return array<string, mixed>
 */
function slotEntry(
    int $slot,
    string $slug,
    ?string $repo = null,
    ?string $branch = null,
    ?array $ports = null,
    ?string $createdAt = null,
    array $degraded = [],
): array {
    $repo ??= test()->main;

    if ($ports === null) {
        $ports = [];

        foreach (['app', 'vite', 'reverb', 'db', 'redis'] as $index => $name) {
            $ports[$name] = 20000 + $slot * 10 + $index;
        }
    }

    $entry = [
        'slot' => $slot,
        'repo' => $repo,
        'slug' => $slug,
        'branch' => $branch ?? $slug,
        'path' => $repo.'-worktrees/'.$slug,
        'ports' => $ports,
        'created_at' => $createdAt ?? '2026-01-01T00:00:00Z',
    ];

    // Absent rather than empty, exactly as `create` writes it: a healthy
    // worktree's entry should read as a healthy worktree's entry.
    return $degraded === [] ? $entry : $entry + ['degraded' => $degraded];
}

/**
 * A timestamp $seconds ago, as `create` would have written it.
 *
 * Read at the call rather than pinned per process, so that the age a run
 * computes from it is $seconds and not $seconds plus however long this worker
 * had already been going. A case that asserts on the timestamp itself keeps the
 * one it declared, rather than asking for a second one and comparing two
 * instants.
 */
function claimedAgo(int $seconds): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
}

/**
 * Everything a run wrote to its diagnostics.
 *
 * @param  resource  $diagnostics
 */
function diagnosticsIn($diagnostics): string
{
    rewind($diagnostics);

    return (string) stream_get_contents($diagnostics);
}

/*
|--------------------------------------------------------------------------
| The machine, faked
|--------------------------------------------------------------------------
|
| A `docker` and a `vendor/bin/sail` that record how they were called and
| answer what this package asks of them, so the suite runs with Docker Desktop
| closed. They live here rather than beside one test file because `create`
| drives the whole stack through them and the runtime drives them directly,
| and a helper only one file declares is a helper that is missing whenever
| that file is not the one being run.
|
*/

/**
 * A `docker` that records every invocation and answers the questions a teardown
 * asks, with no daemon anywhere on the machine.
 *
 * Its containers and volumes live in files that removals delete lines from, so
 * the re-query a teardown ends with sees what those removals actually did —
 * which is the property worth asserting, rather than the calls on their own.
 *
 * @param  list<string>  $containers  Ids the container label query answers with, for a project given no resources of its own.
 * @param  list<string>  $volumes  Names the volume label query answers with, likewise.
 * @param  list<string>  $refuses  Resources whose removal fails, as one still in use would.
 * @param  array<string, array{containers?: int|list<string>, volumes?: int|list<string>, running?: int}>  $projects  What each Compose project on this daemon owns, as a count or as the resources themselves, and how many of its containers are up (all of them unless said).
 * @param  bool  $daemon  Whether `docker info` succeeds at all.
 * @param  bool  $composeSubcommand  Whether `docker compose` exists, or only the standalone binary.
 * @param  bool  $producesSail  Whether `docker run` leaves a `vendor/bin/sail` behind, as the Composer image would.
 * @param  string  $composeVersion  What `compose version --short` answers with; `''` for a build that names no version.
 */
function fakeDockerBinary(
    string $root,
    array $containers = [],
    array $volumes = [],
    array $refuses = [],
    array $projects = [],
    bool $daemon = true,
    int $composeExitCode = 0,
    string $composeOutput = '',
    bool $composeSubcommand = true,
    bool $producesSail = true,
    string $composeVersion = '2.31.0',
): string {
    $state = $root.'/fake';

    is_dir($state) || mkdir($state, 0755, true);

    $owned = projectResources($projects);

    $files = ['containers' => $containers, 'volumes' => $volumes, 'refuses' => $refuses]
        + projectCensus($owned, $projects)
        + $owned;

    foreach ($files as $name => $lines) {
        file_put_contents($state.'/'.$name, $lines === [] ? '' : implode("\n", $lines)."\n");
    }

    // The Sail the bootstrap container "installs", copied into the mount the way
    // a real `composer install` would have written it.
    writeFakeSail($state.'/sail', 0);

    $answers = [
        'daemon' => $daemon ? 'yes' : 'no',
        'compose' => $composeSubcommand ? 'yes' : 'no',
        'sail' => $producesSail ? 'yes' : 'no',
    ];

    $binary = $root.'/docker';

    file_put_contents($binary, <<<SH
        #!/bin/sh
        STATE='$state'
        printf '%s\\n' "\$*" >> "\$STATE/log"

        # A removed resource is gone from every label query there is: out of the
        # list its project answers with, and out of the census the orphan scan
        # counts. `reap` runs both against the same daemon, so a fake that
        # forgot the second would report a project it had just emptied.
        forget() {
            KIND="\$1"
            CENSUS="\$2"
            NAME="\$3"

            if [ -f "\$STATE/\$KIND" ]; then
                grep -vx "\$NAME" "\$STATE/\$KIND" > "\$STATE/next" || true
                mv "\$STATE/next" "\$STATE/\$KIND"
            fi

            for FILE in "\$STATE/\$KIND".*; do
                [ -f "\$FILE" ] || continue
                grep -qx "\$NAME" "\$FILE" || continue

                grep -vx "\$NAME" "\$FILE" > "\$STATE/next" || true
                mv "\$STATE/next" "\$FILE"

                PROJECT="\${FILE##*/}"
                PROJECT="\${PROJECT#\$KIND.}"

                # The project is the first field of a census line, whether or
                # not a container's state follows it.
                awk -v project="\$PROJECT" 'BEGIN { dropped = 0 } { if (! dropped && \$1 == project) { dropped = 1; next } print }' \\
                    "\$STATE/\$CENSUS" > "\$STATE/next"
                mv "\$STATE/next" "\$STATE/\$CENSUS"
            done
        }

        # `docker compose` is probed before it is used; a machine carrying only
        # the standalone binary answers no here, and bin/sail drops to that.
        # `--short` is the version pre-flight asking whether this Compose knows
        # the '!override' merge tag, and it answers before the daemon check
        # below because it is a question about the client rather than about a
        # daemon that may not be running.
        if [ "\$1" = compose ] && [ "\$2" = version ]; then
            [ '{$answers['compose']}' = yes ] || exit 127
            [ "\$3" != --short ] || printf '%s\\n' '$composeVersion'
            exit 0
        fi

        [ '{$answers['daemon']}' = yes ] || exit 1

        # The census the orphan scan reads: one line per resource, carrying the
        # project it belongs to, rather than the ids the per-project queries ask
        # for. Both are label queries; only the filter and the format differ.
        case "\$1 \$2 \$3" in
            'ps -a --filter')
                cat "\$STATE/container-projects"
                exit 0
                ;;
            'volume ls --filter')
                cat "\$STATE/volume-projects"
                exit 0
                ;;
        esac

        # The resource a removal names is always its last argument.
        for LAST in "\$@"; do :; done

        case "\$1 \$2" in
            'compose -p')
                # What compose.yaml interpolates, and what bin/sail exports for it.
                printf '%s:%s\\n' "\$WWWUSER" "\$WWWGROUP" > "\$STATE/compose-env"
                printf '%s\\n' "\$*" >> "\$STATE/compose"
                # Something happening on the machine mid-run, once: a `create`
                # that claims one of the projects a `reap` is partway through.
                if [ -f "\$STATE/hook" ]; then
                    mv "\$STATE/hook" "\$STATE/hook-fired"
                    sh "\$STATE/hook-fired"
                fi
                [ -z '$composeOutput' ] || printf '%s\\n' '$composeOutput' >&2
                exit $composeExitCode
                ;;
            'ps -aq')
                # A per-project query, so a project that owns resources of its
                # own answers with those; the shared list is what a test that
                # only ever tears one project down declares.
                PROJECT="\${LAST##*=}"
                cat "\$STATE/containers.\$PROJECT" 2>/dev/null || cat "\$STATE/containers"
                ;;
            'volume ls')
                PROJECT="\${LAST##*=}"
                cat "\$STATE/volumes.\$PROJECT" 2>/dev/null || cat "\$STATE/volumes"
                ;;
            'volume rm')
                if grep -qx "\$LAST" "\$STATE/refuses"; then
                    printf 'Error response from daemon: remove %s: volume is in use\\n' "\$LAST" >&2
                    exit 1
                fi
                # Removed from wherever it was listed: a removal names a
                # resource, never the project it belonged to.
                forget volumes volume-projects "\$LAST"
                ;;
            'rm --force')
                if grep -qx "\$LAST" "\$STATE/refuses"; then
                    printf 'Error response from daemon: cannot remove container %s\\n' "\$LAST" >&2
                    exit 1
                fi
                forget containers container-projects "\$LAST"
                ;;
            'run --rm')
                printf 'resolving dependencies\\n'
                [ '{$answers['sail']}' = yes ] || exit 0
                for ARG in "\$@"; do
                    case "\$ARG" in
                        *:/var/www/html)
                            MOUNT="\${ARG%:/var/www/html}"
                            mkdir -p "\$MOUNT/vendor/bin"
                            cp "\$STATE/sail" "\$MOUNT/vendor/bin/sail"
                            chmod 0755 "\$MOUNT/vendor/bin/sail"
                            ;;
                    esac
                done
                ;;
        esac

        exit 0
        SH);

    chmod($binary, 0755);

    // The standalone binary bin/sail falls back to, named after the configured
    // one exactly as bin/sail names it: `podman-compose` on a Podman machine.
    file_put_contents($binary.'-compose', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '$state/compose'\nexit 0\n");
    chmod($binary.'-compose', 0755);

    return $binary;
}

/**
 * What each project on this daemon actually owns, as one file per project and
 * kind: `containers.wt-desk-441-fix-login`, `volumes.wt-desk-441-fix-login`.
 *
 * A count is enough for a scan — `list` only ever says "3 volumes" — but `reap`
 * tears these projects down afterwards, and a teardown asks for the resources
 * by name and then re-queries to see whether they went. So a count is turned
 * into that many named resources here, and a test that wants to name one (a
 * volume something refuses to release) gives the names itself.
 *
 * @param  array<string, array{containers?: int|list<string>, volumes?: int|list<string>}>  $projects
 * @return array<string, list<string>>
 */
function projectResources(array $projects): array
{
    $files = [];

    foreach ($projects as $project => $owned) {
        foreach (['containers' => 'c', 'volumes' => 'v'] as $kind => $initial) {
            $resources = $owned[$kind] ?? [];

            $files[$kind.'.'.$project] = is_array($resources)
                ? array_values($resources)
                : array_map(
                    fn (int $index): string => $project.'_'.$initial.$index,
                    $resources > 0 ? range(1, $resources) : [],
                );
        }
    }

    return $files;
}

/**
 * The label census as Docker prints it: one line per container, and one per
 * volume, each carrying the Compose project it belongs to.
 *
 * A container line carries its state as well, because that is the second field
 * `list` reads to tell a worktree that is serving from one whose boot stopped
 * partway (#54). A project says how many of its containers are up with
 * `running`; unless it does, all of them are — "it has four containers" reads
 * as a project that is up, and the cases about the other states say so.
 *
 * @param  array<string, list<string>>  $resources  As {@see projectResources()} built them.
 * @param  array<string, array{containers?: int|list<string>, volumes?: int|list<string>, running?: int}>  $projects
 * @return array{container-projects: list<string>, volume-projects: list<string>}
 */
function projectCensus(array $resources, array $projects = []): array
{
    $census = ['container-projects' => [], 'volume-projects' => []];

    foreach ($resources as $file => $owned) {
        [$kind, $project] = explode('.', $file, 2);

        if ($kind === 'volumes') {
            $census['volume-projects'] = [...$census['volume-projects'], ...array_fill(0, count($owned), $project)];

            continue;
        }

        $up = $projects[$project]['running'] ?? count($owned);

        foreach (array_keys($owned) as $index) {
            $census['container-projects'][] = $project.' '.($index < $up ? 'running' : 'exited');
        }
    }

    return $census;
}

/**
 * A `vendor/bin/sail` already in the worktree, recording how it was called and
 * what it was told — which is the whole of what this package asks of Sail.
 */
function fakeSail(string $path, int $exitCode = 0): void
{
    mkdir($path.'/vendor/bin', 0755, true);

    writeFakeSail($path.'/vendor/bin/sail', $exitCode);
}

/**
 * The logs are written relative to the working directory on purpose: every Sail
 * invocation this package makes runs with the worktree as its cwd, because the
 * Sail that carries a worktree's ports and Compose project is the one it owns.
 */
function writeFakeSail(string $file, int $exitCode): void
{
    file_put_contents($file, <<<SH
        #!/bin/sh
        printf '%s\\n' "\$*" >> sail.log
        printf '%s\\n' "\${SAIL_SKIP_CHECKS:-unset}" >> sail.env
        exit $exitCode
        SH);

    chmod($file, 0755);
}

/**
 * Every invocation the fake `docker` recorded, in order.
 *
 * @return list<string>
 */
function dockerCalls(): array
{
    return recorded(test()->root.'/fake/log');
}

/**
 * @return list<string>
 */
function recorded(string $log): array
{
    if (! is_file($log)) {
        return [];
    }

    $lines = array_map(trim(...), explode("\n", (string) file_get_contents($log)));

    return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
}

/**
 * Wait for a started process to have written $needle, and hand back what it has
 * written so far.
 *
 * Polling rather than `waitUntil()`: that only sees output arriving *during*
 * the call, and in a race the run being waited on second has usually written
 * its line already — which is exactly the case these tests are about.
 */
function waitForOutput(Process $process, string $needle, bool $stderr = false, float $seconds = 30.0): string
{
    $deadline = microtime(true) + $seconds;

    do {
        $written = $stderr ? $process->getErrorOutput() : $process->getOutput();

        if (str_contains($written, $needle)) {
            return $written;
        }

        usleep(20_000);
    } while ($process->isRunning() && microtime(true) < $deadline);

    throw new RuntimeException(
        "the run never wrote '$needle'; stdout: ".json_encode($process->getOutput())
        .', stderr: '.json_encode($process->getErrorOutput())
    );
}
