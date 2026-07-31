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

```bash
./vendor/bin/worktree
```

The commands are not implemented yet.

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
