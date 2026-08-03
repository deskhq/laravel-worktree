<?php

namespace DeskHQ\LaravelWorktree\Shell;

use DeskHQ\LaravelWorktree\Config\Env;
use DeskHQ\LaravelWorktree\Exceptions\UsageException;

/**
 * The shells `shell-init` can emit for.
 *
 * Two, and both of them because the `wt` function is the same POSIX text in
 * either: only the completion differs, and only in how a function is bound to a
 * command name. Fish is neither — its functions, its `complete -c` and its lack
 * of `$(...)` would be a third script rather than a third registration — so it
 * is refused by name here rather than emitted half-working.
 */
enum Dialect: string
{
    case Bash = 'bash';

    case Zsh = 'zsh';

    /**
     * The dialect $name asks for.
     *
     * @throws UsageException when it is a shell this cannot write for.
     */
    public static function named(string $name): self
    {
        return self::tryFrom($name) ?? throw new UsageException(
            "no shell integration is written for '$name'; this emits ".self::known()
        );
    }

    /**
     * What the caller is running, from `$SHELL`, or null when that names
     * something else — a login shell nobody asked us about is not an error, it
     * is a reason to ask which shell they meant.
     */
    public static function current(): ?self
    {
        $shell = Env::get('SHELL');

        return is_string($shell) ? self::tryFrom(basename($shell)) : null;
    }

    /**
     * The shells this knows, as a sentence names them.
     */
    public static function known(): string
    {
        return implode(' and ', array_map(fn (self $dialect): string => $dialect->value, self::cases()));
    }
}
