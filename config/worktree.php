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
    | Written into a new worktree once, and never again — a resumed bootstrap
    | must not revert someone's debugging edits. A key the file already sets is
    | replaced where it stands, comment above it intact; a key it does not set
    | is appended. Values may use the {{port.*}}, {{project}}, {{slug}},
    | {{branch}} and {{path}} placeholders, and an unknown one is an error
    | naming it rather than an empty string left in the file.
    |
    | This is where a worktree's published ports get offset, and where the
    | services it never starts get pointed away from:
    |
    |     'env' => [
    |         'APP_PORT'             => '{{port.app}}',
    |         'VITE_PORT'            => '{{port.vite}}',
    |         'FORWARD_DB_PORT'      => '{{port.db}}',
    |         'FORWARD_REDIS_PORT'   => '{{port.redis}}',
    |         'COMPOSE_PROJECT_NAME' => '{{project}}',
    |         'APP_URL'              => 'http://localhost:{{port.app}}',
    |
    |         // container-internal, deliberately NOT offset — see below
    |         'REVERB_PORT' => 8080,
    |
    |         // point away from services this worktree never starts
    |         'COMPOSE_PROFILES' => '',
    |         'MAIL_MAILER'      => 'log',
    |         'MAIL_HOST'        => '',
    |         'SCOUT_DRIVER'     => 'collection',
    |         'MEILISEARCH_HOST' => '',
    |         'LDAP_HOST'        => '',
    |     ],
    |
    | Every service Sail can start, and the host-side variable each publishes
    | on. These are host sides only: offset every one belonging to a service
    | this worktree starts, name a port for it in `ports` above, and keep
    | `port_stride` at least as large as that list.
    |
    |     laravel.test  APP_PORT (80), VITE_PORT (both sides — see below)
    |     mysql         FORWARD_DB_PORT (3306)
    |     mariadb       FORWARD_DB_PORT (3306)
    |     pgsql         FORWARD_DB_PORT (5432)
    |     mongodb       FORWARD_MONGODB_PORT (27017)
    |     redis         FORWARD_REDIS_PORT (6379)
    |     valkey        FORWARD_VALKEY_PORT (6379)
    |     memcached     FORWARD_MEMCACHED_PORT (11211)
    |     meilisearch   FORWARD_MEILISEARCH_PORT (7700)
    |     typesense     FORWARD_TYPESENSE_PORT (8108)
    |     minio         FORWARD_MINIO_PORT (9000), FORWARD_MINIO_CONSOLE_PORT (8900)
    |     rustfs        FORWARD_RUSTFS_PORT (9000), FORWARD_RUSTFS_CONSOLE_PORT (9001)
    |     mailpit       FORWARD_MAILPIT_PORT (1025), FORWARD_MAILPIT_DASHBOARD_PORT (8025)
    |     rabbitmq      FORWARD_RABBITMQ_PORT (5672), FORWARD_RABBITMQ_DASHBOARD_PORT (15672)
    |     soketi        PUSHER_PORT (6001), PUSHER_METRICS_PORT (9601) — see below
    |     selenium      publishes nothing
    |
    | The number in brackets is the container-internal port, which never moves.
    | Anything the application dials over the Compose network — DB_PORT,
    | REDIS_PORT, MAIL_PORT, MEILISEARCH_HOST, AWS_ENDPOINT — is that inner
    | port, and offsetting it points the application at nothing.
    |
    | VITE_PORT is the exception that looks like the trap below and is not:
    | Sail publishes '${VITE_PORT:-5173}:${VITE_PORT:-5173}', the same variable
    | on both sides, and laravel-vite-plugin reads VITE_PORT for the dev
    | server's own port — so both sides move together, on purpose.
    |
    | ## Trap 1: a variable that is both the host side and the inner one
    |
    | Reverb is the usual case. REVERB_PORT is the host side of
    | '${REVERB_PORT}:8080' in compose.yaml *and*, through
    | config/broadcasting.php, the port the application dials at reverb:<port>.
    | Offset it per worktree and the broadcaster is pointed at a port nothing
    | listens on: the realtime tests hang, with no error to explain it. Soketi
    | has the identical shape with PUSHER_PORT.
    |
    | The fix is to pin the inner value here and remap the host side in the
    | compose overlay below — which is the reason those two mechanisms are
    | separate:
    |
    |     'env'     => ['REVERB_PORT' => 8080],
    |     'compose' => ['port_overrides' => ['reverb' => ['{{port.reverb}}:8080']]],
    |
    | ## Trap 2: a service with a depends_on you did not account for
    |
    | A service can be started by something other than the application. Redis
    | often has to be offset not because anything talks to it from the host,
    | but because reverb pulls it in through its own depends_on, and it
    | publishes '${FORWARD_REDIS_PORT:-6379}:6379' when it does. The second
    | worktree to start reverb then dies on `Bind for :::6379 failed`. Every
    | published port on every transitively started service needs an entry here.
    |
    | Both traps are checked rather than left to be read. Before a create claims
    | a slot, the package works out which services the worktree actually starts
    | — the app service, keep_services below, whatever a `sail up -d <service>`
    | step brings up, and the transitive closure of depends_on over all of it —
    | and refuses when any of them publishes a host port nothing moves per
    | worktree, naming the service, the mapping and the chain that started it.
    | A variable pinned here without a {{port.*}} placeholder counts as unmoved:
    | mentioning it is not offsetting it.
    |
    */

    'env' => [],

    /*
    |--------------------------------------------------------------------------
    | Compose overlay
    |--------------------------------------------------------------------------
    |
    | The two things `.env` cannot say, written as compose.worktree.yaml and
    | handed to Compose through SAIL_FILES:
    |
    |     'compose' => [
    |         'keep_services'  => ['pgsql', 'redis'],
    |         'port_overrides' => ['reverb' => ['{{port.reverb}}:8080']],
    |     ],
    |
    | keep_services trims the depends_on of the app service — APP_SERVICE, or
    | Sail's laravel.test when the application does not set it — so that `sail
    | up -d` starts those services and no others. Sibling worktrees would
    | otherwise each bring the whole of compose.yaml up. An empty list is not
    | "depend on nothing": it leaves depends_on exactly as compose.yaml has it.
    |
    | port_overrides remaps a published port .env cannot, because the same
    | variable is also read inside the container — trap 1 above. The mappings
    | take the same placeholders as `env`.
    |
    | With neither set, no overlay is written and SAIL_FILES is not touched.
    | With either, the file is written on every create, kept out of git through
    | the repository's .git/info/exclude, and *appended* to any SAIL_FILES the
    | application already sets rather than replacing it. An application's own
    | compose.override.yaml is never written to: Compose auto-loads that name,
    | and silently clobbering it is the thing this mechanism exists to avoid.
    |
    | The overlay is written with the `!override` merge tag, so Docker Compose
    | must be at least 2.24 — checked before anything is generated, because an
    | older Compose merges the two depends_on lists instead of replacing them
    | and starts everything anyway.
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
    | Every string takes the {{path}}, {{slug}}, {{project}}, {{branch}},
    | {{uid}}, {{gid}} and {{port.*}} placeholders, and the whole recipe is
    | resolved before the first step starts — a typo in the last step is an
    | error now, not after eleven minutes of Composer and npm.
    |
    |     'steps' => [
    |         ['label' => 'Installing PHP dependencies',
    |          'sail'  => 'composer install',
    |          'sentinel' => '.worktree-installed'],
    |
    |         ['label' => 'Generating the application key',
    |          'sail'  => 'artisan key:generate --force',
    |          'when'  => 'env_empty:APP_KEY'],
    |
    |         ['label' => 'Installing node modules',
    |          'host'  => 'npm ci',
    |          'when'  => 'missing:node_modules'],
    |     ],
    |
    | A `sentinel` says "done once, never again"; a `when` says "needed right
    | now". `npm ci` wants the second: it is worth running again whenever
    | node_modules has been thrown away.
    |
    | ## Seeding is a branch, not a skip
    |
    | Two ordered steps, not one guarded step:
    |
    |     ['sail' => 'artisan migrate:fresh --seed --force', 'sentinel' => '.worktree-seeded'],
    |     ['sail' => 'artisan migrate --force', 'when' => 'exists:.worktree-seeded'],
    |
    | The first drops the schema *because* the sentinel is missing: no seed has
    | ever finished against those tables, so a half-finished one's leftover rows
    | — the source of `duplicate key value violates unique constraint` on the
    | retry — are safe to discard. Once the sentinel is there, the data is real
    | and only new migrations run.
    |
    | ## The APP_KEY gate is env_empty, not "missing"
    |
    | A freshly copied `.env` carries `APP_KEY=`: the key is present, and blank.
    | A condition that only fired on an absent variable would never fire.
    |
    | ## What belongs in a script instead
    |
    | Two things the DSL deliberately cannot say. A restore that has to run even
    | when the thing it brackets failed — parking a container's third-party apt
    | sources around an install, say — because `allow_failure` means "ignore
    | this failure", not "run this regardless". And a decision made by reading
    | another command's exit code, which no `when` can ask.
    |
    | Both belong in a script the application owns, invoked as one step:
    |
    |     ['host' => 'bin/worktree-playwright {{path}}',
    |      'allow_failure' => true,
    |      'degrade' => 'Playwright is not fully installed; tests/Browser will not run here'],
    |
    | `allow_failure` lets the bootstrap finish without that step; `degrade` is
    | what makes that honest, by re-printing the message as the last thing on
    | screen instead of leaving it four minutes back in the asset build. A
    | degraded step is recorded in the registry, and re-entering the worktree
    | retries it — and only it.
    |
    */

    'steps' => [],

];
