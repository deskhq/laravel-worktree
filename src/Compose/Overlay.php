<?php

namespace DeskHQ\LaravelWorktree\Compose;

use DeskHQ\LaravelWorktree\Config\Assignments;
use DeskHQ\LaravelWorktree\Config\EnvFile;
use DeskHQ\LaravelWorktree\Config\Placeholders;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Excludes;
use DeskHQ\LaravelWorktree\Naming\Identity;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * The Compose file a worktree adds to its application's own.
 *
 * Two things `.env` cannot say. A worktree brings up the services it needs and
 * no others, which means trimming the app service's `depends_on` — otherwise
 * every sibling worktree starts the whole of `compose.yaml`, and the machine
 * runs six copies of Meilisearch nobody asked for. And a host port whose
 * variable is *also* read inside the container cannot be offset in `.env` at
 * all: `REVERB_PORT` is the host side of `'${REVERB_PORT}:8080'` and the port
 * the application dials at `reverb:<port>`, so the offset has to happen here,
 * on the published mapping, leaving the inner value alone.
 *
 * ## Not `compose.override.yaml`
 *
 * The bash original wrote that file, unconditionally, on every create. Correct
 * for one application that does not have one; wrong for a package, because
 * Compose auto-loads that name — an application already using one has it
 * silently clobbered, and since it is usually tracked, the damage shows up as
 * an unexplained modification in the worktree's `git status`.
 *
 * `laravel/sail` ships the mechanism built for exactly this. `bin/sail` reads a
 * colon-separated `SAIL_FILES` and turns each entry into a `-f` argument, so a
 * name nothing else claims — `compose.worktree.yaml` — can be added to what
 * Compose is given without displacing anything.
 *
 * Note the sharp edge in that: passing any `-f` disables Compose's own file
 * discovery, and Sail adds no implicit `compose.yaml` of its own. So the
 * application's file has to lead the list ({@see ComposeFile}), and an
 * application that already sets `SAIL_FILES` gets ours appended to its list
 * rather than in place of it.
 */
