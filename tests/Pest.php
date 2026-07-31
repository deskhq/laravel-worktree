<?php

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Git\BaseRefs;
use DeskHQ\LaravelWorktree\Git\Worktrees;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class)->in(__DIR__);

function packagePath(string $path = ''): string
{
    return rtrim(dirname(__DIR__).'/'.ltrim($path, '/'), '/');
}

/**
 * Run the host binary the way a shell would, and hand back the finished process
 * so a test can assert on each stream separately.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 */
function worktree(array $arguments = [], ?string $cwd = null, array $env = []): Process
{
    $process = new Process([PHP_BINARY, packagePath('bin/worktree'), ...$arguments], $cwd, $env);
    $process->setTimeout(60);
    $process->run();

    return $process;
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
 * @param  list<string>  $containers  Ids the container label query answers with.
 * @param  list<string>  $volumes  Names the volume label query answers with.
 * @param  list<string>  $refuses  Resources whose removal fails, as one still in use would.
 * @param  bool  $daemon  Whether `docker info` succeeds at all.
 * @param  bool  $composeSubcommand  Whether `docker compose` exists, or only the standalone binary.
 * @param  bool  $producesSail  Whether `docker run` leaves a `vendor/bin/sail` behind, as the Composer image would.
 */
function fakeDockerBinary(
    string $root,
    array $containers = [],
    array $volumes = [],
    array $refuses = [],
    bool $daemon = true,
    int $composeExitCode = 0,
    string $composeOutput = '',
    bool $composeSubcommand = true,
    bool $producesSail = true,
): string {
    $state = $root.'/fake';

    is_dir($state) || mkdir($state, 0755, true);

    foreach (['containers' => $containers, 'volumes' => $volumes, 'refuses' => $refuses] as $name => $lines) {
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

        # `docker compose` is probed before it is used; a machine carrying only
        # the standalone binary answers no here, and bin/sail drops to that.
        if [ "\$1" = compose ] && [ "\$2" = version ]; then
            [ '{$answers['compose']}' = yes ] || exit 127
            exit 0
        fi

        [ '{$answers['daemon']}' = yes ] || exit 1

        # The resource a removal names is always its last argument.
        for LAST in "\$@"; do :; done

        case "\$1 \$2" in
            'compose -p')
                # What compose.yaml interpolates, and what bin/sail exports for it.
                printf '%s:%s\\n' "\$WWWUSER" "\$WWWGROUP" > "\$STATE/compose-env"
                printf '%s\\n' "\$*" >> "\$STATE/compose"
                [ -z '$composeOutput' ] || printf '%s\\n' '$composeOutput' >&2
                exit $composeExitCode
                ;;
            'ps -aq')
                cat "\$STATE/containers"
                ;;
            'volume ls')
                cat "\$STATE/volumes"
                ;;
            'volume rm')
                if grep -qx "\$LAST" "\$STATE/refuses"; then
                    printf 'Error response from daemon: remove %s: volume is in use\\n' "\$LAST" >&2
                    exit 1
                fi
                grep -vx "\$LAST" "\$STATE/volumes" > "\$STATE/next" || true
                mv "\$STATE/next" "\$STATE/volumes"
                ;;
            'rm --force')
                if grep -qx "\$LAST" "\$STATE/refuses"; then
                    printf 'Error response from daemon: cannot remove container %s\\n' "\$LAST" >&2
                    exit 1
                fi
                grep -vx "\$LAST" "\$STATE/containers" > "\$STATE/next" || true
                mv "\$STATE/next" "\$STATE/containers"
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
