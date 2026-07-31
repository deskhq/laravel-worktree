<?php

namespace DeskHQ\LaravelWorktree\Git;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * Keeping a generated file out of `git status`.
 *
 * A worktree this package has written into should still look untouched to the
 * person working in it. An unexplained entry in `git status` is at best noise
 * in every `git add -A`, and at worst a generated file committed — which is how
 * one developer's host ports end up in everybody else's checkout.
 *
 * Note that `info/exclude` is one of the paths git keeps in the *common*
 * directory (`path.c`: `info/` is shared, `info/sparse-checkout` the one
 * exception), so this is repository-wide however it is reached — there is no
 * per-worktree exclude file to write instead. That is tolerable for a name only
 * this package ever writes, and it is why the entry is added once and then left
 * alone rather than rewritten per worktree.
 */
final readonly class Excludes
{
    /** The comment written above the entry, so a reader knows what put it there. */
    public const string Marker = '# added by laravel-worktree';

    public function __construct(
        private ProcessRunner $runner,
        private Output $output,
    ) {}

    /**
     * Make sure git ignores $file inside the worktree at $path.
     *
     * Never fatal: an entry that could not be written costs a line in `git
     * status`, and abandoning a bootstrap over it would cost the bootstrap.
     */
    public function ensure(string $path, string $file): void
    {
        // The application may already have published it in .gitignore, which is
        // the tidier place for it and the one `worktree` has no business
        // duplicating.
        if ($this->runner->quiet(['git', 'check-ignore', '--quiet', '--', $file], $path) === 0) {
            return;
        }

        $exclude = $this->excludeFile($path);

        if ($exclude === null) {
            $this->output->line("warning: could not find this repository's exclude file; $file will show up in git status");

            return;
        }

        $content = is_file($exclude) ? (string) @file_get_contents($exclude) : '';

        if (in_array($file, array_map(trim(...), explode("\n", $content)), true)) {
            return;
        }

        $trimmed = rtrim($content, "\n");
        $addition = ($trimmed === '' ? '' : $trimmed."\n\n").self::Marker."\n".$file."\n";

        // Locked, because two `create` runs in different worktrees of the same
        // repository share this file and would otherwise interleave.
        if (@file_put_contents($exclude, $addition, LOCK_EX) === false) {
            $this->output->line("warning: could not write $exclude; $file will show up in git status");

            return;
        }

        $this->output->line("excluding $file from git in $exclude");
    }

    /**
     * Where this repository's `info/exclude` is, asked of git rather than
     * assembled by hand: a worktree's `.git` is a file pointing elsewhere, and
     * `--git-path` is what knows where.
     */
    private function excludeFile(string $path): ?string
    {
        $result = $this->runner->capture(['git', 'rev-parse', '--git-path', 'info/exclude'], $path);

        if (! $result->succeeded() || $result->trimmedOutput() === '') {
            return null;
        }

        $answer = $result->trimmedOutput();
        $absolute = str_starts_with($answer, '/') ? $answer : $path.'/'.$answer;

        return is_dir(dirname($absolute)) ? $absolute : null;
    }
}
