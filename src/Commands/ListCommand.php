<?php

namespace DeskHQ\LaravelWorktree\Commands;

class ListCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:list {arguments?* : Options forwarded to the host binary}';

    /** @var string */
    protected $description = 'Show this repository\'s worktrees, slots and ports — runs on the host';

    protected function hostCommand(): string
    {
        return 'list';
    }
}
