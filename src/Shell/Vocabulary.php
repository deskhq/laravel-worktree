<?php

namespace DeskHQ\LaravelWorktree\Shell;

use DeskHQ\LaravelWorktree\Console\Application;
use DeskHQ\LaravelWorktree\Console\Command;

/**
 * Everything a completion has a closed set of values for, read off the usage
 * text the binary already prints.
 *
 * ```
 * create  <slug> [base] [--refresh] [--json]   ->  a slug, --refresh, --json
 * list    [--all] [--json]                     ->  --all, --json
 * ```
 *
 * The specs come from the registered commands ({@see Application::specifications()}),
 * which is the point: a flag that exists is a flag that completes, and a command
 * added next month completes without anybody remembering a second list. The
 * alternative — a table of names and flags kept beside the generator — is the
 * thing that goes stale first and is noticed last, because a completion that is
 * merely *missing* an option looks exactly like a completion that is working.
 *
 * It is a reading of one line of prose per command, which is only safe because
 * that prose is ours and its shape is asserted ({@see Command::usage()}): a
 * `--flag` is a flag, a `<slug>` is the argument this package spends its whole
 * naming layer on, and `[bash|zsh]` is a choice between literals.
 */
final readonly class Vocabulary
{
    /**
     * @param  array<string, string>  $specifications  Each command's argument spec, keyed by the name it is typed as.
     */
    private function __construct(private array $specifications) {}

    /**
     * @param  array<string, string>  $specifications
     */
    public static function of(array $specifications): self
    {
        return new self($specifications);
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return array_keys($this->specifications);
    }

    /**
     * The long options $command accepts, in the order its usage names them.
     *
     * @return list<string>
     */
    public function flags(string $command): array
    {
        preg_match_all('/--[a-z][a-z0-9-]*/', $this->specifications[$command] ?? '', $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * The commands whose positional argument is a worktree — the ones whose
     * completion is worth the whole of this feature, because those names are
     * long, generated from issue titles, and nobody remembers whether it was
     * `441-fix-login` or `441-login-fix`.
     *
     * @return list<string>
     */
    public function takingSlugs(): array
    {
        return array_values(array_filter(
            $this->commands(),
            fn (string $command): bool => str_contains($this->specifications[$command], '<slug>'),
        ));
    }

    /**
     * The literal values $command's argument may take, for the ones whose
     * argument is a choice rather than a name: `shell-init [bash|zsh]`.
     *
     * @return list<string>
     */
    public function values(string $command): array
    {
        if (preg_match('/\[([a-z][a-z0-9-]*(?:\|[a-z][a-z0-9-]*)+)\]/', $this->specifications[$command] ?? '', $matches) !== 1) {
            return [];
        }

        return explode('|', $matches[1]);
    }
}
