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
 * Everything a run wrote to its diagnostics.
 *
 * @param  resource  $diagnostics
 */
function diagnosticsIn($diagnostics): string
{
    rewind($diagnostics);

    return (string) stream_get_contents($diagnostics);
}
