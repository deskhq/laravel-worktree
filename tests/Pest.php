<?php

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
