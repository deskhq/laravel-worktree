<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\InitCommand as HostInitCommand;

class InitCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:init
        {arguments?* : Options forwarded to the host binary}
        {--dry-run : Print what would be written, and write nothing}
        {--force : Replace a config/worktree.php that is already there}';

    /** @var string */
    protected $description = 'Write config/worktree.php from this repository\'s compose.yaml — runs on the host';

    protected function hostCommand(): string
    {
        return 'init';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        $flags = [];

        foreach ([HostInitCommand::DryRun, HostInitCommand::Force] as $flag) {
            if ($this->option($flag) === true) {
                $flags[] = '--'.$flag;
            }
        }

        return $flags;
    }
}
