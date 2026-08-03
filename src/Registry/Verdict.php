<?php

namespace DeskHQ\LaravelWorktree\Registry;

/**
 * What acting on one key during a sweep came to ({@see Fleet::sweep()}).
 *
 * Two facts, because the sweep needs exactly two: whether the key goes in the
 * succeeded list or the survived one, and what to put on the run's diagnostics
 * about it.
 *
 * The diagnostic is optional, and the two callers show why. A `reap` tears a
 * project down and holds the only account there is of what would not go, so it
 * hands that account here to be printed. A `stop` has already said what
 * happened as it happened — the runtime writes the Compose output itself — and
 * a second line here would be the same failure reported twice.
 */
final readonly class Verdict
{
    private function __construct(
        /** Whether the work this verdict is about did what it was asked. */
        public bool $succeeded,
        /** What to say about a failure; empty when the work already said it. */
        public string $diagnostic,
    ) {}

    public static function worked(): self
    {
        return new self(true, '');
    }

    public static function failed(string $diagnostic = ''): self
    {
        return new self(false, $diagnostic);
    }

    /**
     * A verdict from something that already reports on itself, which is what
     * every caller in this package has.
     */
    public static function of(bool $succeeded, string $diagnostic = ''): self
    {
        return $succeeded ? self::worked() : self::failed($diagnostic);
    }
}
