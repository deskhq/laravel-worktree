<?php

namespace DeskHQ\LaravelWorktree\Compose;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * What the application's Compose file declares, in the two terms this package
 * reasons in: what each service pulls in, and what each one publishes.
 *
 * Read rather than assumed, because the alternative is the paragraph in
 * `config/worktree.php` that asks somebody to work the transitive closure of
 * `depends_on` out in their head — which is where the-desk lost a day. The file
 * is right there, and {@see ComposeFile} already knows which of its four legal
 * names this application uses.
 *
 * Pure parsing: no daemon, no `docker compose config`, nothing that costs
 * anything or that a machine with Docker Desktop closed cannot do. Two
 * consequences worth knowing. `include:` and `extends:` are not followed, so a
 * service reached only through one of those is invisible here. And profiles are
 * modelled only as far as {@see unprofiled()} needs them — a service that
 * declares any profile is not one a bare `up` starts.
 */
final readonly class Services
{
    private function __construct(
        /** The file this came out of, so a message can name it. */
        public string $file,
        /** @var array<string, array<mixed>> */
        private array $services,
    ) {}

    /**
     * The services the checkout at $root declares.
     *
     * A repository with no Compose file at all is not an error: it declares no
     * services, publishes no ports, and has nothing for this to say.
     *
     * @throws WorktreeException when the file is there and cannot be read or parsed.
     */
    public static function in(string $root): self
    {
        $name = ComposeFile::in($root);
        $path = $root.'/'.$name;

        if (! is_file($path)) {
            return new self($name, []);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new WorktreeException("could not read $path");
        }

        return self::of($contents, $name);
    }

    /**
     * @throws WorktreeException when $yaml is not YAML this can read.
     */
    public static function of(string $yaml, string $named = ComposeFile::Default): self
    {
        try {
            // Custom tags kept rather than rejected: an application is entitled
            // to write `!override` or `!reset` in its own file, and a parse
            // error over one would refuse a create for a file Compose is
            // perfectly happy with.
            $parsed = Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new WorktreeException("$named could not be parsed: ".$e->getMessage());
        }

        $declared = is_array($parsed) ? self::unwrap($parsed['services'] ?? null) : null;
        $services = [];

        if (is_array($declared)) {
            foreach ($declared as $name => $definition) {
                if (is_string($name)) {
                    $services[$name] = is_array($definition) ? $definition : [];
                }
            }
        }

        return new self($named, $services);
    }

    public function declares(string $service): bool
    {
        return array_key_exists($service, $this->services);
    }

    /**
     * Why booting $service is about to go wrong, or null when this file
     * declares it.
     *
     * `bin/sail` reads `${APP_SERVICE:-"laravel.test"}` from the file it
     * sources and a create runs `sail up -d` against whatever that resolves to,
     * so a name the Compose file does not carry is a boot that fails — after
     * the throwaway Composer container has spent minutes resolving a `vendor/`.
     * `worktree doctor` has always said so and nothing else in the package did
     * (#74); this is the sentence, so that the pre-flight a create makes and the
     * line a report prints cannot come apart.
     *
     * It is worth saying and not worth refusing over: `include:` and `extends:`
     * are not followed here, so a service reached through one of those is real
     * and invisible, and a refusal over it would be this package telling
     * somebody their working application is broken.
     */
    public function undeclared(string $service): ?string
    {
        if ($this->declares($service)) {
            return null;
        }

        return "$this->file declares no service named '$service', which is what ".AppService::Variable
            .' resolves to in this checkout; a create runs \'sail up -d '.$service.'\', so either that name is wrong '
            .'or the service is reached through an include: or extends:, which is not followed here';
    }

    /**
     * Everything a bare `up` would start: every service that claims no profile.
     *
     * A service under a profile is started only when something names it — a
     * `--profile`, a `COMPOSE_PROFILES`, or the service itself on the command
     * line — so counting it as started for a bare `up` would refuse creates
     * over ports nothing ever binds.
     *
     * @return list<string>
     */
    public function unprofiled(): array
    {
        $names = [];

        foreach ($this->services as $name => $definition) {
            $profiles = self::unwrap($definition['profiles'] ?? null);

            if (! is_array($profiles) || $profiles === []) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * What $service pulls in when it starts.
     *
     * Both spellings, because Compose accepts both and an application that
     * needed a `condition:` wrote the second: a list of names, or a map keyed
     * by them.
     *
     * @return list<string>
     */
    public function dependenciesOf(string $service): array
    {
        $dependencies = self::unwrap($this->services[$service]['depends_on'] ?? null);

        if (! is_array($dependencies)) {
            return [];
        }

        $names = array_is_list($dependencies) ? $dependencies : array_keys($dependencies);

        return array_values(array_filter($names, is_string(...)));
    }

    /**
     * The host ports $service publishes, one {@see Publication} per mapping
     * that names one.
     *
     * @return list<Publication>
     */
    public function publishedBy(string $service): array
    {
        $ports = self::unwrap($this->services[$service]['ports'] ?? null);

        if (! is_array($ports)) {
            return [];
        }

        $published = [];

        foreach ($ports as $entry) {
            $publication = Publication::read($entry);

            if ($publication !== null) {
                $published[] = $publication;
            }
        }

        return $published;
    }

    /**
     * A value with its merge tag taken off, so `ports: !override [...]` reads
     * as the list it is.
     */
    private static function unwrap(mixed $value): mixed
    {
        return $value instanceof TaggedValue ? $value->getValue() : $value;
    }
}
