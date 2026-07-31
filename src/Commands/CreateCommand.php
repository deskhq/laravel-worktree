<?php

namespace DeskHQ\LaravelWorktree\Commands;

class CreateCommand extends WorktreeCommand
{
    /** @var string */
    protected $signature = 'worktree:create {arguments?* : The slug, and optionally the base ref}';

    /** @var string */
    protected $description = 'Create (or re-enter) an isolated worktree — runs on the host';

    protected function hostCommand(): string
    {
        return 'create';
    }
}
