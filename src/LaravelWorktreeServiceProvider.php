<?php

namespace DeskHQ\LaravelWorktree;

use DeskHQ\LaravelWorktree\Commands\CreateCommand;
use DeskHQ\LaravelWorktree\Commands\ListCommand;
use DeskHQ\LaravelWorktree\Commands\ReapCommand;
use DeskHQ\LaravelWorktree\Commands\RemoveCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelWorktreeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-worktree')
            ->hasConfigFile()
            ->hasCommands([
                CreateCommand::class,
                ListCommand::class,
                RemoveCommand::class,
                ReapCommand::class,
            ]);
    }
}
