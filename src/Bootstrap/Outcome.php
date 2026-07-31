<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

use DeskHQ\LaravelWorktree\Console\Output;

/**
 * What a bootstrap left behind: the steps that failed and were allowed to.
 *
 * Two things depend on this. The person watching, who needs to be told that the
 * worktree they are about to work in is not quite whole — as the *last* thing
 * on screen, not four minutes of asset building ago (the-desk#1005). And the
 * next run, which retries exactly these and nothing else, because a step that
 * degraded usually did so over a network that has since come back.
 */
final readonly class Outcome
{
    /**
     * @param  list<array{name: string, notice: string|null}>  $degraded  In the order they ran.
     */
    public function __construct(public array $degraded = []) {}

    /**
     * The names to record, and to hand back as `$only` on re-entry.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (array $degradation): string => $degradation['name'], $this->degraded);
    }

    public function isComplete(): bool
    {
        return $this->degraded === [];
    }

    /**
     * Say what degraded, and say it last.
     *
     * Deferred to the caller rather than printed as it happens, because "last"
     * means after the path `create` prints — a notice that scrolls away behind
     * the rest of the bootstrap is a notice nobody reads, which is the whole
     * bug this exists for.
     */
    public function announce(Output $output): void
    {
        if ($this->degraded === []) {
            return;
        }

        $count = count($this->degraded);

        $output->line();
        $output->line($count === 1
            ? 'One step did not complete, and the bootstrap carried on without it:'
            : "$count steps did not complete, and the bootstrap carried on without them:");

        foreach ($this->degraded as $degradation) {
            $output->line('  - '.($degradation['notice'] ?? $degradation['name'].' failed'));
        }

        $output->line('Re-entering this worktree retries just those; nothing else runs again.');
    }
}
