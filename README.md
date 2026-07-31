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
