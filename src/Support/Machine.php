<?php

namespace DeskHQ\LaravelWorktree\Support;

use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * This machine, as far as a lock has to know it: what it is called, which boot
 * it is on, and whether a pid written into a lock is still one of its processes.
 *
 * Every answer here is deliberately allowed to be *unknown*. A lock is broken on
 * the strength of what this reports, and breaking a live one puts two runs
 * inside the same critical section in the same directory — so "I cannot tell"
 * has to be sayable, and is: {@see boot()} and {@see startedAt()} return null,
 * and {@see isRunning()} answers true for anything it cannot rule out.
 *
 * ## Where a start time comes from, and why there has to be one
 *
 * `posix_kill($pid, 0)` answers *is there a process with this number*, not *is
 * it the one that took the lock*. Pids are handed out again, and a lock left on
 * Tuesday has had days for that to happen, so the pid alone is not an identity —
 * the moment the process started is what makes it one.
 *
 * Linux keeps it in `/proc/<pid>/stat`, as ticks since boot; macOS has no
 * `/proc` and keeps it in `ps`. Both are read the same way for the holder and
 * for the reader, on the same machine, so the two tokens are comparable even
 * though neither format is.
 *
 * ## Why the boot identity is Linux-only
 *
 * `/proc/sys/kernel/random/boot_id` is a value the kernel fixes at boot and
 * never touches again, which is exactly what an identity has to be. macOS's
 * nearest equivalent, `kern.boottime`, is *not*: it is a wall-clock timeval that
 * moves when the clock is adjusted, so two reads minutes apart can disagree on a
 * machine that has not rebooted — and a boot identity that drifts would report a
 * live holder as gone. So there is none there, and the start time carries the
 * whole of the answer, which it can: a macOS start time is an absolute moment
 * rather than an offset from boot.
 */
final class Machine
{
    /**
     * `ESRCH` — the one errno that means there is no such process. It is 3 on
     * Linux and on macOS, and every other errno means something is there:
     * `EPERM` in particular, which is what a process belonging to another user
     * answers with.
     */
    private const int NoSuchProcess = 3;

    private ?string $host = null;

    private ?string $boot = null;

    private bool $lookedForBoot = false;

    /** @var array<int, string|null> */
    private array $startTimes = [];

    public function __construct(private readonly ProcessRunner $runner) {}

    /**
     * What this machine is called.
     *
     * It is in the owner record so that a `WORKTREE_HOME` on a network share
     * cannot have one machine judging another's pid table — a pid is only ever
     * meaningful on the host that issued it.
     */
    public function host(): string
    {
        return $this->host ??= (gethostname() ?: 'unknown');
    }

    /**
     * The pid of the run asking, or null on the platform where PHP will not say.
     */
    public function pid(): ?int
    {
        $pid = getmypid();

        return $pid === false || $pid < 1 ? null : $pid;
    }

    /**
     * Which boot this machine is on, or null where there is no stable answer.
     */
    public function boot(): ?string
    {
        if ($this->lookedForBoot) {
            return $this->boot;
        }

        $this->lookedForBoot = true;

        $contents = @file_get_contents('/proc/sys/kernel/random/boot_id');

        return $this->boot = is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
    }

    /**
     * Whether $pid is a process on this machine right now.
     *
     * True for anything that cannot be ruled out, including a PHP built without
     * ext-posix: the cost of saying "alive" about a dead process is the wait
     * that already happens today, and the cost of the opposite is two bootstraps
     * in one directory.
     */
    public function isRunning(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }

        if (! function_exists('posix_kill')) {
            return true;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        return posix_get_last_error() !== self::NoSuchProcess;
    }

    /**
     * When $pid started, as an opaque token, or null when this machine will not
     * say.
     *
     * Opaque because the two sources disagree about what a start time is — ticks
     * since boot on Linux, a wall-clock date from `ps` elsewhere — and nothing
     * here needs to know: it is only ever compared against another token read
     * the same way on the same machine.
     */
    public function startedAt(int $pid): ?string
    {
        if (array_key_exists($pid, $this->startTimes)) {
            return $this->startTimes[$pid];
        }

        return $this->startTimes[$pid] = $this->fromProc($pid) ?? $this->fromPs($pid);
    }

    /**
     * `starttime`, field 22 of `/proc/<pid>/stat`.
     */
    private function fromProc(int $pid): ?string
    {
        $contents = @file_get_contents("/proc/$pid/stat");

        if (! is_string($contents)) {
            return null;
        }

        // Field 2 is the executable name in parentheses, and it may contain
        // spaces and parentheses of its own — so the fields are counted from the
        // last `)` rather than split from the start of the line.
        $close = strrpos($contents, ')');

        if ($close === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($contents, $close + 1))) ?: [];

        // The first field after the `)` is field 3, so `starttime` is at 19.
        $started = $fields[19] ?? null;

        return $started !== '' && $started !== null && ctype_digit($started) ? $started : null;
    }

    /**
     * `lstart`, which is where macOS keeps it.
     *
     * {@see ProcessRunner::consult()} because a machine whose `ps` cannot answer
     * this is an ordinary answer rather than a failure — it makes the liveness
     * check more conservative, and `ps: illegal option` on the diagnostics would
     * read as something having gone wrong with the run.
     */
    private function fromPs(int $pid): ?string
    {
        $result = $this->runner->consult(['ps', '-o', 'lstart=', '-p', (string) $pid]);

        if (! $result->succeeded()) {
            return null;
        }

        $started = $result->trimmedOutput();

        return $started === '' ? null : $started;
    }
}
