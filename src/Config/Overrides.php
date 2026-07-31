<?php

namespace DeskHQ\LaravelWorktree\Config;

/**
 * The environment variables that beat the config file.
 *
 * These are the bash original's escape hatches, kept because they are what
 * people reach for when one run needs different numbers: `WORKTREE_SLOTS=5
 * vendor/bin/worktree create 441`. They resolve through {@see Env}, so a
 * variable exported on the command line and a variable written into `.env`
 * behave the same way, and both behave the same way under `artisan`.
 *
 * `WORKTREE_HOME` is deliberately absent here — it names where the machine's
 * registry lives, not something `config/worktree.php` may set, and is resolved
 * by {@see Configuration::home()}.
 */
final readonly class Overrides
{
    /**
     * Config key => the variable that overrides it.
     *
     * @var array<string, string>
     */
    public const array Variables = [
        'slots' => 'WORKTREE_SLOTS',
        'port_base' => 'WORKTREE_PORT_BASE',
        'base_branch' => 'WORKTREE_BASE',
    ];

    /**
     * @param  array<mixed>  $config
     * @return array<mixed>
     */
    public static function apply(array $config): array
    {
        foreach (self::Variables as $key => $variable) {
            $value = Env::get($variable);

            // An unset variable and one set to nothing both mean "no opinion";
            // the config file, or the default, still decides.
            if ($value === null || $value === '') {
                continue;
            }

            $config[$key] = $value;
        }

        return $config;
    }

    private function __construct() {}
}
