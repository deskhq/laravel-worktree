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
     * The aligner, and the reason this class needs a process runner at all.
     *
     * @var list<string>
     */
    private const array Aligner = ['column', '-t', '-s', self::Separator];

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
        $tsv = implode("\n", array_map(
            fn (array $row): string => implode(self::Separator, $row),
            [$headers, ...$rows],
        ));

        $aligned = $this->runner->filter(self::Aligner, $tsv."\n");

        // An absent `column` exits 127, a `column` that dislikes its arguments
        // exits non-zero, and either way the TSV it was handed is already the
        // answer — so nothing here fails over the absence of a formatter.
        if (! $aligned->succeeded() || trim($aligned->output) === '') {
            return self::lines($tsv);
        }

        return self::lines($aligned->output);
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
