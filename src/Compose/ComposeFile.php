<?php

namespace DeskHQ\LaravelWorktree\Compose;

/**
 * Which file an application describes its services in.
 *
 * Compose accepts four names, and `laravel/sail` carries all four in
 * `InteractsWithDockerComposeServices::$composePaths` — the list below is that
 * one, in its order. The bash original hardcoded `compose.yaml`, which is right
 * for one application and silently wrong for the other three names: the file
 * Compose was pointed at does not exist, so the teardown it was tearing down
 * with had nothing to tear down, and the containers stayed up.
 */
final readonly class ComposeFile
{
    /**
     * @var list<string>
     */
    public const array Names = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    /**
     * What an application with none of them is assumed to have — the name
     * `sail install` writes, and the one Compose itself prefers.
     */
    public const string Default = 'compose.yaml';

    /**
     * The name of the Compose file in $root, first match winning.
     *
     * A name, not a path: it goes into `SAIL_FILES`, which `bin/sail` resolves
     * against the worktree it runs in.
     */
    public static function in(string $root): string
    {
        foreach (self::Names as $name) {
            if (is_file($root.'/'.$name)) {
                return $name;
            }
        }

        return self::Default;
    }

    private function __construct() {}
}
