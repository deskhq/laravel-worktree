<?php

namespace DeskHQ\LaravelWorktree\Commands;

class StartCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:start {arguments?* : The slug of the worktree to bring back up}';

    /** @var string */
    protected $description = 'Bring a stopped worktree back up, without bootstrapping it — runs on the host';

    protected function hostCommand(): string
    {
        return 'start';
    }
}
