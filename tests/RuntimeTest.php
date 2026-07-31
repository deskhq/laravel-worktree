<?php

use DeskHQ\LaravelWorktree\Bootstrap\Action;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Runtime\Docker;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;
use DeskHQ\LaravelWorktree\Support\HostUser;

beforeEach(function () {
    $this->root = temporaryDirectory('worktree-runtime');
    $this->path = $this->root.'/441-fix-login';
    $this->identity = new Identity('441', '441-fix-login', 'wt-desk-441-fix-login', '441-fix-login', $this->path);
    $this->diagnostics = fopen('php://memory', 'w+');

    mkdir($this->path, 0755, true);
    file_put_contents($this->path.'/.env', "APP_NAME=Desk\n");
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('bootstraps a vendor/ through a throwaway Composer image when the worktree has no Sail yet', function () {
    runtime()->boot($this->identity, $this->path.'/.env');

    // Not the app runtime: that image lacks the application's extensions and
    // cannot run its post-install scripts, so it only has to get us as far as
    // vendor/bin/sail — hence --ignore-platform-reqs --no-scripts.
    expect(dockerInvocations())->toContain(sprintf(
        'run --rm -u %d:%d -v %s:/var/www/html -w /var/www/html %s composer install --no-interaction --prefer-dist --no-progress --ignore-platform-reqs --no-scripts',
        HostUser::id(),
        HostUser::group(),
        $this->path,
        SailRuntime::DefaultComposerImage,
    ))
        ->and(sailInvocations())->toBe(['up -d laravel.test'])
        ->and(diagnosticsIn($this->diagnostics))->toContain('bootstrapping vendor/ via '.SailRuntime::DefaultComposerImage);
});

it('leaves the Sail a worktree already has alone', function () {
    fakeSail($this->path);

    runtime()->boot($this->identity, $this->path.'/.env');

    expect(invocationsLike('run --rm'))->toBe([])
        ->and(sailInvocations())->toBe(['up -d laravel.test']);
});

it('starts whichever service APP_SERVICE names, rather than the one Sail happens to default to', function () {
    file_put_contents($this->path.'/.env', "APP_NAME=Desk\nAPP_SERVICE=app\n");

    runtime()->boot($this->identity, $this->path.'/.env');

    expect(sailInvocations())->toBe(['up -d app']);
});

/**
 * Both of Sail's own variables, read from the environment the way `bin/sail`
 * reads them — the Docker binary that reaches Podman, and the Composer image an
 * application on a different PHP needs.
 */
it('takes its Docker from SAIL_DOCKER_BINARY and its Composer image from WORKTREE_COMPOSER_IMAGE', function () {
    $binary = fakeDockerBinary($this->root);

    withEnvironment([
        'SAIL_DOCKER_BINARY' => $binary,
        SailRuntime::ComposerImageVariable => 'laravelsail/php83-composer:latest',
    ], function () use ($binary) {
        expect(Docker::configuredBinary())->toBe($binary);

        $output = new Output($this->diagnostics);

        SailRuntime::for($output, new ProcessRunner($output))->boot($this->identity, $this->path.'/.env');
    });

    expect(invocationsLike('run --rm'))->toHaveCount(1)
        ->and(implode("\n", dockerInvocations()))->toContain('laravelsail/php83-composer:latest')
        ->and(sailInvocations())->toBe(['up -d laravel.test']);
});

it('says what to do when the bootstrap container leaves no Sail behind', function () {
    expect(fn () => runtime(producesSail: false)->boot($this->identity, $this->path.'/.env'))
        ->toThrow(WorktreeException::class, 'there is still no vendor/bin/sail in '.$this->path)
        ->and(fn () => runtime(producesSail: false)->boot($this->identity, $this->path.'/.env'))
        ->toThrow(WorktreeException::class, 'composer require laravel/sail --dev');
});

it('lets a refusal to start the app service stop the run, resumably', function () {
    fakeSail($this->path, exitCode: 1);

    expect(fn () => runtime()->boot($this->identity, $this->path.'/.env'))
        ->toThrow(WorktreeException::class, "'./vendor/bin/sail up -d laravel.test' failed (exit 1)")
        ->and(fn () => runtime()->boot($this->identity, $this->path.'/.env'))
        ->toThrow(WorktreeException::class, "'worktree create 441' picks up where it left off");
});

/**
 * The two halves of the `SAIL_SKIP_CHECKS` rule, which is one decision rather
 * than two: never for the call that brings the app service up, and always for
 * everything that runs after it.
 *
 * With the checks in place, one exited one-shot container makes `bin/sail` run
 * `compose down` on the project it is in the middle of bootstrapping. With them
 * skipped, `EXEC` stays "yes" and Sail reaches into a container that may not be
 * there yet.
 */
it('runs `up` with Sail\'s own checks in place, and every step after it without them', function () {
    fakeSail($this->path);

    $runtime = runtime();

    $runtime->boot($this->identity, $this->path.'/.env');
    $runtime->shell()->run(Action::Binary.' artisan migrate --force', $this->path);

    expect(sailInvocations())->toBe(['up -d laravel.test', 'artisan migrate --force'])
        ->and(sailEnvironment())->toBe(['unset', '1']);
});

it('asks Compose to take the project down, then removes what survived, one call per resource', function () {
    $docker = fakeDockerBinary($this->root, containers: ['c1', 'c2'], volumes: ['wt-desk-441-fix-login_sail-pgsql', 'wt-desk-441-fix-login_sail-redis']);

    $result = runtime($docker)->teardown($this->identity);

    expect($result->succeeded())->toBeTrue()
        ->and($result->describe())->toContain('nothing on this machine carries its label any more')
        ->and(dockerInvocations())->toBe([
            'info',
            'compose version',
            'compose -p wt-desk-441-fix-login down --volumes --remove-orphans',
            // A label query, not a list read out of compose.yaml: that is what
            // covers the anonymous volumes an image's VOLUME directive makes,
            // which nothing declares and nothing can name.
            'ps -aq --filter label=com.docker.compose.project=wt-desk-441-fix-login',
            // One call per resource. `docker rm a b c` abandons the whole batch
            // as soon as one id is unknown, and the survivors are the point.
            // Containers first: --volumes on `docker rm` is the only way to
            // reach the anonymous volumes they mount.
            'rm --force --volumes c1',
            'rm --force --volumes c2',
            'volume ls -q --filter label=com.docker.compose.project=wt-desk-441-fix-login',
            'volume rm --force wt-desk-441-fix-login_sail-pgsql',
            'volume rm --force wt-desk-441-fix-login_sail-redis',
            // Re-queried, because an exit code is not evidence (the-desk#1095).
            'ps -aq --filter label=com.docker.compose.project=wt-desk-441-fix-login',
            'volume ls -q --filter label=com.docker.compose.project=wt-desk-441-fix-login',
        ]);
});

it('names the volumes it could not remove instead of reporting a clean run', function () {
    $docker = fakeDockerBinary(
        $this->root,
        containers: ['c1'],
        volumes: ['wt-desk-441-fix-login_sail-pgsql', 'wt-desk-441-fix-login_sail-redis'],
        refuses: ['wt-desk-441-fix-login_sail-pgsql'],
    );

    $result = runtime($docker)->teardown($this->identity);

    expect($result->succeeded())->toBeFalse()
        ->and($result->containers)->toBe([])
        ->and($result->volumes)->toBe(['wt-desk-441-fix-login_sail-pgsql'])
        ->and($result->survivors())->toBe(['wt-desk-441-fix-login_sail-pgsql'])
        ->and($result->describe())
        ->toContain('wt-desk-441-fix-login survived teardown')
        ->toContain('1 volume (wt-desk-441-fix-login_sail-pgsql)')
        ->and(diagnosticsIn($this->diagnostics))->toContain('could not remove volume wt-desk-441-fix-login_sail-pgsql');
});

it('shows what Compose said when its teardown failed, and sweeps up behind it anyway', function () {
    $docker = fakeDockerBinary(
        $this->root,
        containers: ['c1'],
        volumes: ['v1'],
        composeExitCode: 1,
        composeOutput: 'no configuration file provided: not found',
    );

    $result = runtime($docker)->teardown($this->identity);

    expect($result->succeeded())->toBeTrue()
        ->and(diagnosticsIn($this->diagnostics))
        ->toContain('the Compose teardown of wt-desk-441-fix-login exited 1')
        ->toContain('no configuration file provided: not found')
        ->and(dockerInvocations())->toContain('rm --force --volumes c1')->toContain('volume rm --force v1');
});

/**
 * The failure this whole structure exists to prevent: with no daemon to ask,
 * the sweep removes nothing, the re-query finds nothing, and the two together
 * would otherwise read as proof that the disk is clean (the-desk#1095).
 */
it('refuses to call a teardown clean when there was no daemon to ask', function () {
    $docker = fakeDockerBinary($this->root, volumes: ['v1'], daemon: false);

    $result = runtime($docker)->teardown($this->identity);

    expect($result->succeeded())->toBeFalse()
        ->and($result->survivors())->toBe([])
        ->and($result->describe())->toContain('could not confirm that wt-desk-441-fix-login is gone')
        // And nothing destructive was attempted: `info` was the whole of it.
        ->and(dockerInvocations())->toBe(['info']);
});

it('answers a label query with nothing rather than throwing when there is no Docker at all', function () {
    $output = new Output($this->diagnostics);
    $docker = new Docker(new ProcessRunner($output), $output, $this->root.'/no-such-docker');

    expect($docker->isRunning())->toBeFalse()
        ->and($docker->containers('wt-desk-441-fix-login'))->toBe([])
        ->and($docker->volumes('wt-desk-441-fix-login'))->toBe([]);
});

it('falls back to the standalone Compose binary the way bin/sail does', function () {
    $docker = fakeDockerBinary($this->root, composeSubcommand: false);

    runtime($docker)->teardown($this->identity);

    expect(dockerInvocations())->toContain('compose version')
        ->and(composeInvocations())->toBe(['-p wt-desk-441-fix-login down --volumes --remove-orphans']);
});

it('exports the WWWUSER and WWWGROUP that bin/sail exports and compose.yaml interpolates', function () {
    runtime()->teardown($this->identity);

    expect(trim((string) file_get_contents($this->root.'/fake/compose-env')))->toBe(HostUser::id().':'.HostUser::group());
});

it('publishes host ports, and says so before anything asks the allocator for a slot', function () {
    expect(runtime()->allocatesPorts())->toBeTrue();
});

/**
 * Two spellings of one file: the command line a `sail:` step becomes, and the
 * path the runtime tests for before it can run one.
 */
it('reaches the same Sail from the pipeline and from the runtime', function () {
    expect(Action::Binary)->toBe('./'.SailRuntime::Binary);
});

/**
 * The runtime under test, driven against a fake `docker` — the suite runs with
 * Docker Desktop closed, and nothing here needs a daemon to be observable.
 *
 * @param  string|null  $docker  A fake docker; one that answers everything is made when none is given.
 * @param  bool  $producesSail  Whether the bootstrap container leaves a `vendor/bin/sail` behind.
 */
function runtime(?string $docker = null, bool $producesSail = true): SailRuntime
{
    $output = new Output(test()->diagnostics);
    $runner = new ProcessRunner($output);
    $binary = $docker ?? fakeDockerBinary(test()->root, producesSail: $producesSail);

    return new SailRuntime($output, $runner, new Docker($runner, $output, $binary));
}

/**
 * A `docker` that records every invocation and answers the questions a teardown
 * asks, with no daemon anywhere on the machine.
 *
 * Its containers and volumes live in files that removals delete lines from, so
 * the re-query a teardown ends with sees what those removals actually did —
 * which is the property worth asserting, rather than the calls on their own.
 *
 * @param  list<string>  $containers  Ids the container label query answers with.
 * @param  list<string>  $volumes  Names the volume label query answers with.
 * @param  list<string>  $refuses  Resources whose removal fails, as one still in use would.
 * @param  bool  $daemon  Whether `docker info` succeeds at all.
 * @param  bool  $composeSubcommand  Whether `docker compose` exists, or only the standalone binary.
 * @param  bool  $producesSail  Whether `docker run` leaves a `vendor/bin/sail` behind, as the Composer image would.
 */
function fakeDockerBinary(
    string $root,
    array $containers = [],
    array $volumes = [],
    array $refuses = [],
    bool $daemon = true,
    int $composeExitCode = 0,
    string $composeOutput = '',
    bool $composeSubcommand = true,
    bool $producesSail = true,
): string {
    $state = $root.'/fake';

    is_dir($state) || mkdir($state, 0755, true);

    foreach (['containers' => $containers, 'volumes' => $volumes, 'refuses' => $refuses] as $name => $lines) {
        file_put_contents($state.'/'.$name, $lines === [] ? '' : implode("\n", $lines)."\n");
    }

    // The Sail the bootstrap container "installs", copied into the mount the way
    // a real `composer install` would have written it.
    writeFakeSail($state.'/sail', 0);

    $answers = [
        'daemon' => $daemon ? 'yes' : 'no',
        'compose' => $composeSubcommand ? 'yes' : 'no',
        'sail' => $producesSail ? 'yes' : 'no',
    ];

    $binary = $root.'/docker';

    file_put_contents($binary, <<<SH
        #!/bin/sh
        STATE='$state'
        printf '%s\\n' "\$*" >> "\$STATE/log"

        # `docker compose` is probed before it is used; a machine carrying only
        # the standalone binary answers no here, and bin/sail drops to that.
        if [ "\$1" = compose ] && [ "\$2" = version ]; then
            [ '{$answers['compose']}' = yes ] || exit 127
            exit 0
        fi

        [ '{$answers['daemon']}' = yes ] || exit 1

        # The resource a removal names is always its last argument.
        for LAST in "\$@"; do :; done

        case "\$1 \$2" in
            'compose -p')
                # What compose.yaml interpolates, and what bin/sail exports for it.
                printf '%s:%s\\n' "\$WWWUSER" "\$WWWGROUP" > "\$STATE/compose-env"
                printf '%s\\n' "\$*" >> "\$STATE/compose"
                [ -z '$composeOutput' ] || printf '%s\\n' '$composeOutput' >&2
                exit $composeExitCode
                ;;
            'ps -aq')
                cat "\$STATE/containers"
                ;;
            'volume ls')
                cat "\$STATE/volumes"
                ;;
            'volume rm')
                if grep -qx "\$LAST" "\$STATE/refuses"; then
                    printf 'Error response from daemon: remove %s: volume is in use\\n' "\$LAST" >&2
                    exit 1
                fi
                grep -vx "\$LAST" "\$STATE/volumes" > "\$STATE/next" || true
                mv "\$STATE/next" "\$STATE/volumes"
                ;;
            'rm --force')
                if grep -qx "\$LAST" "\$STATE/refuses"; then
                    printf 'Error response from daemon: cannot remove container %s\\n' "\$LAST" >&2
                    exit 1
                fi
                grep -vx "\$LAST" "\$STATE/containers" > "\$STATE/next" || true
                mv "\$STATE/next" "\$STATE/containers"
                ;;
            'run --rm')
                printf 'resolving dependencies\\n'
                [ '{$answers['sail']}' = yes ] || exit 0
                for ARG in "\$@"; do
                    case "\$ARG" in
                        *:/var/www/html)
                            MOUNT="\${ARG%:/var/www/html}"
                            mkdir -p "\$MOUNT/vendor/bin"
                            cp "\$STATE/sail" "\$MOUNT/vendor/bin/sail"
                            chmod 0755 "\$MOUNT/vendor/bin/sail"
                            ;;
                    esac
                done
                ;;
        esac

        exit 0
        SH);

    chmod($binary, 0755);

    // The standalone binary bin/sail falls back to, named after the configured
    // one exactly as bin/sail names it: `podman-compose` on a Podman machine.
    file_put_contents($binary.'-compose', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '$state/compose'\nexit 0\n");
    chmod($binary.'-compose', 0755);

    return $binary;
}

