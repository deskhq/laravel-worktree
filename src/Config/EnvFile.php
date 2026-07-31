<?php

namespace DeskHQ\LaravelWorktree\Config;

use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Naming\Identity;

/**
 * The `.env` a new worktree starts life with.
 *
 * This is where a worktree stops sharing the machine with its siblings: the
 * ports it publishes are offset by its slot, its Compose project is named after
 * it, and the services it never starts are pointed somewhere harmless. All of
 * it is configuration — `env` in `config/worktree.php` — because every line of
 * it is an application's own business.
 *
 * It cannot be a bootstrap step. It runs before the worktree has a `vendor/`,
 * before a container exists, and it needs the ports the allocator has just
 * handed out, so it is its own thing that runs once, early.
 *
 * ## Written once, and only once
 *
 * A worktree that already has the file keeps it, untouched. Re-entering a
 * worktree is the normal way to resume an interrupted bootstrap, and a resume
 * that reverted somebody's debugging edits would be worse than no resume at
 * all.
 *
 * ## Upsert, not append
 *
 * Every variable replaces the assignment already in the file, or is appended if
 * there is none ({@see Assignments}). Sail's own installer does positional
 * `str_replace` on the strings its stub happens to ship with, which silently
 * does nothing on a customised `.env` — the file is written, the ports are not
 * in it, and the second worktree collides with the first for no visible reason.
 */
final readonly class EnvFile
{
    /** What a worktree falls back to when the main checkout has no `.env`. */
    public const string Example = '.env.example';

    public function __construct(
        private Output $output,
        /** The main working tree, whose `.env` a new worktree starts from. */
        private string $mainRoot,
        /**
         * `APP_ENV` as this run's shell exported it, or null.
         *
         * Not the `APP_ENV` the `.env` sets — that one changes nothing about
         * which file gets read ({@see fileName()}).
         */
        private ?string $appEnv = null,
    ) {}

    /**
     * The generator as the binary uses it, reading `APP_ENV` from the shell.
     */
    public static function for(Output $output, string $mainRoot): self
    {
        return new self($output, $mainRoot, Env::exportedEnvironment());
    }

    /**
     * Give the worktree at $identity->path its environment, once.
     *
     * @param  array<string, int>  $ports  The host ports the worktree's slot owns.
     * @param  array<string, scalar|null>  $variables  `env`, as configured.
     * @return string The path of the file the worktree's environment now lives in.
     *
     * @throws WorktreeException when there is nothing to copy from, a placeholder resolves to nothing, or the file cannot be written.
     */
    public function generate(Identity $identity, array $ports, array $variables): string
    {
        $name = $this->fileName();
        $target = $identity->path.'/'.$name;

        if (! is_dir($identity->path)) {
            throw new WorktreeException("cannot generate $name: there is no worktree at $identity->path yet");
        }

        if (is_file($target)) {
            $this->output->line("keeping the $name already in $identity->path");

            return $target;
        }

        if ($this->appEnv !== null) {
            $this->output->line(
                "APP_ENV=$this->appEnv is exported in this shell, and both bin/sail and Laravel read $name "
                .'in preference to .env — so that is where this worktree\'s ports go'
            );
        }

        $this->apply($this->starting($identity, $name), $identity, $ports, $variables)->save($target);

        $this->output->line('generated '.$name.' ('.count($variables).' '.(count($variables) === 1 ? 'variable' : 'variables').')');

        return $target;
    }

    /**
     * The file both `bin/sail` and Laravel will read in this worktree.
     *
     * ```bash
     * if [ -n "$APP_ENV" ] && [ -f ./.env."$APP_ENV" ]; then
     *   source ./.env."$APP_ENV";
     * elif [ -f ./.env ]; then
     *   source ./.env;
     * fi
     * ```
     *
     * That is `bin/sail`, and `LoadEnvironmentVariables` agrees with it. So on a
     * machine with `APP_ENV` exported, ports written into `.env` are read by
     * nobody the moment `.env.<APP_ENV>` exists — every worktree keeps the
     * default ports and collides, with nothing on screen to explain it. We write
     * the file that wins instead, which also makes it exist, which is what
     * settles the `-f` test above for good.
     */
    private function fileName(): string
    {
        return $this->appEnv === null ? '.env' : '.env.'.$this->appEnv;
    }

    /**
     * What the generated file starts out as: the main checkout's own
     * environment, or the worktree's `.env.example` with a line saying so.
     *
     * @throws WorktreeException when neither exists.
     */
    private function starting(Identity $identity, string $name): Assignments
    {
        foreach ([$this->mainRoot.'/'.$name, $this->mainRoot.'/.env'] as $candidate) {
            if (is_file($candidate)) {
                return Assignments::read($candidate);
            }
        }

        $example = $identity->path.'/'.self::Example;

        if (is_file($example)) {
            $this->output->line("the checkout at $this->mainRoot has no $name to copy; starting from the worktree's ".self::Example);

            return Assignments::read($example);
        }

        throw new WorktreeException(
            "nothing to generate $name from: the checkout at $this->mainRoot has no $name, "
            .'and the worktree has no '.self::Example
        );
    }

    /**
     * @param  array<string, int>  $ports
     * @param  array<string, scalar|null>  $variables
     */
    private function apply(Assignments $content, Identity $identity, array $ports, array $variables): Assignments
    {
        $placeholders = Placeholders::for($identity, $ports);
        $resolved = [];

        foreach ($variables as $key => $value) {
            $resolved[$key] = $placeholders->interpolate(self::literal($value), "env.$key");
        }

        return $content->upsert($resolved, $identity->key);
    }

    /**
     * A configured value as a string: `null` is a variable set to nothing —
     * `MAIL_HOST=` — which is how a service gets pointed away from.
     */
    private static function literal(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
    }
}
