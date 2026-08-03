<?php

namespace DeskHQ\LaravelWorktree\Runtime;

use DeskHQ\LaravelWorktree\Config\Env;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\TimedOutException;
use DeskHQ\LaravelWorktree\Process\ProcessResult;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Support\HostUser;

/**
 * The Docker this machine has, as `bin/sail` would have found it.
 *
 * Every direct call this package makes to Docker comes through here, for three
 * reasons that are all the same reason — Sail already made these configurable,
 * and a package that hardcodes what it made configurable fails silently on
 * somebody else's machine:
 *
 * - **`SAIL_DOCKER_BINARY`.** Sail's own way to Podman. A run whose `sail` steps
 *   go to Podman and whose teardown goes to Docker tears down nothing.
 * - **The `docker-compose` fallback.** `bin/sail` probes `$DOCKER compose` and
 *   drops to the standalone `$DOCKER-compose` binary when the subcommand is not
 *   there. Probed once here, for the same reason.
 * - **`WWWUSER` / `WWWGROUP`.** `bin/sail` exports them and `compose.yaml`
 *   interpolates them; when *we* invoke Compose directly they are unset, which
 *   costs a warning per variable and, on anything Compose then creates, the
 *   wrong ownership.
 *
 * ## An absent daemon is an answer
 *
 * The label queries back `list` as much as they back teardown, and a listing is
 * not worth failing a run over — so a query that cannot be asked comes back
 * empty rather than throwing. That is only safe because "nothing is left" and
 * "nothing could be asked" are told apart somewhere else: {@see isRunning()},
 * which teardown asks first, precisely so that an unreachable daemon cannot be
 * reported as a worktree proved gone (the-desk#1095).
 */
final class Docker
{
    /**
     * The one label that covers every resource Compose creates — named volumes
     * and the anonymous ones an image's `VOLUME` directive produces alike (D7).
     * Its value is ours because we write `COMPOSE_PROJECT_NAME`.
     */
    public const string ProjectLabel = 'com.docker.compose.project';

    /** Sail's own override, which is how Podman is reached. */
    public const string BinaryVariable = 'SAIL_DOCKER_BINARY';

    /**
     * The state Docker gives a container that is up.
     *
     * The one value of `{{.State}}` that counts as running: `paused`,
     * `restarting`, `created`, `exited` and `dead` are all things a person
     * wants told apart from a worktree that is serving requests.
     */
    public const string Up = 'running';

    public const string DefaultBinary = 'docker';

    /**
     * `docker compose`, or the standalone binary, resolved on first use.
     *
     * @var list<string>|null
     */
    private ?array $compose = null;

