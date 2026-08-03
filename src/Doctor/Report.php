<?php

namespace DeskHQ\LaravelWorktree\Doctor;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * Every check of one `worktree doctor`, and the one number that comes out of it.
 *
 * A report rather than a refusal: the whole point of the command is that it does
 * not stop at the first thing it finds, so this collects and the command prints.
 * The exit code is the only thing derived from all of them, and it is derived
 * from one verdict — {@see Verdict::Failed} — because that is what "would this
 * break a create?" means, and a warning that failed a CI run would teach people
 * to stop running it.
 *
 * ## Two renderings, one set of findings
 *
 * The lines a person reads are aligned on the verdict and the check's name, so
 * a report scans as a column of `ok` with the exceptions standing out of it; the
 * message follows, and its continuation lines are indented under the first, so a
 * three-line port collision stays one visible finding. `--json` is the same
 * findings as fields — an agent evaluating an unfamiliar repository is a
 * first-class caller here, and it wants the tokens rather than the layout.
 */
final class Report
{
    /** The gap between the columns, and what a continuation line is indented by. */
    private const int Gap = 2;

    /** @var list<Finding> */
    private array $findings = [];

    public function __construct(
        /** The checkout this was run against, so a pasted report says what it is about. */
        private readonly string $repository,
    ) {}

    public function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * Whether anything here would stop a create — the whole of the exit code.
     */
    public function failed(): bool
    {
        return $this->count(Verdict::Failed) > 0;
    }

    public function count(Verdict $verdict): int
    {
        return count(array_filter($this->findings, fn (Finding $finding): bool => $finding->verdict === $verdict));
    }

    /**
     * The report a person reads, one line at a time.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $verdicts = self::width(array_map(fn (Finding $finding): string => $finding->verdict->value, $this->findings));
        $names = self::width(array_map(fn (Finding $finding): string => $finding->name, $this->findings));

        $lines = ['worktree doctor — '.$this->repository, ''];

        foreach ($this->findings as $finding) {
            $lead = str_pad($finding->verdict->value, $verdicts).str_pad($finding->name, $names);

            foreach ($finding->lines() as $index => $line) {
                // A blank line inside a message stays blank rather than becoming
                // an indent nobody can see: the multi-line refusals use it to
                // separate one collision from the next.
                $lines[] = $line === '' ? '' : ($index === 0 ? $lead : str_repeat(' ', strlen($lead))).$line;
            }
        }

        return [...$lines, '', ...$this->summary()];
    }

    /**
     * The whole report as one line of JSON, in the shape `list --json` and
     * `path --json` publish theirs: one object, its fields named as the registry
     * names its own.
     *
     * @throws WorktreeException when it cannot be encoded.
     */
    public function toJson(): string
    {
        $payload = json_encode([
            'repository' => $this->repository,
            'ok' => ! $this->failed(),
            'checks' => array_map(fn (Finding $finding): array => $finding->toPayload(), $this->findings),
            'counts' => [
                Verdict::Passed->value => $this->count(Verdict::Passed),
                Verdict::Warned->value => $this->count(Verdict::Warned),
                Verdict::Failed->value => $this->count(Verdict::Failed),
                Verdict::Unchecked->value => $this->count(Verdict::Unchecked),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new WorktreeException('the doctor report could not be encoded: '.json_last_error_msg());
        }

        return $payload;
    }

    /**
     * The tally, and what it means for the thing somebody came here to do.
     *
     * The counts use the column's own tokens rather than words for them, so that
     * the last line of the report and the lines above it name the same things —
     * and the sentence after it answers the only question the tally leaves open,
     * which is whether any of this stops a create.
     *
     * @return list<string>
     */
    private function summary(): array
    {
        $counts = [];

        foreach (Verdict::cases() as $verdict) {
            $count = $this->count($verdict);

            if ($count > 0) {
                $counts[] = $count.' '.$verdict->value;
            }
        }

        $total = count($this->findings);

        return [
            $total.' '.($total === 1 ? 'check' : 'checks').': '.implode(', ', $counts),
            $this->failed()
                ? "a 'worktree create' would stop on the ".($this->count(Verdict::Failed) === 1 ? 'failure' : 'failures')
                  .' above; nothing was created, started or removed by this run'
                : "nothing here would stop a 'worktree create'",
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private static function width(array $values): int
    {
        return ($values === [] ? 0 : max(array_map(strlen(...), $values))) + self::Gap;
    }
}
