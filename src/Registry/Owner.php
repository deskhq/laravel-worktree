<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Support\Machine;

/**
 * Who took a lock, written inside the lock directory as `owner.json`.
 *
 * The `mkdir` stays the atomic test-and-set — the directory is still the lock,
 * and winning it is still one syscall. This is what the winner writes
 * immediately afterwards, so that the *next* run has something to judge by
 * instead of a bare directory owned by nothing:
 *
 * ```json
 * {
 *   "pid": 51234,
 *   "host": "studio.local",
 *   "boot": null,
 *   "started_at": "Mon Aug  3 10:22:11 2026",
 *   "command": "worktree create 441",
 *   "taken_at": "2026-08-03T10:22:11Z"
 * }
 * ```
 *
 * ## A reader sees all of it or none of it
 *
 * It is written to a temporary file *inside the lock directory* and renamed into
 * place, for the reason {@see Registry::write()} does the same: a rename within
 * one directory cannot be interrupted halfway, so nothing ever reads a record
 * that is missing the field it was about to be judged on. The absence of the
 * file is a first-class answer — {@see read()} returns null for it — and that
 * answer means *wait*, not *break*.
 *
 * ## What makes an identity
 *
 * The pid alone is not one. Pids are handed out again, and a lock left behind on
 * Tuesday has had days for that to happen, so `posix_kill($pid, 0)` answering
 * yes proves only that *something* has the number. The start time is what turns
 * it into an identity, and the host is what stops one machine judging another's
 * pid table when `WORKTREE_HOME` is on a share. Everything the record cannot
 * establish is null, and every null pushes {@see liveness()} towards waiting.
 */
