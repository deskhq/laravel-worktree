<?php

namespace DeskHQ\LaravelWorktree\Config;

use DeskHQ\LaravelWorktree\Compose\AppService;
use DeskHQ\LaravelWorktree\Compose\Publication;
use DeskHQ\LaravelWorktree\Compose\PublishedPorts;
use DeskHQ\LaravelWorktree\Compose\Services;
use DeskHQ\LaravelWorktree\Compose\StartedServices;

/**
 * The configuration a repository's own `compose.yaml` implies (#58).
 *
 * `config/worktree.php` is a long, careful explanation of how to work three
 * things out by hand: which ports to name in `ports`, which `FORWARD_*_PORT`
 * variables to offset in `env`, and which of them cannot be offset that way and
 * need a `compose.port_overrides` entry instead. It is a derivation the reader
 * is asked to perform against a file the package can read, using a rule the
 * package already implements — and a mistake in it surfaces minutes into a
 * bootstrap on somebody's *second* worktree, as a Docker bind error naming a
 * port that is in no file anybody wrote.
 *
 * The pre-flight ({@see PublishedPorts}) already has the whole answer in hand at
 * the moment it refuses; it just spends it on an error message. This is that
 * same walk, turned around: the services a worktree would start
 * ({@see StartedServices}), each one's `ports:` read with Compose's own grammar
 * ({@see Publication}), and the host side of every mapping named with the rule
 * the refusal names it by ({@see PublishedPorts::portName()}).
 *
 * ## What each mapping becomes
 *
 * - **an offsettable variable** — `'${FORWARD_DB_PORT:-5432}:5432'` — becomes a
 *   name in `ports` and a `{{port.*}}` entry in `env`. That is the ordinary case
 *   and the whole of what most services need.
 * - **a trap-1 variable** — `REVERB_PORT`, `PUSHER_PORT`: the host side of the
 *   mapping *and* the port the application dials inside the Compose network.
 *   Offsetting it points the broadcaster at nothing, so it is pinned to the
 *   container's own port in `env` and the published side is remapped in
 *   `compose.port_overrides`, which is the documented fix and the reason those
 *   two mechanisms are separate.
 * - **a literal host port** — `'8025:8025'` — has no variable for `env` to
 *   assign, so it can only become a `port_overrides` entry.
 *
 * A service that needs an override gets one covering *every* mapping it
 * publishes, because `!override` replaces its whole `ports:` list rather than
 * merging into it — and its offsettable variables then get no `env` entry,
 * because an entry that no longer decides anything is an entry somebody will
 * later maintain for nothing.
 *
 * ## What it deliberately does not know
 *
 * Whether a worktree should start a service at all is policy — `MAIL_MAILER=log`
 * and `SCOUT_DRIVER=collection` are opinions about an application, not facts
 * about its Compose file — so `keep_services` is seeded from what the app
 * service actually depends on and nothing is pointed away from anything.
 *
 * And `steps` is empty, which makes the closure smaller than the eventual truth:
 * a service brought up by a bootstrap step (`sail up -d reverb`, and the redis
 * it pulls in behind it) cannot be seen before that step exists. That is what
 * the pre-flight catches later, and why a second `worktree init` — or a
 * `worktree doctor` — is part of the workflow rather than a surprise.
 */