final readonly class Overlay
{
    /** A name no tool auto-loads and no application is expected to have. */
    public const string File = 'compose.worktree.yaml';

    /** Sail's colon-separated list of Compose files, which is how this one is reached. */
    public const string Variable = 'SAIL_FILES';

    /** The merge tag that replaces a list instead of appending to it (Compose >= 2.24). */
    public const string Tag = 'override';

    public function __construct(
        private Output $output,
        private ComposeVersion $version,
        private Excludes $excludes,
    ) {}

    /**
     * Give the worktree at $identity->path its overlay, and point Sail at it.
     *
     * Regenerated on every run, unlike the `.env`: this file carries no edits
     * worth keeping — it says so in its own first line — and rewriting it is
     * what makes a changed `config/worktree.php` take effect on re-entry.
     *
     * @param  array<string, int>  $ports  The host ports the worktree's slot owns.
     * @param  array{keep_services: list<string>, port_overrides: array<string, list<string>>}  $compose  `compose`, as configured.
     * @param  string  $environmentFile  The worktree's `.env`, as {@see EnvFile} generated it.
     * @return string|null The path written, or null when the configuration asks for no overrides at all.
     *
     * @throws WorktreeException when a placeholder resolves to nothing, Compose is too old, or the file cannot be written.
     */
    public function generate(Identity $identity, array $ports, array $compose, string $environmentFile): ?string
    {
        if (! is_file($environmentFile)) {
            throw new WorktreeException(
                'cannot write '.self::File.": the worktree at $identity->path has no ".basename($environmentFile).' yet'
            );
        }

        if (! self::isWritten($compose)) {
            return null;
        }

        // The file bin/sail sources, so the file that decides what the app
        // service is called and which Compose files it is already given.
        $environment = Assignments::read($environmentFile);
        $services = self::services($identity, $ports, $compose, AppService::in($environment));

        $this->version->verify();

        $target = $identity->path.'/'.self::File;

        // Built and dumped rather than templated, the way Sail builds its own
        // compose.yaml: four levels deep (services, service, key, list) so
        // nothing collapses into flow style, at the indentation Sail's stubs
        // use, so the two files read as one.
        $yaml = self::header($identity).Yaml::dump(['services' => $services], 4, 4);

        if (@file_put_contents($target, $yaml) === false) {
            throw new WorktreeException("could not write $target");
        }

        $this->output->line('generated '.self::File.' ('.implode(', ', array_keys($services)).')');

        $this->excludes->ensure($identity->path, self::File);

        $environment->upsert([self::Variable => $this->sailFiles($environment, $identity)], $identity->key)->save($environmentFile);

        return $target;
    }

    /**
     * Whether this configuration produces an overlay at all.
     *
     * An empty `keep_services` means "leave `depends_on` as `compose.yaml`
     * declares it" and an empty `port_overrides` means nothing is republished,
     * so a repository that configures neither has no {@see self::File} written
     * for it, is never handed the `!override` merge tag, and is not affected by
     * how old this machine's Compose is.
     *
     * Public because that last clause is the difference between a `doctor` that
     * refuses over a Compose version and a create that would have worked: the
     * version pre-flight below runs only when this says so, and the report has
     * to be able to ask the same question (#74).
     *
     * @param  array{keep_services: list<string>, port_overrides: array<string, list<string>>}  $compose  `compose`, as configured.
     */
    public static function isWritten(array $compose): bool
    {
        return $compose['keep_services'] !== [] || $compose['port_overrides'] !== [];
    }

    /**
     * What `SAIL_FILES` has to say for Sail to pass both files to Compose.
     *
     * Appended to whatever the application already set, never substituted for
     * it: an application that lists two files of its own and finds itself given
     * one is an application whose services have quietly changed shape.
     */
    private function sailFiles(Assignments $environment, Identity $identity): string
    {
        $inherited = $environment->value(self::Variable) ?? '';
        $files = array_values(array_filter(array_map(trim(...), explode(':', $inherited)), fn (string $file): bool => $file !== ''));

        if ($files === []) {
            // Nothing inherited, so this list is the whole of what Compose gets:
            // the application's own file first, because -f turns discovery off.
            $files = [ComposeFile::in($identity->path)];
        }

        // Already there on re-entry, and adding it twice would hand Compose the
        // same file twice.
        if (in_array(self::File, $files, true)) {
            return implode(':', $files);
        }

        if ($inherited !== '') {
            $this->output->line('this application already sets '.self::Variable." ($inherited); appending ".self::File.' to it');
        }

        $files[] = self::File;

        return implode(':', $files);
    }

    /**
     * The overlay's services, keyed by name.
     *
     * An empty `keep_services` means "leave `depends_on` as `compose.yaml`
     * declares it", not "depend on nothing" — the default configuration has to
     * mean no overlay at all, or a package that was never configured would
     * start applications with their dependencies cut.
     *
     * @param  array<string, int>  $ports
     * @param  array{keep_services: list<string>, port_overrides: array<string, list<string>>}  $compose
     * @return array<string, array<string, TaggedValue>>
     */
    private static function services(Identity $identity, array $ports, array $compose, string $appService): array
    {
        $placeholders = Placeholders::for($identity, $ports);
        $services = [];

        if ($compose['keep_services'] !== []) {
            $services[$appService] = ['depends_on' => new TaggedValue(self::Tag, $compose['keep_services'])];
        }

        foreach ($compose['port_overrides'] as $service => $mappings) {
            $published = [];

            foreach ($mappings as $index => $mapping) {
                $published[] = $placeholders->interpolate($mapping, "compose.port_overrides.$service.$index");
            }

            $services[$service] = array_merge($services[$service] ?? [], ['ports' => new TaggedValue(self::Tag, $published)]);
        }

        return $services;
    }

    /**
     * Why this file exists, for whoever finds it in a worktree and wonders.
     */
    private static function header(Identity $identity): string
    {
        return <<<YAML
            # Generated by laravel-worktree for $identity->key — do not edit by hand.
            # Every `worktree create` writes it again.
            #
            # Not compose.override.yaml: Compose auto-loads that name, so an application
            # already using one would have it silently replaced. Sail hands this file to
            # Compose instead, through SAIL_FILES in this worktree's environment file.
            #
            # depends_on is trimmed to the services this worktree actually starts, so
            # sibling worktrees do not each bring the whole of compose.yaml up. The ports
            # below are the ones the environment file cannot offset, because the same
            # variable is also read inside the container — REVERB_PORT is the usual one.
            #
            # Both come from 'compose' in config/worktree.php.
            YAML."\n\n";
    }
}
