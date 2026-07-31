<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Config\Env;

/**
 * The handful of escape sequences this package is willing to write, and the
 * three conditions under which it writes none of them.
 *
 * Colour here is hierarchy rather than decoration: a header that weighs the same
 * as its data is a header somebody has to count columns from. That is worth a
 * few bytes of SGR and not worth a dependency — `symfony/console` would bring a
 * formatter, a terminal abstraction and a renderer into a package that
 * deliberately hand-rolls its {@see Application}, {@see Command} and
 * {@see Arguments} and never boots Laravel.
 *
 * ## When there is no colour
 *
 * - The stream is not a terminal. Escape sequences in a pipe are bytes the
 *   reader did not ask for, and `awk` would take them as part of a field.
 * - `NO_COLOR` is set to anything non-empty (https://no-color.org).
 * - `TERM` is `dumb`, which is a terminal saying it cannot render this.
 *
 * The first is the caller's to determine — {@see Emitter::isInteractive()} for
 * stdout, and stderr has its own answer — so it arrives as an argument; the
 * other two are the environment's and are read here.
 */
final readonly class Style
{
    /** Half-bright, for a header and for the line that names the worktrees root. */
    private const string Dim = "\e[2m";

    private const string Reset = "\e[0m";

    private function __construct(private bool $enabled) {}

    /**
     * The style a stream gets, given whether a terminal is on the far side of it.
     */
    public static function forStream(bool $isTerminal): self
    {
        return new self($isTerminal && ! self::isPlain());
    }

    public function dim(string $text): string
    {
        return $this->enabled ? self::Dim.$text.self::Reset : $text;
    }

    /**
     * Whether this environment has asked for no colour at all.
     *
     * `NO_COLOR` is honoured on presence rather than on value — the convention
     * is that any non-empty setting disables colour, so `NO_COLOR=false` still
     * means no colour and is not an invitation to parse it.
     */
    private static function isPlain(): bool
    {
        $suppressed = Env::get('NO_COLOR');

        if ($suppressed !== null && $suppressed !== '') {
            return true;
        }

        return Env::get('TERM') === 'dumb';
    }
}