/**
 * A `vendor/bin/sail` already in the worktree, recording how it was called and
 * what it was told — which is the whole of what this package asks of Sail.
 */
function fakeSail(string $path, int $exitCode = 0): void
{
    mkdir($path.'/vendor/bin', 0755, true);

    writeFakeSail($path.'/vendor/bin/sail', $exitCode);
}

/**
 * The logs are written relative to the working directory on purpose: every Sail
 * invocation this package makes runs with the worktree as its cwd, because the
 * Sail that carries a worktree's ports and Compose project is the one it owns.
 */
function writeFakeSail(string $file, int $exitCode): void
{
    file_put_contents($file, <<<SH
        #!/bin/sh
        printf '%s\\n' "\$*" >> sail.log
        printf '%s\\n' "\${SAIL_SKIP_CHECKS:-unset}" >> sail.env
        exit $exitCode
        SH);

    chmod($file, 0755);
}

/**
 * @return list<string>
 */
function dockerInvocations(): array
{
    return recorded(test()->root.'/fake/log');
}

/**
 * Every docker invocation that starts with $prefix.
 *
 * @return list<string>
 */
function invocationsLike(string $prefix): array
{
    return array_values(array_filter(dockerInvocations(), fn (string $call): bool => str_starts_with($call, $prefix)));
}

/**
 * @return list<string>
 */
function composeInvocations(): array
{
    return recorded(test()->root.'/fake/compose');
}

/**
 * @return list<string>
 */
function sailInvocations(): array
{
    return recorded(test()->path.'/sail.log');
}

/**
 * `SAIL_SKIP_CHECKS` as each Sail invocation saw it, in order.
 *
 * @return list<string>
 */
function sailEnvironment(): array
{
    return recorded(test()->path.'/sail.env');
}

/**
 * @return list<string>
 */
function recorded(string $log): array
{
    if (! is_file($log)) {
        return [];
    }

    $lines = array_map(trim(...), explode("\n", (string) file_get_contents($log)));

    return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
}