    private ?bool $running = null;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Output $output,
        /** `SAIL_DOCKER_BINARY`: `docker`, or `podman` on a machine that runs that instead. */
        private readonly string $binary = self::DefaultBinary,
    ) {}

    /**
     * Docker as the binary reaches it, honouring Sail's own variable.
     */
    public static function for(ProcessRunner $runner, Output $output): self
    {
        return new self($runner, $output, self::configuredBinary());
    }

    /**
     * The binary Sail would use on this machine.
     *
     * Public because the Compose version pre-flight asks the same question of
     * the same variable, and two readings of one variable are two chances to
     * disagree about which Docker this run is talking to.
     */
    public static function configuredBinary(): string
    {
        $binary = Env::get(self::BinaryVariable);

        return is_string($binary) && $binary !== '' ? $binary : self::DefaultBinary;
    }

    /**
     * Whether there is a daemon to ask at all, asked once.
     *
     * `docker info` is the same question `bin/sail` asks before it does
     * anything, and the answer cannot change usefully within one run.
     */
    public function isRunning(): bool
    {
        return $this->running ??= $this->runner->quiet([$this->binary, 'info']) === 0;
    }

    /**
     * The containers $project has on this machine, exited ones included.
     *
     * @return list<string>
     */
    public function containers(string $project): array
    {
        return $this->query([$this->binary, 'ps', '-aq', '--filter', 'label='.self::ProjectLabel.'='.$project]);
    }

    /**
     * The volumes $project has on this machine.
     *
     * A label query rather than a list read out of `compose.yaml`: that is what
     * answers "what is still on disk for this worktree?" without knowing what
     * the application declares, and it is the whole reason the teardown can be
     * verified rather than hoped for.
     *
     * @return list<string>
     */
    public function volumes(string $project): array
    {
        return $this->query([$this->binary, 'volume', 'ls', '-q', '--filter', 'label='.self::ProjectLabel.'='.$project]);
    }

    /**
     * What every Compose project on this machine has, and how much of it is up.
     *
     * The other direction from {@see containers()}: that asks what one project
     * owns, this asks what projects exist at all, which is the only way to find
     * a project no registry entry names any more ({@see Orphans}) — and, since
     * #54, the only way to fill a `STATUS` column without one `docker` per row.
     *
     * Still one query. The project label was already being printed per container
     * and counted here; the state is a second field on the same line, which is
     * what turns "this project has four containers" into "and one of them is
     * up" — the difference between a worktree that is serving and one whose boot
     * stopped partway.
     *
     * @return array<string, Presence>
     */
    public function containersByProject(): array
    {
        $census = [];

        foreach ($this->query([$this->binary, 'ps', '-a', '--filter', 'label='.self::ProjectLabel, '--format', self::censusTemplate()]) as $line) {
            [$project, $state] = self::census($line);

            if ($project === '') {
                continue;
            }

            $census[$project] = ($census[$project] ?? Presence::none())->with($state === self::Up);
        }

        ksort($census);

        return $census;
    }

    /**
     * How many volumes each Compose project on this machine has.
     *
     * @return array<string, int>
     */
    public function volumesByProject(): array
    {
        return $this->tally([$this->binary, 'volume', 'ls', '--filter', 'label='.self::ProjectLabel, '--format', self::labelTemplate()]);
    }

    /**
     * Remove one container, and the anonymous volumes it mounts.
     *
     * `--volumes` is the only route to those: nothing names them, so nothing
     * else can ask for them by name. One call per container, never a batch —
     * `docker rm a b c` abandons the whole batch when one id is already gone,
     * and the survivors are precisely what the teardown reports on.
     */
    public function removeContainer(string $container): bool
    {
        return $this->removed([$this->binary, 'rm', '--force', '--volumes', $container], "container $container");
    }

    /**
     * Remove one named volume. One call per volume, for the reason above.
     */
    public function removeVolume(string $volume): bool
    {
        return $this->removed([$this->binary, 'volume', 'rm', '--force', $volume], "volume $volume");
    }

    /**
     * A Compose invocation, made the way `bin/sail` would have made it.
     *
     * Its output is kept rather than shown: the caller decides, because the one
     * caller there is has to show it on failure and stay quiet otherwise.
     *
     * @param  list<string>  $arguments
     */
    public function compose(array $arguments, ?string $cwd = null): ProcessResult
    {
        return $this->runner->attempt([...$this->composeCommand(), ...$arguments], $cwd, self::interpolation());
    }

    /**
     * Run a throwaway container, streaming everything it says into the run's
     * diagnostics — it is minutes of work, and silence would read as a hang.
     *
     * @param  list<string>  $arguments
     * @param  int|null  $timeout  Seconds it may run for; null is no ceiling.
     *
     * @throws TimedOutException when it ran past $timeout.
     */
    public function run(array $arguments, ?string $cwd = null, ?int $timeout = null): int
    {
        return $this->runner->run([$this->binary, ...$arguments], $cwd, timeout: $timeout);
    }

    /**
     * `docker compose`, or `docker-compose` on a machine that only has that.
     *
     * `bin/sail` probes exactly this way, and a machine Sail works on is a
     * machine this has to work on. Note that the fallback is
     * `${SAIL_DOCKER_BINARY}-compose`, not a literal `docker-compose`: on a
     * Podman machine the pair is `podman` and `podman-compose`.
     *
     * @return list<string>
     */
    private function composeCommand(): array
    {
        return $this->compose ??= $this->runner->quiet([$this->binary, 'compose', 'version']) === 0
            ? [$this->binary, 'compose']
            : [$this->binary.'-compose'];
    }

    /**
     * @param  list<string>  $command
     */
    private function removed(array $command, string $what): bool
    {
        $result = $this->runner->attempt($command);

        if ($result->succeeded()) {
            return true;
        }

        // Not fatal here: what survives is re-queried afterwards and reported
        // then, so one failure to remove does not stop the rest from going.
        $this->output->line("warning: could not remove $what: ".trim($result->output));

        return false;
    }

    /**
     * A label query that answers with one project name per resource, counted.
     *
     * @param  list<string>  $command
     * @return array<string, int>
     */
    private function tally(array $command): array
    {
        $counts = [];

        foreach ($this->query($command) as $project) {
            $counts[$project] = ($counts[$project] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * The Go template that prints one resource's project label, which both
     * `docker ps` and `docker volume ls` understand.
     */
    private static function labelTemplate(): string
    {
        return '{{.Label "'.self::ProjectLabel.'"}}';
    }

    /**
     * The same, plus the container's own state — `docker ps` only, since a
     * volume has none.
     */
    private static function censusTemplate(): string
    {
        return self::labelTemplate().' {{.State}}';
    }

    /**
     * One census line as its two fields.
     *
     * Split on whitespace rather than on a delimiter of our own: a Compose
     * project name may hold none — Docker restricts it to lowercase letters,
     * digits, dashes and underscores — and neither may a container state, so
     * there is nothing here for a separator to protect.
     *
     * @return array{string, string}
     */
    private static function census(string $line): array
    {
        $fields = preg_split('/\s+/', trim($line)) ?: [];

        return [$fields[0] ?? '', $fields[1] ?? ''];
    }

    /**
     * A label query, as a list of ids.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function query(array $command): array
    {
        $result = $this->runner->consult($command);

        if (! $result->succeeded()) {
            return [];
        }

        $lines = array_map(trim(...), explode("\n", $result->output));

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /**
     * What `compose.yaml` interpolates and `bin/sail` exports, which nothing
     * exports when we call Compose ourselves.
     *
     * @return array<string, string>
     */
    private static function interpolation(): array
    {
        return [
            'WWWUSER' => (string) HostUser::id(),
            'WWWGROUP' => (string) HostUser::group(),
        ];
    }
}
