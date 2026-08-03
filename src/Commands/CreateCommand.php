<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\CreateCommand as HostCreateCommand;

class CreateCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:create
        {arguments?* : The slug, and optionally the base ref}
        {--pr : Read the argument as a pull request number, and check its head branch out}
        {--refresh : Run the whole bootstrap again on a worktree that is already ready}
        {--json : Emit the worktree\'s registry entry instead of its path}';

    /** @var string */
    protected $description = 'Create (or re-enter) an isolated worktree — runs on the host';

    protected function hostCommand(): string
    {
        return 'create';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        $flags = [];

        foreach ([HostCreateCommand::PullRequest, HostCreateCommand::Refresh, HostCreateCommand::Json] as $flag) {
            if ($this->option($flag) === true) {
                $flags[] = '--'.$flag;
            }
        }

        return $flags;
    }
}
