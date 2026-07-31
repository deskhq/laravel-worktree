<?php

/**
 * Configuration for deskhq/laravel-worktree.
 *
 * This file is read by `vendor/bin/worktree` on the host, with no application
 * booted: there is no container, no service provider and no facade, because the
 * worktree being built may not have a `vendor/` yet. So it may use `env()` —
 * the binary defines a shim that casts exactly as Laravel does — but it may not
 * reference application classes, container bindings or facades. A test enforces
 * that; a config that reaches for one fails on the host.
 *
 * Every key below is optional. A repository with no copy of this file at all
 * runs on precisely these values.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Slots and ports
    |--------------------------------------------------------------------------
    |
    | Each worktree takes a slot from the machine-global registry in
    | WORKTREE_HOME (default ~/.laravel-worktree), and a slot owns a block of
    | host ports: port_base + slot * port_stride + the port's index in `ports`.
    | Fifty slots at stride ten is 20000-20499.
    |
    | The registry is machine-global rather than per-repository because host
    | ports are: two clones of the same repository must not both take slot 0.
    |
    */

    'slots' => env('WORKTREE_SLOTS', 50),

    'port_base' => env('WORKTREE_PORT_BASE', 20000),

    'port_stride' => 10,

    'ports' => ['app', 'vite', 'reverb', 'db', 'redis'],

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | base_branch is what new worktrees fork from; null means this repository's
    | own default branch. repo_slug names the repository in registry keys and
    | Compose project names; null means the main working tree's directory name.
    | Project names always carry a literal `wt-` marker that no configuration
    | can remove — `worktree reap` force-deletes volumes and scopes itself by
    | that marker.
    |
    */

    'base_branch' => env('WORKTREE_BASE'),

    'repo_slug' => null,

    /*
    |--------------------------------------------------------------------------
    | Generated .env
    |--------------------------------------------------------------------------
    |
    | Written into a new worktree's .env once, and never again — a resumed
    | bootstrap must not revert someone's debugging edits. Values may use the
    | {{port.*}}, {{project}}, {{slug}}, {{branch}} and {{path}} placeholders.
    |
    | This is where a worktree's published ports get offset, and where the
    | services it never starts get pointed away from:
    |
    |     'APP_PORT'             => '{{port.app}}',
    |     'COMPOSE_PROJECT_NAME' => '{{project}}',
    |     'MAIL_MAILER'          => 'log',
    |
    */

    'env' => [],

    /*
    |--------------------------------------------------------------------------
    | Compose overlay
    |--------------------------------------------------------------------------
    |
    | Written as compose.worktree.yaml and passed through SAIL_FILES, so an
    | application's own compose.override.yaml is left alone. keep_services trims
    | the app service's depends_on; port_overrides remaps a host port that .env
    | cannot handle because the same variable is also read inside the container:
    |
    |     'keep_services'  => ['pgsql', 'redis'],
    |     'port_overrides' => ['reverb' => ['{{port.reverb}}:8080']],
    |
    */

    'compose' => [
        'keep_services' => [],
        'port_overrides' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap steps
    |--------------------------------------------------------------------------
    |
    | The recipe, in order. Each step runs exactly one command — `host` on the
    | host with the worktree as its working directory, `sail` in the container,
    | `sail_root` as root in the container — and may carry:
    |
    |     'label'         => shown while it runs
    |     'sentinel'      => skip if this file exists in the worktree; touch on success
    |     'when'          => missing:<path> | exists:<path> | env_empty:<KEY>
    |     'allow_failure' => a failure does not abort the bootstrap
    |     'degrade'       => re-printed at the very end when this step failed
    |
    | Anything the DSL cannot say — a restore that must run even on failure, a
    | decision made from another command's exit code — belongs in a script the
    | application owns, invoked as a single `host` step.
    |
    */

    'steps' => [],

];
