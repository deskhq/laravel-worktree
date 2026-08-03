<?php

namespace DeskHQ\LaravelWorktree\Commands;

class ShellInitCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:shell-init
        {arguments?* : The shell to write for; $SHELL when it is not given}';

    /** @var string */
    protected $description = "Emit the 'wt' function and completion for your shell — runs on the host";

    protected function hostCommand(): string
    {
        return 'shell-init';
    }
}
