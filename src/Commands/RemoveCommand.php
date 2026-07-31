<?php

namespace DeskHQ\LaravelWorktree\Commands;

class RemoveCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:remove {arguments?* : The slug of the worktree to tear down}';

    /** @var string */
    protected $description = 'Tear down a worktree and free its slot — runs on the host';

    protected function hostCommand(): string
    {
        return 'remove';
    }
}
