# Per-worktree isolation so multiple agents can work on a Laravel project concurrently

[![Latest Version on Packagist](https://img.shields.io/packagist/v/deskhq/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/deskhq/laravel-worktree)
[![GitHub Tests Action Status](https://github.com/deskhq/laravel-worktree/actions/workflows/run-tests.yml/badge.svg)](https://github.com/deskhq/laravel-worktree/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/deskhq/laravel-worktree/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/deskhq/laravel-worktree/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/deskhq/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/deskhq/laravel-worktree)

Give every git worktree of a Laravel project its own ports, containers and environment, so several agents can work on the same repository at the same time without fighting over the machine.

## Installation

You can install the package via composer:

```bash
composer require deskhq/laravel-worktree
```

This installs the host binary at `vendor/bin/worktree`.

You can publish the config file with:

```bash
php artisan vendor:publish --tag="worktree-config"
```

## Usage

The binary is the implementation, and it runs on the host:

```bash
cd "$(./vendor/bin/worktree create 441)"
./vendor/bin/worktree list
./vendor/bin/worktree remove 441
```

Only machine-readable output — the path from `create`, the table from `list` — reaches stdout. Every diagnostic, and the whole output of every subprocess it runs, goes to stderr, so `cd "$(...)"` keeps working on a run that also produced megabytes of Composer and npm output.

Exit codes: `0` success, `1` operational failure, `64` usage error. An interrupted run (`130`) or a terminated one (`143`) still releases everything it was holding.

`php artisan worktree:{create,list,remove,reap}` delegate to that binary. Under Sail, artisan runs inside the app container — where there is no docker socket, no host git and no worktrees directory — so from in there they refuse and tell you what to run instead:

```
worktree must run on the host, not inside the container.
Use:  ./vendor/bin/worktree create 441
```

The commands themselves are not implemented yet; each one lands with its own issue.

## Configuration

Everything is optional. A repository with no `config/worktree.php` runs on the defaults below.

| Key | Default | What it is |
| --- | --- | --- |
| `slots` | `50` | How many worktrees the machine allocates slots for |
| `port_base` | `20000` | The first host port of slot 0 |
| `port_stride` | `10` | How many ports each slot reserves |
| `ports` | `['app', 'vite', 'reverb', 'db', 'redis']` | The ports each worktree publishes, in offset order |
| `base_branch` | the repository's default branch | What new worktrees fork from |
| `repo_slug` | the main working tree's directory name | Names this repository in registry keys and project names |
| `env` | `[]` | Variables written into a new worktree's `.env` |
| `compose` | `[]` | `keep_services` and `port_overrides` for the generated Compose overlay |
| `steps` | `[]` | The bootstrap recipe |

A port is `port_base + slot * port_stride + the port's index in ports`, so the defaults cover ports 20000–20499. An unknown or misspelled key is an error naming it, never a silent no-op.

Four environment variables override the file for a single run:

```bash
WORKTREE_SLOTS=5 WORKTREE_PORT_BASE=30000 WORKTREE_BASE=develop ./vendor/bin/worktree create 441
```

`WORKTREE_HOME` moves the machine-global registry and its locks, which live in `~/.laravel-worktree` by default.

### The one constraint

The binary reads that file **on the host, with no application booted** — it runs before the worktree it is building has a `vendor/`, and possibly with no database, no redis and no container running at all. It loads the main checkout's `.env` through `vlucas/phpdotenv` and defines an `env()` that casts exactly as Laravel's does, so `env('WORKTREE_PORT_BASE', 20000)` means the same thing from either entry point.

So `config/worktree.php` may use `env()`, and may not reference application classes, container bindings or facades. A test enforces it: the config is loaded in a process where none of them exist.

## Names

One argument in, every name a run needs out. A numeric argument is enriched with the issue title; anything else is used verbatim:

```bash
worktree create 441             # gh issue view 441 --json title  ->  441-fix-login
worktree create feat/checkout   # no lookup                       ->  feat-checkout
```

| Thing | Form | Example |
| --- | --- | --- |
| Registry key, and Compose project | `wt-<repo-slug>-<slug>` | `wt-the-desk-441-fix-login` |
| Worktree path | `<parent of the main checkout>/<repo-slug>-worktrees/<slug>` | `../the-desk-worktrees/441-fix-login` |
| Branch | numeric: the slug; named: the argument verbatim | `441-fix-login`, `feat/checkout` |

The branch deliberately differs from the slug for a named worktree: somebody typing `feat/checkout` means that branch, and slashes are legal in refs but not in directory names or Compose project names. `repo_slug` defaults to the main checkout's directory name, slugified.

A slug is lowercased, every run of non-alphanumerics collapsed to a single dash, no dash at either end, and cut to 50 characters — then stripped again, because the cut can land on a dash. What comes out is checked against Compose's own rule (`[a-z0-9][a-z0-9_-]*`) where it is built, rather than left for Docker to reject minutes into a bootstrap.

`gh` is an enrichment, never a dependency. Absent, logged out, offline, or pointed at a repository that has no issue 441 are all the same ordinary answer, and all of them name the worktree `issue-441`. What `gh` says about itself stays off the diagnostics, because an optional tool declining is not a failure of the run.

Two different arguments can slugify onto one name — `feat/checkout` and `feat-checkout` — and the second is refused rather than silently re-entering the first:

```
error: 'feat-checkout' and 'feat/checkout' both name 'wt-the-desk-feat-checkout', but they are different branches; the worktree at /Users/…/feat-checkout is on 'feat/checkout' — use that name, or pick one that slugifies differently
```

### The `wt-` marker is not configurable

`repo_slug` names the repository *inside* the key, and that is the whole of the influence a config file has over it.

The prefix is a safety mechanism rather than a style choice. `reap` force-deletes Docker volumes, and it can only scope itself by project name: service-level labels land on containers, labelling volumes would mean enumerating every volume `compose.yaml` declares, and anonymous volumes from a `VOLUME` directive cannot carry a custom label at all. The one label that covers every volume a project owns is `com.docker.compose.project` — whose value we control, because we write `COMPOSE_PROJECT_NAME`. With the marker fixed, overlapping with an unrelated Compose project on the same daemon requires somebody to have deliberately named theirs `wt-`.

## Slots, ports and the registry

Every worktree holds a slot, and a slot owns a block of host ports. Slots are handed out by a **machine-global** registry — `~/.laravel-worktree/registry.json`, moved with `WORKTREE_HOME` — keyed by Compose project name, with each entry recording the checkout it belongs to:

```json
{
  "wt-the-desk-441":       {"slot": 0, "repo": "/Users/…/the-desk", "ports": {"app": 20000, "…": 0}},
  "wt-shop-feat-checkout": {"slot": 1, "repo": "/Users/…/shop",     "ports": {"app": 20010, "…": 0}}
}
```

Machine-global rather than per-repository because host ports are: a per-repo registry gives two clones of the same repository slot 0 each, the same port block, and `Bind for :::20000 failed` on the second — and having two clones is exactly what a worktree tool encourages. Because every entry names its repository, `list` still shows one checkout's worktrees by default.

Before a slot is claimed, every port in its block is bind-probed, and a slot something already holds is skipped with a line naming the port. That also catches port users the registry cannot know about — a stray Postgres, a crashed container still holding a binding. It is a strong pre-flight hint, not a guarantee: Compose binds those ports minutes later, so this is deliberately TOCTOU.

Two `mkdir`-based locks — `flock` is absent on macOS — keep concurrent runs honest:

- the **registry lock** guards the free-slot search plus the claim that follows it, so two different worktrees never take the same slot. Held for milliseconds, and released before any of the slow work, so one repository's `composer install` never blocks another's allocation.
- a **per-worktree lock** serialises the whole create or remove of one worktree, so a second `create 441` waits for the first and then re-enters it rather than running git, Composer, Sail and npm alongside it in the same directory. Different worktrees take different locks.

A lock is only ever released by the process holding it, and both are released by the same shutdown handler — so an interrupted bootstrap (`130`) leaves nothing behind for the next run to trip over, while keeping its registry entry so that next run resumes the same slot.

Resuming tolerates entries written by an earlier version of this package: a port the current configuration declares and the entry does not is derived from the slot, not treated as corruption. The ports an entry *does* record win over the ones the slot would derive, because those are what its containers were published on.

## What a worktree forks from

Everything anchors to the **main** working tree first — `dirname(git rev-parse --git-common-dir)` — so the binary behaves identically whether you run it from the main checkout or from inside one of the worktrees it created.

The base is then resolved to a ref git cannot second-guess, because `git worktree add -b <new> <path> <base>` **DWIMs**: when `<base>` matches no local branch and exactly one remote-tracking branch, git creates a local `<base>` tracking the remote and checks *that* out, silently dropping `-b`. The worktree ends up on the shared release line and every commit made in it lands there. Resolution order:

1. `HEAD` and `@` resolve locally, and are used as-is — `refs/remotes/*/HEAD` exists in any clone, so a remote lookup would redirect them onto `origin/HEAD`.
2. `<base>` is fetched from every remote. Failures — offline, no such branch there — are non-fatal; resolution falls back to the refs already in the clone.
3. Exactly one `refs/remotes/*/<base>` wins, **including over a local branch of the same name**: a local `develop` that is merely behind origin forks the worktree from a stale baseline, and that surfaces later as a conflict or a missing commit rather than as an error.
4. Several remotes carry it → a refusal naming the candidates. Ambiguity is not guessed.
5. Otherwise anything `rev-parse` resolves to a commit — a tag, a SHA, an already-qualified `origin/develop`, or a local-only branch — else a refusal suggesting a fetch.

With no base given, `base_branch` / `WORKTREE_BASE` decides, and with those unset the repository's own default branch does (`refs/remotes/origin/HEAD`, falling back to the branch you are on).

Then, whatever git was asked to do, `HEAD` is re-read in the new worktree and the run is abandoned unless it is on the expected branch:

```
error: worktree /Users/…/441-fix-login is on 'develop', expected '441-fix-login' — refusing to continue, commits would land on the wrong branch
```

One `rev-parse`, and it runs on re-entry too — so a worktree someone switched branches in by hand is caught before a single bootstrap step touches it.

## The worktree's `.env`

Where a worktree stops sharing the machine with its siblings: `env` in `config/worktree.php` offsets the ports it publishes, names its Compose project, and points the services it never starts somewhere harmless.

```php
'env' => [
    'APP_PORT'             => '{{port.app}}',
    'VITE_PORT'            => '{{port.vite}}',
    'FORWARD_DB_PORT'      => '{{port.db}}',
    'FORWARD_REDIS_PORT'   => '{{port.redis}}',
    'COMPOSE_PROJECT_NAME' => '{{project}}',
    'APP_URL'              => 'http://localhost:{{port.app}}',

    'REVERB_PORT' => 8080,          // container-internal, deliberately not offset

    'COMPOSE_PROFILES' => '',       // and the services this worktree never starts
    'MAIL_MAILER'      => 'log',
    'SCOUT_DRIVER'     => 'collection',
    'MEILISEARCH_HOST' => '',
],
```

`{{project}}`, `{{slug}}`, `{{branch}}`, `{{path}}` and `{{port.<name>}}` for any name in `ports` all interpolate. An unknown one is an error naming the variable it came from, not an empty string left in the file — `APP_URL=http://localhost:` fails much later, in a browser, with nothing to connect it back to the typo.

**Generated once.** A worktree that already has the file keeps it, untouched. Re-entering a worktree is the normal way to resume an interrupted bootstrap, and a resume that reverted someone's debugging edits would be worse than no resume at all.

**Copied, then upserted.** The main checkout's `.env` is the starting point, falling back to the worktree's own `.env.example` with a diagnostic. Each variable then replaces the assignment already in the file — where it stands, comment above it intact — or is appended under a `# added by laravel-worktree` line. Sail's installer does positional `str_replace` on the strings its stub happens to ship with, which silently does nothing on a customised `.env`: the file is written, the ports are not in it, and the second worktree collides with the first for no visible reason.

`null` means a variable set to nothing (`MAIL_HOST=`), `true`/`false` are written as those words, and a value that needs quoting gets it, with `\`, `"` and `$` escaped so that phpdotenv and `source` read back the same string.

### Every port Sail publishes

Offset every host-side variable belonging to a service this worktree starts, give it a name in `ports`, and keep `port_stride` at least as large as that list.

| Service | Host-side variable | Container port |
| --- | --- | --- |
| `laravel.test` | `APP_PORT` | 80 |
| `laravel.test` | `VITE_PORT` | `VITE_PORT` — both sides |
| `mysql`, `mariadb` | `FORWARD_DB_PORT` | 3306 |
| `pgsql` | `FORWARD_DB_PORT` | 5432 |
| `mongodb` | `FORWARD_MONGODB_PORT` | 27017 |
| `redis` | `FORWARD_REDIS_PORT` | 6379 |
| `valkey` | `FORWARD_VALKEY_PORT` | 6379 |
| `memcached` | `FORWARD_MEMCACHED_PORT` | 11211 |
| `meilisearch` | `FORWARD_MEILISEARCH_PORT` | 7700 |
| `typesense` | `FORWARD_TYPESENSE_PORT` | 8108 |
| `minio` | `FORWARD_MINIO_PORT`, `FORWARD_MINIO_CONSOLE_PORT` | 9000, 8900 |
| `rustfs` | `FORWARD_RUSTFS_PORT`, `FORWARD_RUSTFS_CONSOLE_PORT` | 9000, 9001 |
| `mailpit` | `FORWARD_MAILPIT_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT` | 1025, 8025 |
| `rabbitmq` | `FORWARD_RABBITMQ_PORT`, `FORWARD_RABBITMQ_DASHBOARD_PORT` | 5672, 15672 |
| `soketi` | `PUSHER_PORT`, `PUSHER_METRICS_PORT` | 6001, 9601 |
| `selenium` | publishes nothing | — |

The container port never moves, so anything the application dials over the Compose network — `DB_PORT`, `REDIS_PORT`, `MAIL_PORT`, `MEILISEARCH_HOST`, `AWS_ENDPOINT` — keeps its default. `VITE_PORT` is the exception that looks like the trap below and is not: Sail publishes `'${VITE_PORT:-5173}:${VITE_PORT:-5173}'`, and `laravel-vite-plugin` reads `VITE_PORT` for the dev server's own port, so both sides move together on purpose.

### Two traps

**A variable that is both the host side and the container-internal one.** `REVERB_PORT` is the host side of `'${REVERB_PORT}:8080'` in `compose.yaml` *and*, through `config/broadcasting.php`, the port the application dials at `reverb:<port>`. Offset it per worktree and the broadcaster points at a port nothing listens on: the realtime tests hang, with no error. `soketi` has the identical shape with `PUSHER_PORT`. Pin the inner value in `env`, remap the host side in the Compose overlay — which is why those two mechanisms are separate.

**A service with a `depends_on` you did not account for.** Redis often has to be offset not because anything talks to it from the host, but because `reverb` pulls it in through its own `depends_on`, and it publishes `'${FORWARD_REDIS_PORT:-6379}:6379'` when it does. The second worktree to start reverb then dies on `Bind for :::6379 failed`. Every published port on every transitively started service needs an entry.

### `APP_ENV`, and the file Sail actually reads

```bash
if [ -n "$APP_ENV" ] && [ -f ./.env."$APP_ENV" ]; then
  source ./.env."$APP_ENV";
elif [ -f ./.env ]; then
  source ./.env;
fi
```

That is `bin/sail`, and `LoadEnvironmentVariables` agrees with it. On a machine with `APP_ENV` exported, ports written into `.env` would be read by nobody the moment `.env.<APP_ENV>` exists — every worktree keeps the default ports and collides, with nothing on screen to explain why. So when `APP_ENV` is exported, `.env.<APP_ENV>` is the file generated, which also makes it exist, which settles that `-f` test for good. It is the shell's `APP_ENV` that decides, never the one a `.env` sets, so it is captured before any `.env` is read.

## The Compose overlay

The two things `.env` cannot say. A worktree should start the services it needs and no others, which means trimming the app service's `depends_on` — otherwise every sibling worktree brings the whole of `compose.yaml` up, and the machine ends up running six copies of Meilisearch nobody asked for. And a host port whose variable is *also* read inside the container cannot be offset in `.env` at all, so it is remapped here, on the published mapping, leaving the inner value alone.

```php
'compose' => [
    'keep_services'  => ['pgsql', 'redis'],
    'port_overrides' => ['reverb' => ['{{port.reverb}}:8080']],
],
```

becomes `compose.worktree.yaml` in the worktree:

```yaml
services:
    laravel.test:
        depends_on: !override
            - pgsql
            - redis
    reverb:
        ports: !override
            - '20002:8080'
```

`keep_services` trims whichever service `APP_SERVICE` names, falling back to Sail's `laravel.test`; the mappings in `port_overrides` take the same placeholders as `env`. An empty `keep_services` is not "depend on nothing" — it leaves `depends_on` as `compose.yaml` declares it. With neither set, no overlay is written at all.

The `!override` merge tag is what makes this a replacement rather than a merge, and it needs Docker Compose >= 2.24. That is a pre-flight, with the version named, because an older Compose merges the two lists instead and quietly starts everything.

### Not `compose.override.yaml`

Compose auto-loads that name. Writing it would silently clobber the one an application already has — and since it is usually tracked, the damage would show up as an unexplained modification in the worktree's `git status`.

`laravel/sail` ships the mechanism built for exactly this: `bin/sail` reads a colon-separated `SAIL_FILES` and turns each entry into a `-f` argument. So the overlay takes a name nothing else claims, and the worktree's `.env` gets

```
SAIL_FILES=compose.yaml:compose.worktree.yaml
```

Note the sharp edge in it: passing any `-f` disables Compose's own file discovery, and Sail adds no implicit `compose.yaml`, so the application's own file has to lead the list. Whichever of Compose's four legal names it uses is discovered — `compose.yaml`, `compose.yml`, `docker-compose.yaml`, `docker-compose.yml`, in Sail's own order — and an application that already sets `SAIL_FILES` has ours **appended** to its list rather than substituted for it.

The generated file is added to the repository's `.git/info/exclude`, unless a published `.gitignore` already covers it, so a worktree with an overlay still has a clean `git status`.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Emmanuel Paul](https://github.com/emmpaul)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
