<?php

namespace DeskHQ\LaravelWorktree\Git;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * Where the repository actually lives, resolved from the shared git directory.
 *
 * Every command anchors here first, so the binary behaves identically whether
 * it was invoked from the main checkout or from inside one of the worktrees it
 * created: `--git-common-dir` points at the main `.git` from both, and its
 * parent is the main working tree.
 */
final readonly class Anchor
{
    private function __construct(
        /** The shared `.git` directory, absolute. */
        public string $commonDir,
        /** The main working tree — the checkout the worktrees hang off. */
        public string $mainRoot,
    ) {}

    /**
     * @throws WorktreeException when $workingDirectory is not inside a git repository.
     */
    public static function resolve(ProcessRunner $runner, string $workingDirectory): self
    {
        $inside = $runner->capture(['git', 'rev-parse', '--is-inside-work-tree'], $workingDirectory);

        if (! $inside->succeeded() || $inside->trimmedOutput() !== 'true') {
            throw new WorktreeException('not inside a git repository');
        }

        $commonDir = $runner->capture(['git', 'rev-parse', '--git-common-dir'], $workingDirectory);

        if (! $commonDir->succeeded() || $commonDir->trimmedOutput() === '') {
            throw new WorktreeException('could not resolve the git directory');
        }

        // `--git-common-dir` answers relative to the working directory when it
        // can (plain `.git` from the main checkout), absolute from a worktree.
        $path = $commonDir->trimmedOutput();
        $resolved = realpath(str_starts_with($path, '/') ? $path : $workingDirectory.'/'.$path);

        if ($resolved === false) {
            throw new WorktreeException("the git directory ({$path}) does not exist");
        }

        return new self($resolved, dirname($resolved));
    }
}