final readonly class Owner
{
    /**
     * The record, relative to the lock directory it describes.
     */
    public const string File = 'owner.json';

    /**
     * How much of the command line is kept. It is a diagnostic — enough to tell
     * a `create` from a `reap` at a glance — not a transcript.
     */
    private const int CommandLength = 200;

    public function __construct(
        public int $pid,
        /** The machine that issued the pid; a pid means nothing anywhere else. */
        public string $host,
        /** The boot it was issued on, where this platform has a stable one. */
        public ?string $boot,
        /** When the process started, as {@see Machine::startedAt()} reports it. */
        public ?string $startedAt,
        /** What the run was, for the line the next run prints. */
        public string $command,
        /** When the lock was taken, as the registry stamps its entries. */
        public string $takenAt,
    ) {}

    /**
     * The record for the run asking, or null on a machine that will not name its
     * own pid or itself — in which case the lock goes unrecorded and the next
     * run waits it out, which is what this package did before there were records
     * at all.
     */
    public static function thisRun(Machine $machine): ?self
    {
        $pid = $machine->pid();
        $host = $machine->host();

        if ($pid === null || $host === null) {
            return null;
        }

        return new self(
            $pid,
            $host,
            $machine->boot(),
            $machine->startedAt($pid),
            self::invocation(),
            gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * The record inside the lock directory at $lock, or null when there is none
     * to read.
     *
     * Unparseable is the same answer as absent, deliberately: a lock written by
     * an older version of this package has no record, a lock being taken right
     * now has not written one yet, and a truncated one has been edited by hand.
     * The safe reading of all three is *I cannot tell who holds this*, which is
     * not *nobody does*.
     */
    public static function read(string $lock): ?self
    {
        $contents = @file_get_contents($lock.'/'.self::File);

        if (! is_string($contents) || trim($contents) === '') {
            return null;
        }

        $record = json_decode($contents, true);

        if (! is_array($record)) {
            return null;
        }

        $pid = $record['pid'] ?? null;
        $host = $record['host'] ?? null;
        $takenAt = $record['taken_at'] ?? null;

        if (! is_int($pid) || $pid < 1 || ! is_string($host) || $host === '' || ! is_string($takenAt)) {
            return null;
        }

        return new self(
            $pid,
            $host,
            self::text($record['boot'] ?? null),
            self::text($record['started_at'] ?? null),
            self::scrub(self::text($record['command'] ?? null) ?? ''),
            self::scrub($takenAt),
        );
    }

    /**
     * Write this record into the lock directory at $lock, all of it or none.
     *
     * Best effort: a record that could not be written leaves a lock that reads
     * as unowned, which is the conservative end of every judgement made about
     * it. The lock itself is already held by the time this runs.
     */
    public function writeInto(string $lock): bool
    {
        $payload = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            return false;
        }

        // In the lock directory rather than in `$TMPDIR`, so the rename below is
        // within one filesystem and therefore atomic; across devices it would be
        // copy-then-unlink, and a reader could catch it halfway.
        $temporary = $lock.'/.owner-'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temporary, $payload."\n") === false) {
            return false;
        }

        if (! @rename($temporary, $lock.'/'.self::File)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * Whether the run that wrote this record is still going.
     *
     * The order is the whole of the safety. Anything this cannot establish stops
     * at {@see Liveness::Alive} or {@see Liveness::Unknown}, and only two things
     * reach {@see Liveness::Gone}: a pid this machine does not have, and a pid it
     * has handed out to something that started at a different moment.
     */
    public function liveness(Machine $machine): Liveness
    {
        // Another machine's pid, which this one's process table says nothing
        // about — a `WORKTREE_HOME` on a network share is the case. A machine
        // that cannot name itself cannot claim the record either, for the same
        // reason: it has no way to know the pid was issued here.
        if ($machine->host() === null || $this->host !== $machine->host()) {
            return Liveness::Unknown;
        }

        if (! $machine->isRunning($this->pid)) {
            return Liveness::Gone;
        }

        $started = $machine->startedAt($this->pid);

        // The number is in use, by something that started at a different moment:
        // the holder is gone and its pid has been issued again.
        if ($this->startedAt !== null && $started !== null && $this->startedAt !== $started) {
            return Liveness::Gone;
        }

        // Only reached when the two start times could not be compared. A pid
        // that appears to have survived a reboot is a different process
        // whatever it claims, and on Linux a start time is an offset from boot,
        // so this is what stops two boots' offsets reading as one process.
        if ($this->boot !== null && $machine->boot() !== null && $this->boot !== $machine->boot()) {
            return Liveness::Gone;
        }

        return Liveness::Alive;
    }

    /**
     * Whether $other is this same holder — used to confirm, after a lock has
     * been moved aside, that what moved is the record that was judged and not
     * one somebody wrote in between.
     */
    public function is(self $other): bool
    {
        return $this->pid === $other->pid
            && $this->host === $other->host
            && $this->boot === $other->boot
            && $this->startedAt === $other->startedAt
            && $this->takenAt === $other->takenAt;
    }

    /**
     * The holder, as a line naming it to somebody who has to decide what to do
     * about it.
     */
    public function describe(): string
    {
        return "pid $this->pid on $this->host"
            .($this->command === '' ? '' : " ($this->command)")
            .", holding it since $this->takenAt";
    }

    /**
     * Who has this lock and whether they are still there, as one clause.
     *
     * The sentence three callers were each writing their own version of (#74):
     * {@see Lock::confront()} says it while waiting, `worktree unlock` says it
     * while refusing, and `worktree doctor` says it in a report — and *"which is
     * not running any more"* appeared in all three. The judgement was already
     * shared ({@see liveness()}); this is the wording of it, so that a lock
     * described in a report and the same lock described by the run that waits
     * for it read identically.
     */
    public function heldBy(Liveness $liveness): string
    {
        return 'taken by '.$this->describe().match ($liveness) {
            Liveness::Alive => ', which is still running',
            Liveness::Gone => ', which is not running any more',
            Liveness::Unknown => ', on another machine — nothing here can tell whether it is still running',
        };
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'host' => $this->host,
            'boot' => $this->boot,
            'started_at' => $this->startedAt,
            'command' => $this->command,
            'taken_at' => $this->takenAt,
        ];
    }

    /**
     * What this run was invoked as, shortened and stripped of anything that
     * would move a terminal's cursor when the next run prints it back.
     *
     * The whole invocation rather than the subcommand alone, because *which*
     * `create` has the lock is the question somebody asks — and it costs
     * nothing to answer: every argument this binary accepts is a branch name,
     * an issue number or a declared flag, and the same line is already on the
     * process table for anyone with `ps`.
     */
    private static function invocation(): string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (! is_array($argv) || $argv === []) {
            return '';
        }

        $words = array_values(array_map(fn (mixed $word): string => is_string($word) ? $word : '', $argv));
        $words[0] = basename($words[0]);

        return self::scrub(implode(' ', $words));
    }

    private static function scrub(string $text): string
    {
        $line = trim((string) preg_replace('/[[:cntrl:]]+/', ' ', $text));

        return strlen($line) <= self::CommandLength
            ? $line
            : rtrim(substr($line, 0, self::CommandLength)).'…';
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
