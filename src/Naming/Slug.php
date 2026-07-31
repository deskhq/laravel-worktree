<?php

namespace DeskHQ\LaravelWorktree\Naming;

/**
 * Turning what somebody typed into something git, a filesystem and Docker will
 * each accept.
 *
 * Compose is the strictest of the three, and the only one that fails late: a
 * project name it dislikes is rejected when the first container starts, minutes
 * into a bootstrap, by a tool that does not know where the name came from. So
 * the rule it enforces lives here as {@see ProjectName}, and every derived name
 * is checked against it before anything is run.
 */
final readonly class Slug
{
    /**
     * Docker's rule for a Compose project name.
     */
    public const string ProjectName = '/\A[a-z0-9][a-z0-9_-]*\z/';

    /**
     * How long a slug may get before it is cut.
     *
     * Slugs end up in a directory name, a branch name, a project name and a
     * registry key, so the ceiling is about keeping all four readable rather
     * than about any one limit.
     */
    public const int Length = 50;

    /**
     * The slug of $text: lowercase, every run of non-alphanumerics collapsed to
     * a single dash, no dash at either end, at most {@see Length} characters.
     *
     * Empty when $text carries nothing to name a worktree with — `///` and `!`
     * both slugify to nothing, and callers refuse rather than invent a name.
     */
    public static function of(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        // The cut can land in the middle of a collapsed run, so the trailing
        // dash it leaves is stripped again rather than shipped into a path.
        return trim(substr($slug, 0, self::Length), '-');
    }

    /**
     * Whether Compose would accept $name as a project name.
     */
    public static function isProjectName(string $name): bool
    {
        return preg_match(self::ProjectName, $name) === 1;
    }

    private function __construct() {}
}
