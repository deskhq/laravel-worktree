<?php

namespace DeskHQ\LaravelWorktree\Tests;

use DeskHQ\LaravelWorktree\LaravelWorktreeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelWorktreeServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        //
    }
}
