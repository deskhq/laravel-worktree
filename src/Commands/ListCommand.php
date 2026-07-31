<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\ListCommand as HostListCommand;

class ListCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:list
        {arguments?* : Options forwarded to the host binary}
        {--all : Show every worktree on this machine, not just this repository\'s}
        {--json : Emit the registry entries instead of the table}';

    /** @var string */
    protected $description = 'Show this repository\'s worktrees, slots and ports — runs on the host';

    protected function hostCommand(): string
    {
        return 'list';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        $flags = [];

        foreach ([HostListCommand::All, HostListCommand::Json] as $flag) {
            if ($this->option($flag) === true) {
                $flags[] = '--'.$flag;
            }
        }

        return $flags;
    }
}