final readonly class Derivation
{
    /**
     * The variables that are the host side of a mapping *and* the port the
     * application dials inside the container: trap 1, pinned rather than
     * offset.
     *
     * A list rather than a rule, because nothing in `compose.yaml` distinguishes
     * `'${REVERB_PORT:-8080}:8080'` from `'${APP_PORT:-80}:80'` — the difference
     * is that `config/broadcasting.php` reads one of them. These two are the ones
     * Sail ships; anything else with the same shape is caught by the pre-flight
     * on the first create rather than guessed at here.
     *
     * @var list<string>
     */
    public const array Trapped = ['REVERB_PORT', 'PUSHER_PORT'];

    /**
     * What a repository with no ports at all still gets, so that `port_stride`
     * never narrows below the published default just because a Compose file is
     * small.
     */
    public const int MinimumStride = 10;

    private function __construct(
        /** The file this was derived from, so a summary can name it. */
        public string $composeFile,
        /** What this application calls its app service. */
        public string $appService,
        /**
         * Every service a worktree would start, keyed by name and valued by why.
         *
         * @var array<string, string>
         */
        public array $started,
        /** @var list<string> */
        public array $ports,
        /** @var array<string, scalar|null> */
        public array $env,
        /**
         * The subset of `env` that is pinned for the container's sake rather
         * than offset for the host's — trap 1, which reads differently and is
         * commented as such in the generated file.
         *
         * @var array<string, int|string>
         */
        public array $pinned,
        /** @var list<string> */
        public array $keepServices,
        /** @var array<string, list<string>> */
        public array $portOverrides,
        public int $portStride,
    ) {}

    /**
     * The derivation for the checkout at $root: its Compose file, and the app
     * service its own `.env` names.
     */
    public static function at(string $root): self
    {
        return self::of(Services::in($root), AppService::at($root));
    }

    public static function of(Services $services, string $appService): self
    {
        // The app service's own `depends_on` is what `keep_services` is seeded
        // from, and — since an empty list means "as compose.yaml declares it" —
        // the closure it produces is the one a worktree of this repository
        // starts today, before any bootstrap step has been written.
        $keep = $services->dependenciesOf($appService);
        $started = StartedServices::of($services, $appService, ['keep_services' => $keep, 'port_overrides' => []], []);

        /** @var list<string> $ports */
        $ports = [];
        /** @var array<string, string> $owners */
        $owners = [];
        /** @var array<string, string> $offsets */
        $offsets = [];
        /** @var array<string, int|string> $pinned */
        $pinned = [];
        /** @var array<string, list<string>> $overrides */
        $overrides = [];
        $appPort = null;

        foreach (array_keys($started) as $service) {
            $publications = $services->publishedBy($service);
            $remapped = self::needsOverride($publications);

            foreach ($publications as $index => $publication) {
                $name = self::allocate($publication, $service, $index, $ports, $owners);

                if ($service === $appService) {
                    $appPort ??= $name;
                }

                if ($remapped) {
                    $overrides[$service][] = '{{port.'.$name.'}}:'.$publication->container;
                }

                if ($publication->variable === null) {
                    continue;
                }

                if (self::isTrapped($publication->variable)) {
                    $pinned[$publication->variable] = self::inner($publication);

                    continue;
                }

                // Its host side comes from the override now, so an offset here
                // would decide nothing.
                if (! $remapped) {
                    $offsets[$name] = $publication->variable;
                }
            }
        }

        return new self(
            $services->file,
            $appService,
            $started,
            $ports,
            self::environment($ports, $offsets, $pinned, $appPort),
            $pinned,
            $keep,
            $overrides,
            max(self::MinimumStride, count($ports)),
        );
    }

    /**
     * The generated file's values, in the shape `config/worktree.php` returns
     * them — so that what was derived can be handed straight to
     * {@see Configuration::fromArray()} and checked by the pre-flight it was
     * derived from, before a byte of it is written.
     *
     * The keys nothing here derives are left out rather than restated: this is
     * what the generated file *changes*, and everything else it says is the
     * package's published default.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'port_stride' => $this->portStride,
            'ports' => $this->ports,
            'env' => $this->env,
            'compose' => ['keep_services' => $this->keepServices, 'port_overrides' => $this->portOverrides],
        ];
    }

    /**
     * `env`, in the order the published example writes it: the offsets in port
     * order, then the two every worktree needs, then whatever is pinned for the
     * container's sake.
     *
     * @param  list<string>  $ports
     * @param  array<string, string>  $offsets  Port name to the variable that offsets with it.
     * @param  array<string, int|string>  $pinned
     * @return array<string, scalar|null>
     */
    private static function environment(array $ports, array $offsets, array $pinned, ?string $appPort): array
    {
        $env = [];

        foreach ($ports as $name) {
            if (array_key_exists($name, $offsets)) {
                $env[$offsets[$name]] = '{{port.'.$name.'}}';
            }
        }

        $env['COMPOSE_PROJECT_NAME'] = '{{project}}';

        // Only when the app service publishes something to reach it on: an
        // APP_URL naming a port no worktree owns is worse than none at all.
        if ($appPort !== null) {
            $env['APP_URL'] = 'http://localhost:{{port.'.$appPort.'}}';
        }

        return [...$env, ...$pinned];
    }

    /**
     * Whether this service's `ports:` has to be replaced wholesale rather than
     * offset variable by variable — because one of its mappings names no
     * variable at all, or names one the application also dials inside the
     * container.
     *
     * @param  list<Publication>  $publications
     */
    private static function needsOverride(array $publications): bool
    {
        foreach ($publications as $publication) {
            if ($publication->variable === null || self::isTrapped($publication->variable)) {
                return true;
            }
        }

        return false;
    }

    private static function isTrapped(string $variable): bool
    {
        return in_array($variable, self::Trapped, true);
    }

    /**
     * The value a trapped variable is pinned to: the container's own port, which
     * is the one the application has to keep dialling however the published side
     * moves.
     */
    private static function inner(Publication $publication): int|string
    {
        return preg_match('/\A(\d+)/', $publication->container, $matches) === 1
            ? (int) $matches[1]
            : $publication->container;
    }

    /**
     * The name this mapping's host port takes in `ports`, allocated once per
     * thing that owns one.
     *
     * Two services publishing the same variable share a port, because they
     * publish the same host port and always did. Everything else gets a name of
     * its own, disambiguated by the container port it lands on — which is what
     * turns Mailpit's second mapping into `mailpit_8025` rather than into a
     * collision with its first.
     *
     * @param  list<string>  $ports
     * @param  array<string, string>  $owners  Port name to the variable or mapping that holds it.
     */
    private static function allocate(
        Publication $publication,
        string $service,
        int $index,
        array &$ports,
        array &$owners,
    ): string {
        $owner = $publication->variable ?? $service.'#'.$index;
        $held = array_search($owner, $owners, true);

        if (is_string($held)) {
            return $held;
        }

        $base = ($publication->variable === null ? null : self::sanitise(PublishedPorts::portName($publication->variable)))
            ?? self::sanitise($service)
            ?? 'port';

        $name = $base;
        $digits = preg_match('/\A(\d+)/', $publication->container, $matches) === 1 ? $matches[1] : null;

        for ($attempt = 0; array_key_exists($name, $owners); $attempt++) {
            $name = $attempt === 0 && $digits !== null ? $base.'_'.$digits : $base.'_'.($attempt + 2);
        }

        $owners[$name] = $owner;
        $ports[] = $name;

        return $name;
    }

    /**
     * $name as `ports` will accept it — lowercase, and starting with a letter —
     * or null when there is nothing left of it that {@see Schema} would take.
     */
    private static function sanitise(string $name): ?string
    {
        $name = (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($name));
        $name = trim($name, '_');

        return preg_match('/\A[a-z][a-z0-9_]*\z/', $name) === 1 ? $name : null;
    }
}
