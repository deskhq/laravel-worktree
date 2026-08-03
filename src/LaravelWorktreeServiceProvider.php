<?php

namespace DeskHQ\LaravelWorktree;

use DeskHQ\LaravelWorktree\Commands\CreateCommand;
use DeskHQ\LaravelWorktree\Commands\DoctorCommand;
use DeskHQ\LaravelWorktree\Commands\InitCommand;
use DeskHQ\LaravelWorktree\Commands\ListCommand;
use DeskHQ\LaravelWorktree\Commands\PathCommand;
use DeskHQ\LaravelWorktree\Commands\ReapCommand;
use DeskHQ\LaravelWorktree\Commands\RemoveCommand;
use DeskHQ\LaravelWorktree\Commands\StartCommand;
use DeskHQ\LaravelWorktree\Commands\StopCommand;
use DeskHQ\LaravelWorktree\Commands\UnlockCommand;
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
                PathCommand::class,
                ListCommand::class,
                StopCommand::class,
                StartCommand::class,
                RemoveCommand::class,
                ReapCommand::class,
                UnlockCommand::class,
                DoctorCommand::class,
                InitCommand::class,
            ]);
    }

    /**
     * Publish the escape-hatch scripts an application owns once it has them.
     *
     * These are starting points rather than package internals: published, then
     * edited. `vendor:publish` copies with the umask's permissions rather than
     * the source file's, so a published script needs `chmod +x` before a `host`
     * step can invoke it.
     */
    public function packageBooted(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../stubs/worktree-playwright' => base_path('bin/worktree-playwright'),
        ], 'worktree-stubs');
    }
}
