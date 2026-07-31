<?php

use Symfony\Component\Process\Process;

/**
 * The contract from the-desk#1043: only machine-readable output reaches stdout,
 * and it stays that way because of how the code is shaped, not because every
 * call site remembered to redirect.
 */
it('keeps stdout to one line through a step that writes to its own stdout', function () {
    $process = new Process([PHP_BINARY, packagePath('tests/Fixtures/emits-a-path.php')]);
    $process->setTimeout(60);
    $process->run();

    expect($process)->toHaveSucceeded()
        ->and($process->getOutput())->toBe("/Users/agent/project-worktrees/441-fix-login\n")
        ->and($process->getErrorOutput())
        ->toContain('creating worktree')
        ->toContain('resolving package 499')
        ->toContain('warning: something took a while');
});

it('writes to stdout from exactly one class', function () {
    expect(sourceFilesMatching('/\bSTDOUT\b|php:\/\/stdout/'))->toBe(['Console/Emitter.php']);
});

it('starts subprocesses from exactly one class', function () {
    expect(sourceFilesMatching('/Symfony\\\\Component\\\\Process/'))->toBe(['Process/ProcessRunner.php']);
});

/**
 * @return list<string> The src-relative paths whose contents match, sorted.
 */
function sourceFilesMatching(string $pattern): array
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(packagePath('src'), FilesystemIterator::SKIP_DOTS),
    );

    $matches = [];

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
            $matches[] = str_replace(packagePath('src').'/', '', $file->getPathname());
        }
    }

    sort($matches);

    return $matches;
}
