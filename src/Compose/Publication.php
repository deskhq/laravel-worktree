<?php

namespace DeskHQ\LaravelWorktree\Compose;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * One entry of a service's `ports:`, read for the only thing that can collide.
 *
 * A container port is private to the Compose network and every worktree may
 * have its own; a **host** port is the machine's, and two worktrees publishing
 * the same one means the second `up` dies on `Bind for :::6379 failed`. So the
 * host side is the whole of what this reads, and there are exactly three
 * answers:
 *
 * - **nothing** — `'3000'` and `'3000-3005'` name a container port and let
 *   Docker pick the host one, so nothing is ever taken twice. Read as `null`,
 *   and never reported.
 * - **a variable** — `'${FORWARD_REDIS_PORT:-6379}:6379'`. Offsettable in the
 *   worktree's `.env`, which is what `env` in `config/worktree.php` is for.
 * - **a literal** — `'8025:8025'`. Not offsettable in `.env` at all: there is
 *   no variable to assign, so the only fix is `compose.port_overrides`.
 *
 * The parsing is Compose's own grammar rather than a split on `:`, because the
 * mapping that matters most is the one a naive split gets wrong:
 * `${FORWARD_REDIS_PORT:-6379}` carries a colon of its own, inside `${…}`.
 */
final readonly class Publication
{
    private function __construct(
        /** The mapping as the Compose file writes it, so a message can name it. */
        public string $mapping,
        /** The variable the host side reads, or null when it is a literal port. */
        public ?string $variable,
    ) {}

    /**
     * One `ports:` entry, or null when it publishes no fixed host port.
     *
     * @param  mixed  $entry  A short-syntax string, a long-syntax map, or a bare container port.
     */
    public static function read(mixed $entry): ?self
    {
        // An application entitled to write `!override` in its own Compose file
        // is an application whose ports are still ports.
        if ($entry instanceof TaggedValue) {
            $entry = $entry->getValue();
        }

        if (is_array($entry)) {
            return self::long($entry);
        }

        // `- 3000` is the short syntax's container-port-only form written as a
        // number, which publishes on a host port Docker chooses.
        if (! is_string($entry) || trim($entry) === '') {
            return null;
        }

        return self::short(trim($entry));
    }

    /**
     * `'127.0.0.1:${APP_PORT:-80}:80/tcp'` — everything is optional except the
     * container port, and the host side is therefore the second-to-last
     * segment whenever there is more than one.
     *
     * Counting from the right rather than from the left is what makes a
     * bracketed IPv6 host address — `'[::1]:8080:80'` — land on `8080` without
     * this having to understand IPv6.
     */
    private static function short(string $mapping): ?self
    {
        $segments = self::segments($mapping);

        if (count($segments) < 2) {
            return null;
        }

        return new self($mapping, self::variableIn($segments[count($segments) - 2]));
    }

    /**
     * The long syntax, where the host side has a name: `published`. A mapping
     * without one is the ephemeral case again.
     *
     * @param  array<mixed>  $entry
     */
    private static function long(array $entry): ?self
    {
        $published = $entry['published'] ?? null;

        if (! is_string($published) && ! is_int($published)) {
            return null;
        }

        // Inline, because this ends up on one line of a refusal alongside the
        // short-syntax mappings it has to read as a sibling of.
        return new self(rtrim(Yaml::dump($entry, 0)), self::variableIn((string) $published));
    }

    /**
     * $mapping split on the colons that separate its parts, and on no others.
     *
     * The exception is the whole reason this is not `explode(':', …)`: a
     * `${VAR:-default}` is one part however many colons it contains, and it is
     * precisely the mapping this package exists to reason about.
     *
     * @return list<string>
     */
    private static function segments(string $mapping): array
    {
        $segments = [];
        $current = '';
        $depth = 0;
        $length = strlen($mapping);

        for ($index = 0; $index < $length; $index++) {
            $character = $mapping[$index];

            if ($character === '$' && ($mapping[$index + 1] ?? '') === '{') {
                $depth++;
                $current .= '${';
                $index++;

                continue;
            }

            if ($character === '}' && $depth > 0) {
                $depth--;
                $current .= $character;

                continue;
            }

            if ($character === ':' && $depth === 0) {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $segments[] = $current;

        return $segments;
    }

    /**
     * The variable a host side reads — `${FORWARD_REDIS_PORT:-6379}` and
     * `$APP_PORT` alike — or null when it is a number and nothing else.
     */
    private static function variableIn(string $side): ?string
    {
        if (preg_match('/\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $side, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
