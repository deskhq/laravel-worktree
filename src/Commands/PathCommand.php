<?php

namespace DeskHQ\LaravelWorktree\Commands;

use DeskHQ\LaravelWorktree\Console\PathCommand as HostPathCommand;

class PathCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:path
        {arguments?* : The slug of the worktree to look up}
        {--json : Emit the worktree\'s registry entry instead of its path}';

    /** @var string */
    protected $description = 'Print where a worktree is, changing nothing — runs on the host';

    protected function hostCommand(): string
    {
        return 'path';
    }

    /**
     * @return list<string>
     */
    protected function flags(): array
    {
        return $this->option(HostPathCommand::Json) === true ? ['--'.HostPathCommand::Json] : [];
    }
}
