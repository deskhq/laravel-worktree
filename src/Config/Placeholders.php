<?php

namespace DeskHQ\LaravelWorktree\Config;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Identity;

/**
 * `{{port.app}}`, `{{project}}` and the rest, resolved against one worktree.
 *
 * A configuration file is read once, on the host, long before a slot has been
 * claimed — so the ports a worktree publishes cannot be written into it. They
 * are named instead, and substituted here, which is what lets one static file
 * describe every worktree the repository will ever have.
 *
 * A name nothing defines is an error naming the offender and the value it came
 * from, never an empty string left in the file: `APP_URL=http://localhost:`
 * fails minutes later in a browser, with nothing to connect it back to a
 * mistyped placeholder.
 */
final readonly class Placeholders
{
    /**
     * Everything resolvable that is not a port.
     *
     * @var list<string>
     */
    public const array Names = ['project', 'slug', 'branch', 'path', 'uid', 'gid'];

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $ports
     */
    private function __construct(private array $values, private array $ports) {}

    /**
     * The placeholders one worktree resolves.
     *
     * @param  array<string, int>  $ports  The host ports its slot owns.
     */
    public static function for(Identity $identity, array $ports): self
    {
        $values = [
            'project' => $identity->key,
            'slug' => $identity->slug,
            'branch' => $identity->branch,
            'path' => $identity->path,
            'uid' => (string) self::userId(),
            'gid' => (string) self::groupId(),
        ];

        foreach ($ports as $name => $port) {
            $values['port.'.$name] = (string) $port;
        }

        return new self($values, array_keys($ports));
    }

    /**
     * Substitute every placeholder in $value.
     *
     * @param  string  $where  The config path the value came from, for the error message: `env.APP_URL`.
     *
     * @throws WorktreeException when $value names a placeholder this worktree does not have.
     */
    public function interpolate(string $value, string $where): string
    {
        return (string) preg_replace_callback(
            '/\{\{([^{}]*)\}\}/',
            fn (array $match): string => $this->resolve(trim($match[1]), $where),
            $value,
        );
    }

    /**
     * The host user this run is, which a step handing work to a container has
     * to say out loud: `docker run --user {{uid}}:{{gid}}` is the difference
     * between a `vendor/` the person on the host owns and one they cannot
     * delete. `bin/sail` exports the same pair as `WWWUSER` and `WWWGROUP`.
     *
     * `posix_*` where the extension is compiled in, and the owner of the running
     * script where it is not — which is the same user in the case that matters,
     * because that is who installed this package.
     */
    private static function userId(): int
    {
        return function_exists('posix_getuid') ? posix_getuid() : (int) getmyuid();
    }

    private static function groupId(): int
    {
        return function_exists('posix_getgid') ? posix_getgid() : (int) getmygid();
    }

    private function resolve(string $name, string $where): string
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        $used = "$where uses {{".$name.'}}, which ';

        if (str_starts_with($name, 'port.')) {
            throw self::error($used.'is not a port this configuration declares; '.Schema::File
                .' names '.implode(', ', $this->ports)." in 'ports'");
        }

        throw self::error($used.'is not a placeholder; the ones there are '
            .implode(', ', array_map(fn (string $known): string => '{{'.$known.'}}', self::Names))
            .' and {{port.<name>}}');
    }

    private static function error(string $message): WorktreeException
    {
        return new WorktreeException(Schema::File.': '.$message);
    }
}
