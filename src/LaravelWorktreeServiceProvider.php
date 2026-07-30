<?php

namespace DeskHQ\LaravelWorktree;

use DeskHQ\LaravelWorktree\Commands\LaravelWorktreeCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelWorktreeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-worktree')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_worktree_table')
            ->hasCommand(LaravelWorktreeCommand::class);
    }
}
