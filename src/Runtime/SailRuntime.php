<?php

namespace DeskHQ\LaravelWorktree\Runtime;

use DeskHQ\LaravelWorktree\Bootstrap\Action;
use DeskHQ\LaravelWorktree\Bootstrap\ProcessShell;
use DeskHQ\LaravelWorktree\Bootstrap\Shell;
use DeskHQ\LaravelWorktree\Compose\AppService;
use DeskHQ\LaravelWorktree\Config\Assignments;
use DeskHQ\LaravelWorktree\Config\Env;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Support\HostUser;

/**
 * The runtime a worktree gets when the application runs on `laravel/sail`.
 *
 * Sail is a **soft** dependency — detected, never required, and absent from
 * this package's `require` — because the seam it sits behind is only honest if
 * nothing here assumes it. An application without it gets the message in
 * {@see obtainSail()} rather than a stack trace three minutes into a bootstrap.
 *
 * Everything Sail already makes configurable is read rather than assumed: the
 * app service ({@see AppService}), the Docker binary and the Compose command
 * ({@see Docker}), and the Compose file list, which the worktree's own
 * `SAIL_FILES` carries. Each of those is a place the bash original hardcoded
 * what Sail configures, and each would be a silent failure in somebody else's
 * application rather than a loud one.
 *
 * One thing it deliberately does not do: forward agent environment into the
 * container. `bin/sail` already forwards `AI_AGENT`, `CLAUDECODE`,
 * `CLAUDE_CODE`, `CURSOR_AGENT`, `GEMINI_CLI` and a dozen more, and parallel
 * agents are the entire point of this package — so the right move is to let
 * Sail keep doing it, and to say so where somebody would otherwise go looking.
 */
