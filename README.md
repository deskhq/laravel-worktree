# Per-worktree isolation so multiple agents can work on a Laravel project concurrently

[![Latest Version on Packagist](https://img.shields.io/packagist/v/deskhq/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/deskhq/laravel-worktree)
[![GitHub Tests Action Status](https://github.com/deskhq/laravel-worktree/actions/workflows/run-tests.yml/badge.svg)](https://github.com/deskhq/laravel-worktree/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/deskhq/laravel-worktree/actions/workflows/pint.yml/badge.svg)](https://github.com/deskhq/laravel-worktree/actions?query=workflow%3APint+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/deskhq/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/deskhq/laravel-worktree)

Give every git worktree of a Laravel project its own ports, containers and environment, so several agents can work on the same repository at the same time without fighting over the machine.

## What actually collides

Most of the isolation you need already exists, and knowing which part is missing is what makes the rest of this design legible.

Git worktrees already isolate the working tree. Sail already isolates containers, networks and named volumes per Compose project, keyed off `COMPOSE_PROJECT_NAME`. Two worktrees of the same repository are almost entirely independent before this package does anything at all.

Two things are not:

- **Host port bindings.** Every published port in `compose.yaml` binds the same host port in every worktree. The second one to start dies on `Bind for :::5432 failed`, minutes into a bootstrap, naming a port nobody configured.
- **`vendor/` and `node_modules/`.** They live in the working tree, they are enormous, and they are not interchangeable between branches.

So that is the whole job: hand each worktree a unique block of host ports and its own Compose project, then install its dependencies. Everything else in this package is in service of doing that without surprising the application it runs inside.

## It runs on the host, and it has to

This is the first thing worth understanding, because it is otherwise the first issue anyone files.

The implementation is a binary — `vendor/bin/worktree` — and not `php artisan worktree:create`. Under Sail, artisan runs **inside the app container**, and from in there every single thing this package needs is unreachable: there is no Docker socket to create containers with, no host git to add a worktree to, no sibling worktrees directory, and no way to bind a host port. A worktree also has no `vendor/` at the moment it is created, so there is no application to boot and no artisan to run in the first place.

`php artisan worktree:{create,list,remove,reap}` do exist, as a facade. Called from inside the container they refuse and tell you what to run instead:

```
worktree must run on the host, not inside the container.
Use:  ./vendor/bin/worktree create 441
```

Laravel is never booted by the binary. Nothing in it depends on an application that may not be installed yet, or may be broken — which is also why `config/worktree.php` may use `env()` but may not reach for a facade or a container binding.

## Requirements

| | |
| --- | --- |
| `git` | worktrees, and the ref resolution behind `create <slug> [base]` |
| `docker` | Compose **v2.24 or newer** — the overlay uses the `!override` merge tag, and an older Compose merges `depends_on` instead of replacing it, silently starting everything. Checked before anything is generated. |
| PHP on the host | the binary is PHP, and it runs outside the container. `ext-pcntl` is required, for the signal handling that releases locks on an interrupted run. |
| `laravel/sail` | a soft dependency: detected, never required, and deliberately not in this package's `require`. |
| `gh` | **optional.** It enriches a numeric slug with the issue title. Absent, logged out, offline, or pointed at a repository with no such issue are all the same ordinary answer. |

No `jq`. The bash original this replaces needed it; nothing here does.

## Installation

```bash
composer require --dev deskhq/laravel-worktree
```

That installs the host binary at `vendor/bin/worktree`. Publish the config with:

```bash
php artisan vendor:publish --tag="worktree-config"
```

The published `config/worktree.php` is the real documentation for this package — every key is commented where it sits, along with the traps that are not obvious until they have cost you an afternoon. A repository that publishes nothing at all still runs, on the defaults that file documents.

For a fully worked example rather than a commented blank, see [`stubs/worktree-example.php`](stubs/worktree-example.php) — the real configuration of the application this package was extracted from, including its eleven-step bootstrap. A test in this package's own suite loads it through the same validator the binary uses, so it cannot quietly stop being legal.

There is also an escape-hatch script to publish, once you need one:

```bash
php artisan vendor:publish --tag="worktree-stubs"
chmod +x bin/worktree-playwright
```

The `chmod` is not optional: `vendor:publish` copies with the umask's permissions rather than the source file's, so a published script arrives non-executable and a `host` step invoking it fails on permissions rather than on anything to do with the script.

## Usage

```bash
cd "$(./vendor/bin/worktree create 441)"
./vendor/bin/worktree list
./vendor/bin/worktree remove 441
./vendor/bin/worktree reap
```

Only machine-readable output — the path from `create`, the table from `list` — reaches stdout. Every diagnostic, and the whole output of every subprocess it runs, goes to stderr, so `cd "$(...)"` keeps working on a run that also produced megabytes of Composer and npm output.

Exit codes: `0` success, `1` operational failure, `64` usage error. An interrupted run (`130`) or a terminated one (`143`) still releases everything it was holding.

`worktree:reap` is the one to run from the binary rather than through artisan when you want to be asked before anything is destroyed: the facade gives the binary pipes rather than your terminal, so a `reap` under it has nobody to confirm with and says so rather than assuming. `--yes` and `--dry-run` work either way.

## Creating, resuming and re-entering

```bash
worktree create <slug> [base] [--refresh] [--json]
```

`create` prints the worktree's **absolute path**, alone, on stdout — that is the whole of what a caller has to parse:

```bash
cd "$(./vendor/bin/worktree create 441)"
```

`--json` emits the worktree's registry entry instead, on one line:

```json
{"project":"wt-the-desk-441-fix-login","slot":0,"repo":"/Users/…/the-desk","slug":"441-fix-login","branch":"441-fix-login","path":"/Users/…/the-desk-worktrees/441-fix-login","ports":{"app":20000,"vite":20001,"reverb":20002,"db":20003,"redis":20004},"created_at":"2026-07-30T22:04:11Z","degraded":[]}
```

That is also why there is no MCP server: an agent that can run a process can already read this.

### Three entry states

| The worktree is | What `create` does |
| --- | --- |
| **ready** — it has a `.worktree-ready` | Short-circuits: re-reads `HEAD`, retries whatever is recorded as degraded, prints the path. A healthy one makes no Docker call at all. |
| **registered but not ready** — a run was interrupted | Resumes on the *same* slot and the *same* path. The recipe runs again from the top, and step sentinels make what already finished cheap to skip. |
| **unregistered** | Allocates a slot, claims it, bootstraps. |

`--refresh` turns the first into the second, so a changed `config/worktree.php` takes effect on a worktree that is already ready. It does not delete step sentinels: a `sentinel` means "done once, and never again", and dropping them would re-run `migrate:fresh --seed` over real data.

A registry entry whose directory somebody has deleted by hand is none of the three — it is forgotten and made again, with a line saying so. Git's own record of that worktree is cleared first, or `git worktree add` would refuse the path as *missing but already registered*; the branch is untouched, so nothing committed in there is lost.

### The order, and what holds a lock

1. the per-worktree lock
2. the entry: resumed, or a slot allocated under the registry lock and released immediately after
3. `git worktree add`, and `HEAD` verified
4. the `.env` — only if the worktree has none
5. the Compose overlay — regenerated every run, because ports may have moved
6. the runtime boots
7. the step pipeline
8. `.worktree-ready`
9. the path on stdout, and then any degrade notices on stderr

The per-worktree lock is taken before anything is read and held until the process exits, so a stray second `create 441` waits and then re-enters rather than racing the first through git, Composer, Sail and npm in one directory. The registry lock covers the free-slot search and the claim that follows it and nothing else — held across a five-minute bootstrap, it would serialise every unrelated worktree on the machine.

## Listing

```bash
worktree list [--all] [--json]
```

One row per worktree on stdout, in slot order:

```
KEY                      SLOT  APP    VITE   REVERB  DB     REDIS  BRANCH         PATH
wt-the-desk-441-fix-log  0     20000  20001  20002   20003  20004  441-fix-login  /Users/…/the-desk-worktrees/441-fix-login
wt-the-desk-feat-search  1     20010  20011  20012   20013  20014  feat/search    /Users/…/the-desk-worktrees/feat-search
```

The port columns are whatever `ports` names, in the order it names them, so a repository that publishes a `meilisearch` port gets a `MEILISEARCH` column without this command knowing anything about it. Fields go out tab-separated and are aligned by `column -t`; where there is no `column`, the tabs are what you get, and `awk -F'\t'` reads the same fields either way.

It shows the checkout it was run from. `--all` widens it to the machine — which the machine-global registry is what makes meaningful, and which is the fastest way to answer *what is holding port 20012?*, since that port may well belong to a clone in somebody else's terminal.

`--json` emits the registry entries instead, on one line, as `create --json` emits one:

```bash
worktree list --json | jq -r '.[] | select(.degraded | length > 0) | .project'
```

An empty registry is a line on stderr rather than a header with nothing under it — except under `--json`, where `[]` is the answer a script asked for.

### The orphan warning

A worktree torn down by hand, a teardown interrupted partway, or a registry file that was lost, all leave the same thing behind: containers and volumes under a `wt-` project name that no entry claims. `list` names them, on **stderr**:

```
2 projects of the-desk still on this daemon that no worktree claims:
  wt-the-desk-441-fix-login  4 containers, 3 volumes
  wt-the-desk-feat-checkout  0 containers, 3 volumes
'worktree reap' removes them
```

Which is what makes `reap` discoverable at the moment it is relevant, rather than after the disk fills. It is on stderr because the table is a contract: a diagnostic printed between rows would be read as a row, and `worktree list | wc -l` would count it.

The scan is the one `reap` destroys by — a warning about something `reap` would not touch teaches people to ignore the warning, and the reverse is worse. So it is scoped identically: the `wt-` marker, narrowed to this repository unless `--all`, minus everything the registry claims from any checkout. It is silent when there is nothing to say, and silent when there is no daemon to ask: with nothing answering, a clean bill of health would be inferred rather than established.

## Removing

```bash
worktree remove <slug>
```

Containers, volumes, the worktree directory and the slot. **The branch stays** — the work is the point and the infrastructure is disposable, so `git worktree remove` runs with `--force` and nothing in this command touches a ref. Nothing reaches stdout either: there is no answer for a script to read, only an exit code.

The order, and every position in it is load-bearing:

1. the per-worktree lock, taken before the registry is read and held until the process exits, so a concurrent `create` for the same key waits instead of slotting a replacement entry in behind the teardown
2. the [teardown](#teardown-and-why-it-proves-rather-than-trusts), which proves rather than trusts
3. `git worktree remove --force`, tolerating a path git will not remove
4. `git worktree prune`, or the next `git worktree add` is refused the path as *missing but already registered*
5. the registry entry deleted
6. **exit non-zero if the teardown left anything behind**, naming it and naming `reap`

Step 6 is the point. The git side has genuinely finished either way, so the slot really is free; what failed is the disk, and both halves are said separately because an operator acts on each:

```
error: wt-the-desk-441-fix-login survived teardown: 1 volume (wt-the-desk-441-fix-login_sail-pgsql) could not be removed
wt-the-desk-441-fix-login is out of the registry and its slot is free — the git side finished — but its containers and volumes are not accounted for; 'worktree reap' is the way back to them
```

The same exit code covers the daemon that could not be asked at all, because an unreachable daemon proves nothing about what is on its disk.

Freeing the slot while staying silent about surviving volumes is exactly the bug this replaces: the version before it logged *"compose teardown reported nothing to remove (already down?)"* on any non-zero exit and deleted the entry anyway (the-desk#1095).

### It works with no registry entry

The registry is a convenience, not the source of truth, and both facts a teardown needs are derivable from the name: the Compose project is `wt-<repo-slug>-<slug>`, and the worktree is a directory named after the slug beside the checkout. So a key nothing holds is a line on stderr rather than a refusal:

```
nothing in the registry holds wt-the-desk-441-fix-login, so this run goes by the name it was given: the project wt-the-desk-441-fix-login, and the worktree at /Users/…/the-desk-worktrees/441-fix-login
```

Refusing was the bug it fixes: a worktree somebody removed by hand — or one whose entry a *failed* teardown had already deleted — had no supported way to reclaim its volumes at all. Where the derived directory is not there, the sibling `*-worktrees` directories are globbed for one holding that slug, which is what a `repo_slug` set since the worktree was created leaves behind; a match is believed only once git confirms it is a worktree of *this* repository, because two checkouts side by side have sibling directories that look identical from the filesystem alone.

An entry another checkout holds is refused rather than destroyed — a key is a Compose project name, so removing it from here would tear down that checkout's containers.

## Reaping

```bash
worktree reap [--all] [--dry-run] [--yes]
```

The catch-all `remove` never gets to: a worktree torn down by hand, a teardown interrupted partway, a registry file somebody lost. Each leaves the same thing behind, and before this there was no supported way back to it.

```
$ worktree reap
found 2 orphaned projects for the-desk:
  wt-the-desk-441-fix-login  4 containers, 3 volumes
  wt-the-desk-feat-checkout  0 containers, 3 volumes
destroy these? [y/N]
```

**It force-deletes Docker volumes and there is no undo**, so its scoping is the most deliberate thing in the package.

### Why the project name, and not a label of our own

Stamping our own label on what we create and reaping by it is the obvious design, and it does not work:

- service-level `labels:` in the overlay land on **containers**, not volumes;
- labelling volumes would mean enumerating every volume `compose.yaml` declares — the coupling the label-query teardown exists to avoid;
- **anonymous volumes**, the ones an image's `VOLUME` directive produces, cannot carry a custom label at all.

The one label that covers every volume a project owns is `com.docker.compose.project`, whose value is ours because we write `COMPOSE_PROJECT_NAME`. So scoping keys on the project *name*, and the `wt-` prefix in it is a safety mechanism rather than a style choice: it is a literal no configuration can change or remove, which is why [`repo_slug`](#names) names the repository *inside* the key rather than replacing it.

A project is eligible only when all three hold:

1. its name matches `wt-<repo-slug>-*` for this repository — `--all` widens that to `wt-*`;
2. it has containers or volumes on this daemon;
3. no registry entry claims it, from *any* checkout.

So `app-441` is never in scope, under any configuration, and colliding with an unrelated Compose project takes somebody having deliberately named theirs `wt-`. It is the same scan `list` warns by, deliberately: a warning about something `reap` would not touch teaches people to ignore the warning, and the reverse is worse.

### The gate

`--dry-run` reports and makes no destructive call at all. `--yes` agrees in advance, for CI. A run with no terminal and no `--yes` is an **error** rather than an implied yes — a cron job that force-deletes volumes because nobody was there to object is the failure this command is built around. Anything but a clear `y` at the prompt is a no.

### The re-check under the lock

A scan is a snapshot, and nothing is destroyed on it. Each project is torn down holding **the same per-key lock `create` takes**, with the registry re-read inside it, so a bootstrap that started in between is skipped rather than reaped out from under itself:

```
skipping wt-the-desk-feat-checkout: a worktree claimed it after the scan — slot 2, at /Users/…/the-desk-worktrees/feat-checkout — so it is not an orphan any more
```

One key's lock at a time, released before the next is taken, so reaping a machineful of projects does not hold up every unrelated command for the duration.

A volume that would not go is named and exits non-zero, as it does in `remove`. Nothing to reap is a clean exit 0 — and a daemon that could not be reached says *that*, rather than reporting a machine with nothing on it, because an unanswered daemon proves nothing about what is on its disk.

An append-only "projects we created" ledger was considered and rejected: it has the registry's failure mode — lose it and orphans become permanently unreachable, which is the hole this command exists to close. Derivable scoping degrades better than bookkeeping.

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

`WORKTREE_HOME` moves the machine-global registry and its locks, which live in `~/.laravel-worktree` by default, and `WORKTREE_COMPOSER_IMAGE` names the throwaway image a fresh worktree gets its first `vendor/` from ([the runtime](#the-runtime)).

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

That one is checked rather than left to be read — see [the published-port pre-flight](#the-published-port-pre-flight).

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

### The published-port pre-flight

The second of the [two traps](#two-traps) is a rule the package has everything it needs to check: it has the configuration, and the application's `compose.yaml` is right there. So it checks it, before a create claims a slot and before a single file is generated.

It first works out which services the worktree will actually run:

1. the app service — `APP_SERVICE`, or Sail's `laravel.test`;
2. whatever that service depends on, which is `compose.keep_services` when the overlay trims it and `compose.yaml`'s own list when it does not;
3. every service a bootstrap step brings up by hand — `['sail' => 'up -d reverb']`, which no `depends_on` anywhere mentions;
4. and then the transitive closure of `depends_on` over all of that.

Then, for each of those, it reads every `ports:` mapping that names a host port and pulls the host-side variable out of it: `'${FORWARD_REDIS_PORT:-6379}:6379'` → `FORWARD_REDIS_PORT`. A mapping is covered when `env` assigns that variable a `{{port.*}}` placeholder, or when the service has a `port_overrides` entry — which replaces its whole `ports:` list, so what `compose.yaml` published for it no longer applies. Anything else is refused:

```
error: config/worktree.php: 1 published host port would be the same in every worktree, so the
second one to start would die on a Docker bind error naming a port nothing here configures:

  redis publishes '${FORWARD_REDIS_PORT:-6379}:6379'
    started because reverb depends on it, and the bootstrap step 'sail up -d reverb' starts it
    add 'FORWARD_REDIS_PORT' => '{{port.redis}}' to 'env'
```

Step 4 is the whole point, and the reason a refusal names the chain rather than only the service: `redis` is in nothing the application can see — it arrives because `reverb` depends on it, and `reverb` arrives because a bootstrap step starts it. Without this the failure surfaces on the *second* worktree, minutes into a bootstrap, as `Bind for :::6379 failed`.

A refusal rather than a warning, because a warning here is one nobody reads until the bind error arrives anyway. Two cases get their own message, because *"add it to `env`"* is no help in either. A literal host port — `'8025:8025'`, with no variable in it — has nothing for `env` to assign, so `port_overrides` is the only fix. And a variable `env` assigns a **fixed** value to is not offset by having been mentioned: that is [trap 1](#two-traps) exactly, `REVERB_PORT` pinned for the container's sake and never remapped on the published side.

The check is pure parsing: no daemon, nothing to start, and no cost to a create that passes it. Two limits follow from that. `include:` and `extends:` are not followed, so a service reached only through one of those is invisible to it. And profiles are modelled only far enough to keep a bare `sail up -d` from counting a profiled service as started.

### Not `compose.override.yaml`

Compose auto-loads that name. Writing it would silently clobber the one an application already has — and since it is usually tracked, the damage would show up as an unexplained modification in the worktree's `git status`.

`laravel/sail` ships the mechanism built for exactly this: `bin/sail` reads a colon-separated `SAIL_FILES` and turns each entry into a `-f` argument. So the overlay takes a name nothing else claims, and the worktree's `.env` gets

```
SAIL_FILES=compose.yaml:compose.worktree.yaml
```

Note the sharp edge in it: passing any `-f` disables Compose's own file discovery, and Sail adds no implicit `compose.yaml`, so the application's own file has to lead the list. Whichever of Compose's four legal names it uses is discovered — `compose.yaml`, `compose.yml`, `docker-compose.yaml`, `docker-compose.yml`, in Sail's own order — and an application that already sets `SAIL_FILES` has ours **appended** to its list rather than substituted for it.

The generated file is added to the repository's `.git/info/exclude`, unless a published `.gitignore` already covers it, so a worktree with an overlay still has a clean `git status`.

## The runtime

What brings a worktree up and what takes it down again sits behind one interface, and `SailRuntime` is the only implementation there is:

```php
interface Runtime
{
    public function boot(Identity $worktree, string $environmentFile): void;
    public function teardown(Identity $worktree): TeardownResult;
    public function allocatesPorts(): bool;
    public function shell(): Shell;
}
```

Without containers there are no host ports to collide over, nothing to tear down and nothing to reap — so a non-Sail mode is a genuinely different product, deferred behind this seam rather than half-built inside the Sail one. `allocatesPorts()` is the question asked before anything reaches for a slot. `shell()` is the fourth method because what a `sail:` step has to be told before it will behave is the runtime's knowledge, not the pipeline's.

**Sail is a soft dependency.** It is detected, never required, and it is not in this package's `require`. An application without it gets a message naming the fix rather than a stack trace three minutes into a bootstrap.

### Boot

A fresh worktree has no `vendor/`, so it has no `vendor/bin/sail` either — and Sail is how everything after this runs. The way out is a throwaway Composer container, which is emphatically *not* the app runtime: it lacks the application's extensions and cannot run its post-install scripts, hence `--ignore-platform-reqs --no-scripts`. It produces just enough `vendor/` to obtain Sail, and the authoritative install is an ordinary bootstrap step inside the app container afterwards. `WORKTREE_COMPOSER_IMAGE` overrides the image, which defaults to `laravelsail/php84-composer:latest`.

Then `sail up -d <app service>`, with the `depends_on` the [Compose overlay](#the-compose-overlay) trimmed — so a worktree starts the services it needs and no others.

### What Sail already makes configurable

Each of these is a place the bash original hardcoded a value Sail lets an application choose, and each would be a silent failure in someone else's application:

| Sail's variable | What honouring it changes |
| --- | --- |
| `APP_SERVICE` | The app service is not always `laravel.test`. It is read from the worktree's own environment file — the one `bin/sail` sources — and used for both the overlay and `up`. |
| `SAIL_DOCKER_BINARY` | Sail's route to Podman. Every direct Docker call this package makes goes through it, including the `docker compose` / `docker-compose` probe, which falls back to `${SAIL_DOCKER_BINARY}-compose` exactly as `bin/sail` does. |
| `SAIL_FILES` | How the generated overlay reaches Compose at all — see above. |
| `WWWUSER` / `WWWGROUP` | Exported by `bin/sail` and interpolated by `compose.yaml`, and unset when *we* call Compose directly. Exported here too, or the teardown warns per variable and what Compose creates gets the wrong ownership. |

**Agent environment forwarding is already solved upstream.** `bin/sail` forwards `AI_AGENT`, `CLAUDECODE`, `CLAUDE_CODE`, `CURSOR_AGENT`, `GEMINI_CLI` and a dozen more into the container. Parallel agents are the entire point of this package, so the right move is to let Sail keep doing it and not reimplement it.

### `SAIL_SKIP_CHECKS`, and why it is set in exactly one place

Unless that variable is set, `bin/sail` runs this before every command:

```bash
if "${COMPOSE_CMD[@]}" ps "$APP_SERVICE" 2>&1 | grep 'Exit\|exited'; then
    echo "Shutting down old Sail processes..." >&2
    "${COMPOSE_CMD[@]}" down > /dev/null 2>&1
    EXEC="no"
elif [ -z "$("${COMPOSE_CMD[@]}" ps -q)" ]; then
    EXEC="no"
fi
```

One exited one-shot container in the project — a migration that ran and finished, a worker that stopped — matches that grep, and Sail takes the whole project **down** in the middle of the bootstrap that started it.

But setting it is not a blanket fix, because that same block is what sets `EXEC="no"`, and `EXEC` is how Sail knows whether there is a container to `exec` into. Skip the checks before anything is up and Sail reaches into a container that does not exist. (In v1.63 `EXEC="no"` makes Sail refuse with *Sail is not running*; older releases fell back to `compose run --rm`. Either way, before `up` the answer is wrong.)

So it is set for bootstrap steps and for nothing else: `boot()` runs `sail up -d` without it, and every step the pipeline runs comes after that.

### Teardown, and why it proves rather than trusts

```
docker compose -p <project> down --volumes --remove-orphans   # output kept, shown on failure
docker rm --force --volumes <container>                       # one call per container
docker volume rm --force <volume>                             # one call per volume
                                                              # then re-query, and report survivors
```

`remove` exits non-zero when anything is still there, naming it. The version this replaced logged *"compose teardown reported nothing to remove (already down?)"* on **any** non-zero exit and carried on to delete the registry entry, so a failure was indistinguishable from a clean run until the disk filled — and by then nothing pointed at the volumes any more (the-desk#1095).

Three details that look like style and are not:

- **One `docker rm` per resource.** A single `docker rm a b c` abandons the whole batch when one id is unknown, and the survivors are precisely what this reports on.
- **Label queries, not a hardcoded volume list.** Everything Compose creates carries `com.docker.compose.project` — named volumes and the anonymous ones an image's `VOLUME` directive produces alike. One label query answers "what is still on disk for this worktree?" without knowing what `compose.yaml` declares.
- **An unreachable daemon is not a clean teardown.** With nothing to ask, the sweep removes nothing and the re-query finds nothing, which would read as proof that the disk is clean. That case is refused by name instead. Every other Docker query answers *empty* rather than throwing, because those same queries back `list`, and a listing is not worth failing a run over.

## The bootstrap

The recipe is `steps` in `config/worktree.php`, run in the order it is declared. This is the mechanism that keeps one application's bootstrap out of this package: `composer install`, `artisan migrate`, `npm run build`, the generated TypeScript and the browser binaries are all configuration, and none of them is a class in here.

```php
'steps' => [
    ['label' => 'Installing PHP dependencies',
     'sail'  => 'composer install',
     'sentinel' => '.worktree-installed'],

    ['label' => 'Generating the application key',
     'sail'  => 'artisan key:generate --force',
     'when'  => 'env_empty:APP_KEY'],

    ['label' => 'Installing node modules',
     'host'  => 'npm ci',
     'when'  => 'missing:node_modules'],
],
```

| Key | Meaning |
| --- | --- |
| `host` | run on the host, with the worktree as the working directory |
| `sail` | run in the container, through the worktree's own `./vendor/bin/sail` |
| `sail_root` | run in the container as root, through `sail root-shell -c` |
| `label` | the progress line; without one, a step is named by its command |
| `sentinel` | skip if this file exists in the worktree; touch it on success |
| `when` | `missing:<path>`, `exists:<path>` or `env_empty:<KEY>` |
| `allow_failure` | a failure does not abort the bootstrap |
| `degrade` | re-printed at the very end when this step failed |

Every string takes the `{{path}}`, `{{slug}}`, `{{project}}`, `{{branch}}`, `{{uid}}`, `{{gid}}` and `{{port.*}}` placeholders, and the **whole recipe is resolved before the first step starts** — a typo in the last step is an error now, not after eleven minutes of Composer and npm.

The host/container split is not a nicety. `mkdir -p resources/js/generated` and a throwaway `docker run … composer install` are host commands, `artisan migrate` is not, and any real recipe has both.

A `sentinel` says "done once, never again"; a `when` says "needed right now". `npm ci` wants the second — it is worth re-running whenever `node_modules` has been thrown away. A sentinel is only touched when its step succeeded, and it is excluded from git like every other file this package writes.

### Seeding is a branch, not a skip

```php
['sail' => 'artisan migrate:fresh --seed --force', 'sentinel' => '.worktree-seeded'],
['sail' => 'artisan migrate --force', 'when' => 'exists:.worktree-seeded'],
```

The first drops the schema *because* the sentinel is missing: no seed has ever finished against those tables, so a half-finished one's leftover rows — the source of `duplicate key value violates unique constraint` on the retry — are safe to discard. Once the sentinel is there the data is real, and only new migrations run.

`env_empty`, meanwhile, is not `env_missing`: a freshly copied `.env` carries `APP_KEY=`, so the key is present and blank, and a condition that only fired on an absent variable would never fire at all.

### Degrading, and the retry that follows

`allow_failure` lets the bootstrap finish without a step. `degrade` is what makes that honest — the notice is held back and printed as the **last** thing on screen, after the path, instead of scrolling away behind four minutes of asset building:

```
One step did not complete, and the bootstrap carried on without it:
  - Playwright is not fully installed; tests/Browser will not run here
Re-entering this worktree retries just those; nothing else runs again.
```

Degraded steps are recorded in the worktree's registry entry, and re-entering the worktree runs those and only those — a step usually degrades because a registry was unreachable or a download timed out, and the next run is the natural moment to try again. A step that failed *without* `allow_failure` stops the bootstrap where it stands, names its exit code, and leaves the worktree resumable.

### Where the DSL stops

Two things it deliberately cannot say.

**Finally-semantics.** A restore that must run even when the thing it brackets failed — parking a container's third-party apt sources around an install, say. `allow_failure` means "ignore this failure", not "run this regardless".

**Probe-gating.** A decision made by reading another command's exit code, such as whether Chromium is already installed where `PLAYWRIGHT_BROWSERS_PATH` puts it. No `sentinel` can ask that.

Both are exotic, and covering them would mean adding `always:` and `when: shell_fails:<cmd>` — inventing a worse programming language, then documenting and testing it. So the escape hatch is a script the application owns, invoked as one ordinary step:

```php
['host' => 'bin/worktree-playwright {{path}}',
 'allow_failure' => true,
 'degrade' => 'Playwright is not fully installed; tests/Browser will not run here'],
```

## Migrating from a bash script

This package was extracted from one, and the translation is the best available description of where the seam is. A thousand lines of `bin/worktree` became one `config/worktree.php` and one escape-hatch script — [`stubs/worktree-example.php`](stubs/worktree-example.php) is the result, and it is the file to read alongside this table.

| In the script | Here |
| --- | --- |
| `SLOTS`, `PORT_BASE` | `slots`, `port_base` |
| `DEFAULT_BASE` | `base_branch` |
| `WORKTREE_STARTED_SERVICES`, and the `depends_on` in `write_override` | `compose.keep_services` |
| the `reverb.ports` block in `write_override` | `compose.port_overrides` |
| every `upsert_env` in `write_env` | `env` |
| `detach_unstarted_services` | `env`, with blank hosts and container-free drivers |
| `migrate_and_seed` | two `steps`: `sentinel` on the seed, `when: exists:` on the migrate |
| `install_playwright_browsers`, and the apt park/restore | `bin/worktree-playwright`, as one step with `allow_failure` and `degrade` |
| the install sequence in `cmd_create` | `steps` |
| `slugify`, `gh issue view` | built in — see [Names](#names) |
| `find_free_slot`, `acquire_lock`, the registry | built in |
| `teardown_project`, `orphan_projects`, `cmd_reap` | built in — `remove` and `reap` |
| `emit`, and parking stdout on fd 3 | built in — the stdout contract holds for every step you configure |
| `require_tooling` | built in, and no `jq`: it was the script's only hard dependency and nothing here needs it |

### Four behaviour changes, one of which bites

| Change | Consequence |
| --- | --- |
| `compose.override.yaml` → `compose.worktree.yaml` + `SAIL_FILES` | stops clobbering an override file the application owns |
| a per-repository registry → the machine-global one in `~/.laravel-worktree` | one-time migration; the old file is not read, and moving it by hand is the whole of it |
| `DEFAULT_BASE=master` → `base_branch`, defaulting to the repository's default branch | **not the no-op it looks like** — see below |
| project `desk-<NNN>` → `wt-<repo-slug>-<slug>` | **existing worktrees' Docker resources become unreachable by this tool** |

**The base branch.** A script that hardcoded `master` and a package that asks git for the default branch agree only when your integration branch *is* your default branch. If work is cut from `develop` while GitHub's default is `master`, leaving `base_branch` unset forks every worktree from whatever `master` currently is — which may be many commits behind, and the omission surfaces later as a conflict or a missing commit rather than as an error. Set it explicitly:

```php
'base_branch' => env('WORKTREE_BASE', 'develop'),
```

**Reap before you switch.** This is the one that costs you disk you cannot find again. `reap` can only scope itself by Compose project name, and it only ever looks at projects carrying the `wt-` marker. Any project the old script created is invisible to it — so run the **old** script's `reap` while you still have it:

```bash
bin/worktree reap        # the OLD script, before deleting it
```

If it is already gone, the resources are still reachable by hand, one project at a time:

```bash
docker volume ls --filter 'label=com.docker.compose.project=<old-project>'
docker compose -p <old-project> down --volumes --remove-orphans
```

## Testing

```bash
composer test
vendor/bin/pest --parallel
```

**No Docker in the suite.** Nothing here requires a daemon: it runs on a laptop
with Docker Desktop closed, and CI stops the daemon before running it. Every
Docker interaction is asserted through a fake binary that records its argv and
replays canned output, which is what makes the properties worth asserting
observable at all — one `docker rm` per resource, survivors re-queried after a
teardown, `--dry-run` reaching no destructive call. A case that declares no fake
of its own gets one that refuses loudly rather than falling through to whatever
`docker` is on `PATH`.

**Real git, everywhere it matters.** The half that cannot be faked usefully: an
upstream carrying `master` and `develop`, cloned so the clone knows `develop`
only as `remotes/origin/develop`, with `develop` carrying a commit `master` does
not — so a fork from the wrong base is caught by SHA rather than only by branch
name. That is the regression suite for the-desk#619 and the-desk#639 — see
[What a worktree forks from](#what-a-worktree-forks-from) — and it fails if
either fix is reverted.

**`$HOME` is pinned per case.** Everything shared between runs — the registry,
the global lock directory, the per-key locks — hangs off `$HOME`, so pinning it
is what keeps parallel cases out of each other's state and any case at all out
of your real `~/.laravel-worktree`.

**Failures carry stderr.** Every diagnostic this tool emits goes to stderr, and
asserting on the exit code alone throws all of it away, so
`expect($process)->toHaveSucceeded()` reports what the run actually said.

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
