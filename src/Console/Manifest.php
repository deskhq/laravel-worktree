<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Registry\DeadEntry;
use DeskHQ\LaravelWorktree\Runtime\Orphan;

/**
 * The indented, aligned list under a heading:
 *
 * ```
 *   wt-the-desk-441-fix-login  4 containers, 3 volumes
 *   wt-the-desk-feat-checkout  0 containers, 3 volumes
 * ```
 *
 * One place, because `list` warns with these lines and `reap` asks permission
 * with them: a manifest that reads differently from the warning that sent you
 * to it is a manifest somebody has to reconcile by eye before answering `y`.
 * Two classes build one — {@see Orphan} and {@see DeadEntry} — and a `reap`
 * prints both in the same breath, so they align the same way or the summary
 * reads as two unrelated reports.
 *
 * Not {@see Table}: that is the one table this package *prints*, on stdout,
 * under a contract. This is prose, on stderr, and every line of it is a
 * diagnostic.
 */
final readonly class Manifest
{
    /** What every line is indented by. */
    private const string Indent = '  ';

    /** The gap between the name and what is said about it. */
    private const int Gap = 2;

    /**
     * @param  array<string, string>  $items  What each name is holding, keyed by the name.
     * @return list<string>
     */
    public static function lines(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $width = max(array_map(strlen(...), array_keys($items))) + self::Gap;

        $lines = [];

        foreach ($items as $name => $held) {
            $lines[] = self::Indent.str_pad($name, $width).$held;
        }

        return $lines;
    }
}
