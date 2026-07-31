<?php

use DeskHQ\LaravelWorktree\Config\Configuration;
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
