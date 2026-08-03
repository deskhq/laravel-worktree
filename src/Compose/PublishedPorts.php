<?php

namespace DeskHQ\LaravelWorktree\Compose;

use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * The host ports a worktree's services would bind, checked before it takes any.
 *
 * The failure this exists to prevent has a shape worth stating, because it is
 * the reason a comment was not enough. It never appears on the first worktree:
 * the first one binds 6379 and everything works. It appears on the **second**,
 * minutes into a bootstrap, as `Bind for :::6379 failed` — naming a port that
 * is in no configuration file, belonging to a service the application never
 * asked for, pulled in by a `depends_on` two levels down.
 *
 * Everything needed to say so up front is already here: the configuration, and
 * the application's own Compose file. So this is a pre-flight next to the
 * Compose version check ({@see ComposeVersion}) — pure parsing, no daemon, run
 * before a slot is claimed and before a single file is generated.
 *
 * ## What counts as covered
 *
 * For each service the worktree starts ({@see StartedServices}), each `ports:`
 * mapping that names a host port is covered when either:
 *
 * - the host side is a variable `env` assigns a `{{port.*}}` placeholder to,
 *   which is what gives it a per-slot value in the worktree's own environment
 *   file; or
 * - the service has a `compose.port_overrides` entry, which replaces its whole
 *   `ports:` list through the `!override` merge tag — so whatever
 *   `compose.yaml` said about it no longer applies, and the author has taken
 *   explicit control of what that service publishes.
 *
 * Anything else is refused rather than warned about, because a warning here is
 * a warning nobody reads until the bind error arrives anyway. Two cases get
 * their own message, because "add it to `env`" is no help in either. A
 * **literal** host port has no variable to assign at all. And a variable `env`
 * assigns a *fixed* value to is not offset by having been mentioned — it is
 * trap 1 in `config/worktree.php`, `REVERB_PORT` pinned for the container's
 * sake and never remapped on the published side.
 */
final readonly class PublishedPorts
{
    public function __construct(
        private Services $services,
        private string $appService,
    ) {}

    /**
     * The check as a create makes it: the main checkout's Compose file, and
     * whatever that checkout calls its app service.
     *
     * The main working tree rather than the worktree, because a create runs
     * this before `git worktree add` has made the worktree exist — and the
     * Compose file is tracked, so the two are the same file anyway.
     */
    public static function of(string $mainRoot): self
    {
        return new self(Services::in($mainRoot), AppService::at($mainRoot));
    }

    /**
     * @throws WorktreeException when a service this worktree starts publishes a host port nothing offsets.
     */
    public function verify(Configuration $config): void
    {
        $problem = $this->problem($config);

        if ($problem !== null) {
            throw new WorktreeException($problem);
        }
    }

    /**
     * The refusal {@see verify()} would raise, or null when every published host
     * port a worktree's services bind is one something offsets.
     *
     * Split out so that `worktree doctor` can report this check without a create
     * — the same sentence, built in the same place, since it is one of the best
     * things this package says and a second wording of it would be a second
     * thing to keep true (#57).
     */
    public function problem(Configuration $config): ?string
    {
        $collisions = [];

        foreach ($this->started($config) as $service => $why) {
            // The overlay replaces this service's whole `ports:` list, so what
            // the application's file publishes for it is no longer published.
            if (array_key_exists($service, $config->compose['port_overrides'])) {
                continue;
            }

            foreach ($this->services->publishedBy($service) as $publication) {
                if ($publication->variable !== null && self::offsets($config->env[$publication->variable] ?? null)) {
                    continue;
                }

                $collisions[] = self::describe($service, $why, $publication, $config);
            }
        }

        if ($collisions === []) {
            return null;
        }

        return Schema::File.': '.count($collisions).' published host '.(count($collisions) === 1 ? 'port' : 'ports')
            .' would be the same in every worktree, so the second one to start would die on a Docker bind error '
            ."naming a port nothing here configures:\n\n".implode("\n\n", $collisions)
            ."\n\nEvery published port of every service a worktree starts needs an entry — including the services "
            .'it starts through depends_on rather than by name.';
    }

    /**
     * Every service a worktree would start, keyed by name and valued by why —
     * which is what this checks the published ports of.
     *
     * Public because it is an answer in its own right: it is the thing nobody can
     * work out in their head ({@see StartedServices}), and a report that says
     * *these twelve services would start, and none of them collides* is worth
     * more than one that says only that nothing is wrong.
     *
     * @return array<string, string>
     */
    public function started(Configuration $config): array
    {
        return StartedServices::of($this->services, $this->appService, $config->compose, $config->steps);
    }

    /**
     * One collision, as three lines: what would bind, why that service is
     * running at all, and the one edit that fixes it.
     */
    private static function describe(string $service, string $why, Publication $publication, Configuration $config): string
    {
        return "  $service publishes '$publication->mapping'"
            ."\n    started because $why"
            ."\n    ".self::remedy($service, $publication, $config);
    }

    private static function remedy(string $service, Publication $publication, Configuration $config): string
    {
        if ($publication->variable === null) {
            return "the host side is a literal, so there is no variable for 'env' to offset: "
                ."give $service a 'compose.port_overrides' entry, which replaces its ports: outright";
        }

        if (array_key_exists($publication->variable, $config->env)) {
            return "'env' assigns '$publication->variable', but a value with no {{port.*}} placeholder in it is the "
                .'same value in every worktree: give it one — or, when that variable is read inside the container '
                ."too, leave it pinned and remap the published side with a 'compose.port_overrides' entry for "
                .$service.', which is trap 1 in '.Schema::File;
        }

        $port = self::portName($publication->variable);

        if (in_array($port, $config->ports, true)) {
            return "add '$publication->variable' => '{{port.$port}}' to 'env'";
        }

        return "add '$port' to 'ports', and '$publication->variable' => '{{port.$port}}' to 'env'";
    }

    /**
     * Whether an `env` value moves this port per worktree, rather than merely
     * mentioning it.
     *
     * The placeholder is the whole of the question: it is the only thing in
     * that file that can differ between two worktrees of the same repository.
     */
    private static function offsets(string|int|float|bool|null $value): bool
    {
        return is_string($value) && preg_match('/\{\{\s*port\.[^{}]*\}\}/', $value) === 1;
    }

    /**
     * The name this variable's port would sensibly be given in `ports`:
     * `FORWARD_REDIS_PORT` is asking to be `redis`, and `APP_PORT` `app`.
     */
    private static function portName(string $variable): string
    {
        $name = strtolower($variable);
        $name = (string) preg_replace('/\Aforward_/', '', $name);
        $name = (string) preg_replace('/_port\z/', '', $name);

        return $name === '' ? strtolower($variable) : $name;
    }
}
