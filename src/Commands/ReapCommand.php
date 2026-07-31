<?php

namespace DeskHQ\LaravelWorktree\Commands;

class ReapCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:reap {arguments?* : Options forwarded to the host binary}';

    /** @var string */
    protected $description = 'Remove stray worktree projects left on this machine — runs on the host';

    protected function hostCommand(): string
    {
        return 'reap';
    }
}
