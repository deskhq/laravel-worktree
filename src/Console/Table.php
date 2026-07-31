<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * The one table this package prints, in the two forms a caller may get it.
 *
 * `list` writes to stdout, so its output is a contract as much as `create`'s
 * path is: one line per worktree, columns in a fixed order, and nothing else on
 * the stream. Alignment is therefore a courtesy laid over a machine-readable
 * shape rather than the shape itself — the fields are separated by a tab and
 * handed to `column -t`, and a machine without `column` gets exactly that TSV.
 * Both forms carry the same fields in the same order, so `awk` sees what a
 * person does.
 */
final readonly class Table
{
    /**
     * What separates fields on the way in, and what a fallback leaves between
     * them on the way out. A tab rather than spaces because a worktree's path
     * may contain them, and `column` splitting on those would shear the row.
     */
    public const string Separator = "\t";

    /**
     * The gap `column -t` leaves between two columns, which the width below has
     * to account for.
     */
    private const int Gap = 2;

    public function __construct(private ProcessRunner $runner) {}

    /**
     * $headers and $rows as lines, aligned where this machine can align them.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    public function render(array $headers, array $rows): array
    {
        $table = [$headers, ...$rows];

        $tsv = implode("\n", array_map(
            fn (array $row): string => implode(self::Separator, $row),
            $table,
        ));

        $aligned = $this->runner->filter($this->aligner($table), $tsv."\n");

        // An absent `column` exits 127, a `column` that dislikes its arguments
        // exits non-zero, and either way the TSV it was handed is already the
        // answer — so nothing here fails over the absence of a formatter.
        if (! $aligned->succeeded()) {
            return self::lines($tsv);
        }

        $lines = self::lines($aligned->output);

        return self::isFaithful($lines, $tsv) ? $lines : self::lines($tsv);
    }

    /**
     * `column -t`, told how wide the table it is being handed actually is.
     *
     * Without that it formats to a *terminal* width, defaulting to 80 when
     * there is no terminal — which there never is here, because this runs
     * inside `worktree list | …`. util-linux before 2.41 answers a table that
     * does not fit by dropping columns off the end, so a `PATH` long enough to
     * be worth printing is the first thing to go. Both this `-c` and BSD's take
     * the same argument, and neither minds being told a width it did not need.
     *
     * @param  list<list<string>>  $table
     * @return list<string>
     */
    private function aligner(array $table): array
    {
        $widths = [];

        foreach ($table as $row) {
            foreach ($row as $column => $field) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($field));
            }
        }

        $width = array_sum($widths) + self::Gap * max(count($widths) - 1, 0);

        return ['column', '-t', '-s', self::Separator, '-c', (string) $width];
    }

    /**
     * Whether $lines is the table that went in, differing only in its spacing.
     *
     * The aligner is a courtesy, and a courtesy may not cost a field: a
     * `column` that dropped, wrapped or reordered anything is one whose output
     * is worse than the TSV it was handed, whatever its exit code said.
     *
     * @param  list<string>  $lines
     */
    private static function isFaithful(array $lines, string $tsv): bool
    {
        $expected = array_map(self::collapsed(...), self::lines($tsv));

        return array_map(self::collapsed(...), $lines) === $expected;
    }

    /**
     * A line with every run of whitespace flattened to one space, which is all
     * an aligner is allowed to have changed.
     */
    private static function collapsed(string $line): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $line));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = array_map(rtrim(...), explode("\n", $text));

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }
}
