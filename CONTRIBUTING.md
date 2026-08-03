# Contributing

Thanks for taking the time. This is a small package with a narrow job, so the most useful thing you can do before writing code is open an issue describing the problem you hit — several things that look like bugs are decisions with reasons, and the README says which.

## Getting set up

```bash
git clone git@github.com:deskhq/laravel-worktree.git
cd laravel-worktree
composer install
```

You need PHP 8.4 or newer with `ext-pcntl`, and `git`. **You do not need Docker.** Nothing in the suite talks to a daemon: every Docker interaction is asserted through a fake binary that records its argv and replays canned output, and CI stops the daemon before running the suite to keep it that way.

## Before opening a pull request

```bash
composer test        # vendor/bin/pest
vendor/bin/pest --parallel
composer analyse     # phpstan, at the level the baseline pins
composer format      # pint
```

Run the suite both ways. `--parallel` gives each file its own process, which is what catches a case depending on state — or on a helper — that another file left behind.

CI runs the same three, across PHP 8.4/8.5 and Laravel 12/13 at both `prefer-lowest` and `prefer-stable`. Three checks are required to merge: **`tests`**, which reports for that whole matrix, **`phpstan`**, and **`pint`**.

## Tests

Every behaviour change needs a test. A few conventions this suite holds to, which reviewing your own diff against will save a round trip:

- **Assert what the run said, not only that it failed.** `expect($process)->toHaveSucceeded()` reports stderr on failure; a bare exit-code assertion throws away the only diagnostic there was.
- **Nothing may reach the developer's machine.** `$HOME` is pinned per case, and a case that declares no fake `docker` gets one that refuses loudly rather than falling through to whatever is on `PATH`. `tests/HarnessTest.php` asserts these properties directly — if you add a way around them, assert it there.
- **Real git where it matters.** Ref resolution is tested against actual repositories, because a fork from the wrong base is only catchable by SHA.
- **Helpers used by more than one file live in `tests/Pest.php`**, and an arch test enforces it.

## Commits and releases

Releases are cut by [release-please](https://github.com/googleapis/release-please) from commit messages, so **commit subjects must follow [Conventional Commits](https://www.conventionalcommits.org/)**:

```
feat: reap command — orphan scan, human gate, and a re-check under the lock
fix: stop reading the .env's own APP_ENV as one the shell exported
```

`feat:` and `fix:` appear in the changelog; `chore:`, `docs:`, `test:`, `refactor:` and `ci:` do not. A breaking change needs `!` after the type, or a `BREAKING CHANGE:` footer. Pull requests are squash-merged, so **the pull request title is the commit message that lands** — it is the one that has to be conventional.

Do not edit `CHANGELOG.md` or bump a version by hand. release-please owns both.

## Style

Pint, on the default Laravel preset, plus what the codebase already does: explicit return types and parameter types everywhere, constructor property promotion, curly braces on every control structure, PHPDoc blocks over inline comments.

Run `composer format` before pushing. CI checks the same thing with `pint --test` and fails if anything is unformatted; it does not style the branch for you. It used to, and the commit it pushed arrived with no `tests` or `phpstan` on it — GitHub does not start a workflow run for a push made with its own token — which left the pull request waiting on required checks that would never start.

## Documentation

The README is the design document, not a feature list: it explains *what collides* and *why each decision is the way it is*. If your change makes a statement in it untrue, change the statement in the same pull request.

[`CONTEXT.md`](CONTEXT.md) is the smaller thing underneath it: the words this package uses — *key*, *slot*, *entry*, *fleet*, *orphan* — and what each one is allowed to mean. A concept that needs a new word belongs there before it belongs in a class name.
