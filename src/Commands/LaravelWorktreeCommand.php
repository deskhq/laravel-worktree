<?php

namespace DeskHQ\LaravelWorktree\Commands;

use Illuminate\Console\Command;

class LaravelWorktreeCommand extends Command
{
    public $signature = 'laravel-worktree';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
