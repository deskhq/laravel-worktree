<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\UnlockCommand as HostUnlockCommand;

class UnlockCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:unlock
        {arguments?* : The worktree whose lock to remove, forwarded to the host binary}
        {--all : Take every lock on this machine, not just this worktree\'s}
        {--force : Remove it even though its holder may still be running}';

    /** @var string */
    protected $description = 'Remove a lock a run never gave back — runs on the host';

    protected function hostCommand(): string
    {
        return 'unlock';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        $flags = [];

        foreach ([HostUnlockCommand::All, HostUnlockCommand::Force] as $flag) {
            if ($this->option($flag) === true) {
                $flags[] = '--'.$flag;
            }
        }

        return $flags;
    }
}
