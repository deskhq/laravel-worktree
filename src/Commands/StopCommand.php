<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\StopCommand as HostStopCommand;

class StopCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:stop
        {arguments?* : The slug of the worktree to stop, or the one to keep running}
        {--all : Stop every worktree in scope rather than one named worktree}
        {--all-except : Stop every worktree but the one named}
        {--all-repos : Widen --all and --all-except to every worktree on this machine}';

    /** @var string */
    protected $description = 'Stop a worktree\'s containers, keeping everything else — runs on the host';

    protected function hostCommand(): string
    {
        return 'stop';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        $flags = [];

        foreach ([HostStopCommand::All, HostStopCommand::AllExcept, HostStopCommand::AllRepos] as $flag) {
            if ($this->option($flag) === true) {
                $flags[] = '--'.$flag;
            }
        }

        return $flags;
    }
}
