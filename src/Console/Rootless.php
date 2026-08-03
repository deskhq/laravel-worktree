<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * A command that answers with no repository under it.
 *
 * {@see Application} anchors the repository and reads its configuration before
 * any command runs, because every command until now has needed both: a `list`
 * with no checkout under it has nothing to list, and a `create` with no
 * configuration does not know how many slots there are. `shell-init` needs
 * neither — what it prints is a shell function and a completion, and the whole
 * point of it is to be `eval`d from an rc file, which runs in `~`.
 *
 * So the anchor is skipped rather than caught: a `not inside a git repository`
 * on every new terminal would be the package failing at the one moment it is
 * supposed to be invisible. The repository shows up later, when `wt` is actually
 * used from inside one — and the failure there is the binary's own message,
 * which is the point of the function resolving the binary rather than the shell
 * resolving a name on `PATH`.
 */
interface Rootless extends Command
{
    /**
     * The run, with nothing resolved in front of it.
     *
     * @param  list<string>  $arguments
     * @return int One of the {@see ExitCode} constants.
     */
    public function emit(array $arguments): int;
}
