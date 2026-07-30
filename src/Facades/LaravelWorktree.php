<?php

namespace DeskHQ\LaravelWorktree\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \DeskHQ\LaravelWorktree\LaravelWorktree
 */
class LaravelWorktree extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \DeskHQ\LaravelWorktree\LaravelWorktree::class;
    }
}
