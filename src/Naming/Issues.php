<?php

namespace DeskHQ\LaravelWorktree\Naming;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * The optional enrichment of D2: an issue number, named by `gh` when it can be.
 *
 * `create 441` is the argument people actually type, and `441-fix-login` is the
 * name they want to see in `git branch` three days later — so the title is
 * looked up, once, and folded into the slug.
 *
 * It is an enrichment and never a dependency. `gh` absent, logged out, offline,
 * pointed at a repository that has no issue 441, or upgraded into printing
 * something else are all the same ordinary answer, and all of them name the
 * worktree `issue-<n>` exactly as the bash original did. That is also why the
 * lookup runs through {@see ProcessRunner::consult()}: `gh: command not found`
 * on the diagnostics would read as a failure of the run rather than as the
 * absence of a tool nothing required.
 */
final readonly class Issues
{
    public function __construct(
        private ProcessRunner $runner,
        private Output $output,
        private Anchor $anchor,
    ) {}

    /**
     * The slug for issue $number: `441-fix-login` when `gh` can name it,
     * `issue-441` when it cannot.
     */
    public function slug(string $number): string
    {
        $title = $this->title($number);

        if ($title === null) {
            $this->output->line("gh could not name issue $number; the worktree will be issue-$number");

            return Slug::of('issue-'.$number);
        }

        return Slug::of($number.'-'.$title);
    }

    /**
     * The title `gh` reports for $number, or null when it reports anything else.
     */
    private function title(string $number): ?string
    {
        $result = $this->runner->consult(
            ['gh', 'issue', 'view', $number, '--json', 'title'],
            $this->anchor->mainRoot,
        );

        if (! $result->succeeded()) {
            return null;
        }

        $reported = json_decode($result->trimmedOutput(), true);
        $title = is_array($reported) ? ($reported['title'] ?? null) : null;

        return is_string($title) && trim($title) !== '' ? $title : null;
    }
}
