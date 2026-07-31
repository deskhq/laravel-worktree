<?php

namespace DeskHQ\LaravelWorktree\Config;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * The assignments an environment file makes, read and rewritten as text.
 *
 * Two writers work on the same file: {@see EnvFile} gives a new worktree its
 * whole environment, and the Compose overlay comes back afterwards to point
 * `SAIL_FILES` at itself. They agree about what an assignment looks like
 * because they are the same code — a second implementation of "replace `KEY=`
 * where it stands" is how the two end up disagreeing about `export KEY=`, about
 * padding, or about a key the file assigns twice.
 *
 * Reading is here for the same reason. Whether an application already sets
 * `SAIL_FILES`, and what it calls its app service, are questions answered from
 * the file `bin/sail` will `source` — not from this process's environment,
 * which is the main checkout's and not the worktree's.
 */
final readonly class Assignments
{
    /** The comment written above appended variables, so a reader knows where they came from. */
    public const string Marker = '# added by laravel-worktree for';

    private function __construct(private string $content) {}

    public static function of(string $content): self
    {
        return new self($content);
    }

    /**
     * @throws WorktreeException when the file cannot be read.
     */
    public static function read(string $path): self
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new WorktreeException("could not read $path");
        }

        return new self($content);
    }

    /**
     * The value $key ends up with, or null when the file never assigns it.
     *
     * The *last* assignment wins, because `bin/sail` reads these by sourcing the
     * file and that is what a shell is left holding. (phpdotenv keeps the first
     * instead — which is exactly why {@see upsert()} rewrites every occurrence,
     * so the two can never be told apart.)
     */
    public function value(string $key): ?string
    {
        if (preg_match_all(self::pattern($key), $this->content, $matches) < 1) {
            return null;
        }

        return self::parse((string) end($matches[1]));
    }

    /**
     * Set each variable, replacing the assignment the file already makes or
     * appending it under a line saying who put it there.
     *
     * In place, so the comment above a variable still describes the variable
     * below it. Every occurrence, because `.env` files with a key twice exist,
     * and phpdotenv keeps the first while `source` keeps the last — replacing
     * both is the only answer that means the same thing to Sail and to Laravel.
     *
     * @param  array<string, string>  $variables
     * @param  string  $attribution  What the appended block is credited to: the worktree's project name.
     */
    public function upsert(array $variables, string $attribution): self
    {
        $content = $this->content;
        $appended = [];

        foreach ($variables as $key => $value) {
            $assignment = $key.'='.self::quoted($value);
            $replaced = self::replace($content, $key, $assignment);

            if ($replaced === null) {
                $appended[] = $assignment;

                continue;
            }

            $content = $replaced;
        }

        $content = trim($content, "\n") === '' ? '' : rtrim($content, "\n")."\n";

        if ($appended !== []) {
            $content .= ($content === '' ? '' : "\n").self::Marker.' '.$attribution."\n".implode("\n", $appended)."\n";
        }

        return new self($content);
    }

    /**
     * @throws WorktreeException when the file cannot be written.
     */
    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->content) === false) {
            throw new WorktreeException("could not write $path");
        }
    }

    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * $content with every `KEY=` assignment it already makes replaced, or null
     * when it makes none.
     */
    private static function replace(string $content, string $key, string $assignment): ?string
    {
        $pattern = '/^(\h*)(export\h+)?'.preg_quote($key, '/').'\h*=.*$/m';

        if (preg_match($pattern, $content) !== 1) {
            return null;
        }

        return (string) preg_replace_callback(
            $pattern,
            fn (array $match): string => $match[1].(($match[2] ?? '') === '' ? '' : 'export ').$assignment,
            $content,
        );
    }

    private static function pattern(string $key): string
    {
        return '/^\h*(?:export\h+)?'.preg_quote($key, '/').'\h*=(.*)$/m';
    }

    /**
     * The value as whatever reads the file ends up with: quotes removed, the
     * escapes {@see quoted()} writes undone, and a trailing comment dropped —
     * `SAIL_FILES=compose.yaml # ours` sets one file, not two words.
     */
    private static function parse(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/\A"((?:[^"\\\\]|\\\\.)*)"/', $raw, $matches) === 1) {
            return str_replace(['\\\\', '\"', '\$'], ['\\', '"', '$'], $matches[1]);
        }

        if (preg_match("/\\A'([^']*)'/", $raw, $matches) === 1) {
            return $matches[1];
        }

        return rtrim((string) preg_split('/\h#/', $raw)[0]);
    }

    /**
     * The value as it can be written into the file.
     *
     * Quoted only when it has to be, because an unquoted value is what people
     * expect to see. Inside the quotes, `\`, `"` and `$` are escaped: both
     * phpdotenv and `source` would otherwise read `$` as the start of a
     * variable and silently substitute — usually to nothing.
     */
    private static function quoted(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_.\-\/:@,+=]+\z/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\"', '\$'], $value).'"';
    }
}