final readonly class SailRuntime implements Runtime
{
    /**
     * The worktree's own Sail, as a path relative to it. {@see Action::Binary}
     * is the same file written as a command line, and a test holds the two
     * together.
     */
    public const string Binary = 'vendor/bin/sail';

    /** Overrides the throwaway image below, for an application whose `composer.json` needs a different PHP. */
    public const string ComposerImageVariable = 'WORKTREE_COMPOSER_IMAGE';

    /**
     * The highest Composer image Sail publishes. It only has to resolve a
     * `composer.json` well enough to produce `vendor/bin/sail`; the resulting
     * `vendor/` then runs on whatever PHP the application's own image has.
     */
    public const string DefaultComposerImage = 'laravelsail/php84-composer:latest';

    /** Sail's own escape from its pre-flight checks. Set in one place; see {@see self::shell()}. */
    public const string SkipChecks = 'SAIL_SKIP_CHECKS';

    public function __construct(
        private Output $output,
        private ProcessRunner $runner,
        private Docker $docker,
        private string $composerImage = self::DefaultComposerImage,
    ) {}

    /**
     * The runtime as the binary wires it: the machine's real Docker, and
     * whichever Composer image `WORKTREE_COMPOSER_IMAGE` names.
     */
    public static function for(Output $output, ProcessRunner $runner): self
    {
        $image = Env::get(self::ComposerImageVariable);

        return new self(
            $output,
            $runner,
            Docker::for($runner, $output),
            is_string($image) && $image !== '' ? $image : self::DefaultComposerImage,
        );
    }

    /**
     * Containers publish host ports, so every worktree needs a block of its own.
     */
    public function allocatesPorts(): bool
    {
        return true;
    }

    /**
     * Get the worktree from an attached directory to a running app service.
     *
     * @throws WorktreeException when Sail cannot be obtained, or refuses to start the service.
     */
    public function boot(Identity $worktree, string $environmentFile): void
    {
        $this->obtainSail($worktree);

        $service = AppService::in(is_file($environmentFile) ? Assignments::read($environmentFile) : Assignments::of(''));

        $this->output->line("starting $service (building the image on the first run can take a while)");

        // Without SAIL_SKIP_CHECKS, deliberately: this is the call that makes
        // the app service exist, and the checks it runs are the ones that decide
        // whether a later `sail exec` has anything to exec into. See shell().
        $exitCode = $this->runner->run([Action::Binary, 'up', '-d', $service], $worktree->path);

        if ($exitCode !== 0) {
            throw new WorktreeException(
                "'".Action::Binary." up -d $service' failed (exit $exitCode) in $worktree->path; "
                ."the bootstrap stopped there — fix it, then 'worktree create $worktree->name' picks up where it left off"
            );
        }
    }

    /**
     * Take the worktree's project off this machine, and prove that it went.
     *
     * The structure is the point, and every part of it is load-bearing:
     *
     * 1. **Ask Compose first**, keeping its output rather than showing it. On
     *    the ordinary run it says nothing anybody needs; on the run that
     *    mattered it is the only account of what went wrong.
     * 2. **Remove what survived by hand** — containers before volumes, because
     *    `--volumes` on `docker rm` is the only way to reach the anonymous
     *    volumes a container mounts, and a volume with a container still on it
     *    cannot be removed anyway.
     * 3. **Re-query, and report the survivors.** An exit code is not evidence.
     *    The label query is, and it is what turns "already down?" into a list of
     *    volume names somebody can act on (the-desk#1095).
     *
     * A daemon that cannot be reached at all short-circuits at the top: the
     * sweep would find nothing, the re-query would agree, and the two together
     * would read as proof that the disk is clean.
     */
    public function teardown(Identity $worktree): TeardownResult
    {
        $project = $worktree->key;

        if (! $this->docker->isRunning()) {
            return TeardownResult::unanswered($project, 'there is no Docker daemon answering on this machine, so nothing could be asked about, or removed for, this project');
        }

        $this->output->line("tearing down the containers and volumes of $project");

        // From inside the worktree when it is still there, so Compose finds the
        // application's own file and the worktree's `.env`; from wherever the
        // run started when it is not, because `remove` has to work on a worktree
        // somebody already deleted by hand.
        $down = $this->docker->compose(
            ['-p', $project, 'down', '--volumes', '--remove-orphans'],
            is_dir($worktree->path) ? $worktree->path : null,
        );

        if (! $down->succeeded()) {
            $this->output->line("the Compose teardown of $project exited $down->exitCode; what it said follows, and whatever it left is removed below");
            $this->output->write(rtrim($down->output)."\n");
        }

        foreach ($this->docker->containers($project) as $container) {
            $this->docker->removeContainer($container);
        }

        foreach ($this->docker->volumes($project) as $volume) {
            $this->docker->removeVolume($volume);
        }

        return new TeardownResult($project, $this->docker->containers($project), $this->docker->volumes($project));
    }

    /**
     * The shell every bootstrap step runs through, with Sail's pre-flight
     * checks skipped — and this is the one place in the package that skips them.
     *
     * Unless `SAIL_SKIP_CHECKS` is set, `bin/sail` runs this before every
     * command:
     *
     * ```bash
     * if "${COMPOSE_CMD[@]}" ps "$APP_SERVICE" 2>&1 | grep 'Exit\|exited'; then
     *     echo "Shutting down old Sail processes..." >&2
     *     "${COMPOSE_CMD[@]}" down > /dev/null 2>&1
     *     EXEC="no"
     * elif [ -z "$("${COMPOSE_CMD[@]}" ps -q)" ]; then
     *     EXEC="no"
     * fi
     * ```
     *
     * One exited one-shot container in the project — a migration that ran and
     * finished, a queue worker that stopped — matches that grep, and Sail takes
     * the whole project **down** in the middle of the bootstrap that started it.
     *
     * But skipping the checks is not a blanket fix, because the same block is
     * what sets `EXEC="no"`, and `EXEC` is how Sail knows whether there is a
     * container to `exec` into. Skip it before anything is up and Sail execs
     * into a container that does not exist. (In v1.63 `EXEC="no"` makes Sail
     * refuse with "Sail is not running"; older releases fell back to `compose
     * run --rm`. Either way, before `up` the answer is wrong.)
     *
     * So it is set for steps and for nothing else: {@see boot()} runs `sail up
     * -d` without it, and every step the pipeline runs comes after that.
     */
    public function shell(): Shell
    {
        return new ProcessShell($this->runner, [self::SkipChecks => '1']);
    }

    /**
     * Make sure the worktree has a Sail to be driven through.
     *
     * A fresh worktree has no `vendor/`, so it has no `vendor/bin/sail` either,
     * and Sail is how everything after this runs. The way out is a throwaway
     * Composer container — which is emphatically *not* the app runtime: it lacks
     * the application's extensions and cannot run its post-install scripts,
     * hence `--ignore-platform-reqs --no-scripts`. It produces just enough
     * `vendor/` to obtain Sail, and the authoritative install is an ordinary
     * bootstrap step that runs inside the app container afterwards.
     *
     * @throws WorktreeException when there is still no Sail after it has run.
     */
    private function obtainSail(Identity $worktree): void
    {
        $sail = $worktree->path.'/'.self::Binary;

        if (is_file($sail)) {
            return;
        }

        $this->output->line("bootstrapping vendor/ via $this->composerImage to obtain Sail");

        $this->docker->run([
            'run', '--rm',
            // As the person on the host, or the vendor/ this writes is a
            // vendor/ they cannot delete.
            '-u', HostUser::id().':'.HostUser::group(),
            '-v', $worktree->path.':/var/www/html',
            '-w', '/var/www/html',
            $this->composerImage,
            'composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress',
            '--ignore-platform-reqs', '--no-scripts',
        ]);

        if (is_file($sail)) {
            return;
        }

        // Both reasons in one message, because from here they look identical and
        // the fix is different: an application that does not use Sail, and an
        // install that did not finish.
        throw new WorktreeException(
            'there is still no '.self::Binary." in $worktree->path after bootstrapping vendor/ with $this->composerImage: "
            .'this runtime drives a worktree through Sail, so the application needs laravel/sail in its composer.json '
            .'(composer require laravel/sail --dev) — or that install failed, in which case its output is just above this line'
        );
    }
}
