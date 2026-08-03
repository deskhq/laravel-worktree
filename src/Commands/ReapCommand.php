<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\ReapCommand as HostReapCommand;

class ReapCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:reap
        {arguments?* : Options forwarded to the host binary}
        {--all : Reap every wt- project on this machine, not just this repository\'s}
        {--dry-run : Report what would be destroyed, and touch nothing}
        {--yes : Skip the confirmation, for a run with nobody watching it}';

    /** @var string */
    protected $description = 'Remove stray worktree projects, and reclaim the slots nothing is behind — runs on the host';

    protected function hostCommand(): string
    {
        return 'reap';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        $flags = [];

        foreach ([HostReapCommand::All, HostReapCommand::DryRun, HostReapCommand::Yes] as $flag) {
            if ($this->option($flag) === true) {
                $flags[] = '--'.$flag;
            }
        }

        return $flags;
    }
}
