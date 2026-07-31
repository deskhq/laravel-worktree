<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

/**
 * The three places a bootstrap step can run.
 *
 * The host/container distinction is not an extension of the DSL, it is the
 * whole reason the DSL needs more than one verb: `mkdir -p
 * resources/js/generated` and a throwaway `docker run … composer install` are
 * host commands, `artisan migrate` is not, and any real recipe has both.
 *
 * Every one of these is started on the host all the same — `sail` is a host
 * script that shells into the container — so a step never needs this package to
 * be inside anything.
 */
enum Action: string
{
    /** On the host, with the worktree as the working directory. */
    case Host = 'host';

    /** In the container, through the application's own Sail. */
    case Sail = 'sail';

    /** In the container as root, which is what installing a package takes. */
    case SailRoot = 'sail_root';

    /**
     * Sail, as it is reached from the worktree that owns it.
     *
     * Relative on purpose: every step runs with the worktree as its working
     * directory, and the worktree's `vendor/bin/sail` is the one carrying that
     * worktree's `.env` — its ports, its Compose project, its overlay.
     */
    public const string Binary = './vendor/bin/sail';

    /**
     * The command line that runs $command where this action puts it.
     *
     * `root-shell -c` is Sail's own way in as root: it runs `bash -c` in the app
     * service as uid 0, so the command is passed as a single argument and is
     * quoted here rather than left to be re-split by two shells in a row.
     */
    public function commandLine(string $command): string
    {
        return match ($this) {
            self::Host => $command,
            self::Sail => self::Binary.' '.$command,
            self::SailRoot => self::Binary.' root-shell -c '.escapeshellarg($command),
        };
    }
}
