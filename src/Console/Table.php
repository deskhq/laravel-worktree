<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * The one table this package prints, in the two renderings a caller may get it.
 *
 * `list` is read by a person and by `awk`, and one rendering cannot serve both:
 * the fields a script needs are the full ones — the key, the branch, the
 * absolute path — and a row carrying all of them is about 250 columns wide,
 * which in a terminal hard-wraps into a continuation sitting under no header at
 * all. So the two are separated at the only place that knows which is being
 * asked for, {@see Emitter::isInteractive()}:
 *
 * - **not a terminal** — {@see tsv()}, one tab-separated line per entry and
 *   nothing else. This is the documented contract, and it is now literally
 *   true: `awk -F'\t'` reads the same fields a person sees, which it did not
 *   while the tabs were being handed to `column -t` to be padded away.
 * - **a terminal** — {@see fitted()}, aligned to the columns that are actually
 *   there, truncated rather than wrapped, with a dim header. A person who wants
 *   the elided fields can widen the window or reach for `--json`; a wrapped row
 *   is worse than an elided one, because it is the shape this table is trying
 *   to avoid.
 *
 * No subprocess in either. `column -t` used to align the piped form, which is
 * where alignment does the least good and the most harm: it formats to a
 * *terminal* width, defaults to 80 when there is no terminal — which there
 * never is on that branch — and util-linux before 2.41 answers a table that
 * does not fit by dropping columns off the end, `PATH` first.
 */
final readonly class Table
{
    /**
     * What separates fields in the piped rendering. A tab rather than spaces
     * because a worktree's path may contain them, and a reader splitting on
     * those would shear the row.
     */
    public const string Separator = "\t";

    /** The gap the fitted rendering leaves between two columns. */
    private const int Gap = 2;

    /**
     * The narrowest a column is squeezed to before the line itself is cut.
     *
     * A floor rather than an even division, because eleven columns of four
     * characters each is not a table anybody can read. When the floor is
     * reached and the row still does not fit, the line is truncated whole —
     * which keeps the one promise that matters here, that no row wraps.
     */
    private const int Floor = 12;

    /** What marks a field the width did not allow room for. */
    private const string Ellipsis = '…';

    /**
     * The table as tab-separated lines: the header, then one line per row.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    public static function tsv(array $headers, array $rows): array
    {
        return array_map(
            fn (array $row): string => implode(self::Separator, $row),
            [$headers, ...$rows],
        );
    }

    /**
     * The table aligned into $width columns, with the header dimmed.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    public static function fitted(array $headers, array $rows, int $width, Style $style): array
    {
        $table = [$headers, ...$rows];
        $widths = self::squeezed(self::widths($table), $width);

        $lines = array_map(
            fn (array $row): string => self::line($row, $widths, $width),
            $table,
        );

        $lines[0] = $style->dim($lines[0]);

        return $lines;
    }

    /**
     * What each column needs to show every field it holds in full.
     *
     * @param  list<list<string>>  $table
     * @return list<int>
     */
    private static function widths(array $table): array
    {
        $widths = [];

        foreach ($table as $row) {
            foreach ($row as $column => $field) {
                $widths[$column] = max($widths[$column] ?? 0, self::length($field));
            }
        }

        return array_values($widths);
    }

    /**
     * The same widths, taken off the widest column one character at a time until
     * the table fits.
     *
     * Widest-first rather than proportionally, because the two columns that
     * overflow a real listing — the key and the path — are an order of magnitude
     * wider than `SLOT` or a port, and shaving those instead would cost a digit
     * of a number to save nothing.
     *
     * @param  list<int>  $widths
     * @return list<int>
     */
    private static function squeezed(array $widths, int $width): array
    {
        while (self::spans($widths) > $width) {
            $widest = array_keys($widths, max($widths), true)[0];

            if ($widths[$widest] <= self::Floor) {
                break;
            }

            $widths[$widest]--;
        }

        return $widths;
    }

    /**
     * @param  list<int>  $widths
     */
    private static function spans(array $widths): int
    {
        return array_sum($widths) + self::Gap * max(count($widths) - 1, 0);
    }

    /**
     * One row, each field clipped to its column and padded out to it, and the
     * whole line clipped to the window in case the floor above left it long.
     *
     * @param  list<string>  $row
     * @param  list<int>  $widths
     */
    private static function line(array $row, array $widths, int $width): string
    {
        $cells = [];

        foreach ($row as $column => $field) {
            $cells[] = self::padded(self::clipped($field, $widths[$column]), $widths[$column]);
        }

        return self::clipped(rtrim(implode(str_repeat(' ', self::Gap), $cells)), $width);
    }

    private static function padded(string $field, int $width): string
    {
        return $field.str_repeat(' ', max($width - self::length($field), 0));
    }

    /**
     * $field in at most $width characters, the last of them saying so.
     */
    private static function clipped(string $field, int $width): string
    {
        if (self::length($field) <= $width) {
            return $field;
        }

        if ($width <= 1) {
            return $width === 1 ? self::Ellipsis : '';
        }

        return implode('', array_slice(self::characters($field), 0, $width - 1)).self::Ellipsis;
    }

    private static function length(string $field): int
    {
        return count(self::characters($field));
    }

    /**
     * $field as characters rather than bytes.
     *
     * Paths and branch names carry accents, and `strlen` would both misalign a
     * column holding one and cut a codepoint in half when it clipped it. PCRE's
     * `u` modifier is enough for that and needs no extension — which matters,
     * because `ext-mbstring` is not something this package requires. A string
     * that is not valid UTF-8 falls back to its bytes, misaligned but intact.
     *
     * @return list<string>
     */
    private static function characters(string $field): array
    {
        $characters = preg_split('//u', $field, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? str_split($field) : $characters;
    }
}
