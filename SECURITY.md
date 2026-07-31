# Security Policy

## Reporting a vulnerability

Report it privately, through [GitHub's private vulnerability reporting](https://github.com/deskhq/laravel-worktree/security/advisories/new). That opens a draft advisory only you and the maintainers can see.

If that form is unavailable to you, email **emmanuel.paul@outlook.com** with `laravel-worktree` in the subject.

Please do not open a public issue for a vulnerability, and please do not report it in Discussions.

You should get an acknowledgement within a week. This is a small package with one maintainer, so a fix may take longer than an acknowledgement does — you will be told which is happening.

## Supported versions

Before 1.0, only the latest release is supported. Fixes land on `main` and go out in the next release; there are no backport branches.

## What is in scope

This tool runs **on the host**, not inside a container, and that is the whole shape of its attack surface. It reads and writes `~/.laravel-worktree`, adds git worktrees beside your checkout, generates a `.env` and a Compose overlay for each one, and shells out to `git`, `docker` and `composer`. Anything that lets one of those reach further than the worktree it was invoked for is in scope. Concretely, that includes:

- A repository slug, branch name or issue title that escapes quoting and reaches a shell.
- A path — a worktree name, a base ref, a configured directory — that escapes the sibling worktrees directory and writes elsewhere on the host.
- Registry or lock handling that lets one worktree read, corrupt or take another's slot, including under a concurrent run.
- Secrets from the main checkout's `.env` being copied somewhere they should not be, or being written world-readable.

## What is not

- **`config/worktree.php` executes host commands by design.** The bootstrap is a list of commands the application itself declares. Cloning an untrusted repository and running `worktree create` in it runs whatever that file says, on your machine, outside any container. That is what the feature is; treat a repository's bootstrap config exactly as you treat its `composer.json` scripts.
- Anything that requires an attacker to already have write access to your checkout, your `~/.laravel-worktree`, or your Docker socket. At that point the tool is not the weakest thing available to them.
- Denial of service against your own laptop — exhausting slots, ports or disk by creating many worktrees.
