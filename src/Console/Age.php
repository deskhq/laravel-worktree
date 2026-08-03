<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * How long ago a slot was claimed, in the width a column can spare (#54).
 *
 * `created_at` has been on every entry since the registry existed and nothing
 * ever showed it. It is what turns *I have twelve worktrees* into *and four of
 * them are from March*, which is the sentence that gets a machine tidied up —
 * an absolute timestamp is a subtraction the reader has to do, and a column of
 * them is twelve subtractions.
 *
 * ## One unit, and days all the way out
 *
 * `47s`, `12m`, `5h`, `152d`. The largest unit that has whole numbers of itself
 * in the answer, and never a second one beside it: this is a column, not a
 * report, and `3d 4h 12m` is precision nobody came for.
 *
 * Days do not roll over into weeks or months, deliberately. `152d` and *from
 * March* are the same fact, and the alternative costs the one ambiguity this
 * format cannot afford — `m` for minutes beside `mo` for months, in a column
 * scanned rather than read.
 *
 * `--json` gets the subtraction instead of the word, in seconds
 * ({@see seconds()}), because a script asked for a number.
 */
final readonly class Age
{
    /** What a column says about an entry whose `created_at` is unreadable. */
    public const string Unknown = '-';

    private const int Minute = 60;

    private const int Hour = 3600;

    private const int Day = 86400;

    /**
     * How many seconds ago $createdAt was, or null when it cannot be read.
     *
     * Null rather than zero for an entry written by something that got the
     * field wrong, or by a version that had not got one yet: an unreadable
     * timestamp is not a worktree made a moment ago, and rendering it as one
     * would put the newest-looking row in the table on the oldest entry in it.
     *
     * A timestamp in the future is a clock that moved, not an entry from
     * tomorrow, and floors at zero.
     */
    public static function seconds(string $createdAt, ?int $now = null): ?int
    {
        if (trim($createdAt) === '') {
            return null;
        }

        $claimed = strtotime($createdAt);

        if ($claimed === false) {
            return null;
        }

        return max(($now ?? time()) - $claimed, 0);
    }

    /**
     * The column's word for that many seconds.
     */
    public static function describe(?int $seconds): string
    {
        if ($seconds === null) {
            return self::Unknown;
        }

        return match (true) {
            $seconds < self::Minute => $seconds.'s',
            $seconds < self::Hour => intdiv($seconds, self::Minute).'m',
            $seconds < self::Day => intdiv($seconds, self::Hour).'h',
            default => intdiv($seconds, self::Day).'d',
        };
    }
}
