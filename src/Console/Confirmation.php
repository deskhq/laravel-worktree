<?php

namespace DeskHQ\LaravelWorktree\Console;

/**
 * The human gate in front of a destructive command.
 *
 * Only `reap` has one, and only because it force-deletes Docker volumes: every
 * other command in this package acts on a worktree somebody named, while `reap`
 * acts on whatever a scan found. Provable scoping is what makes that safe to
 * offer at all, and a confirmation is what makes it safe to *default* to.
 *
 * The question goes to {@see Output}, so it lands on stderr with every other
 * diagnostic — stdout is reserved for what a script parses, and a prompt is not
 * that. The answer is read from stdin, and anything that is not a clear yes is
 * a no: a run that destroys volumes because somebody hit return has no business
 * asking in the first place.
 *
 * ## Non-interactive is not an implied yes
 *
 * {@see isInteractive()} is asked *before* the question, because with no
 * terminal there is nothing to read: `fgets` on a closed stdin returns false
 * immediately, and a gate that silently answered itself "no" would be
 * indistinguishable from one that answered "yes" the day the check was
 * inverted. So the caller refuses instead, and says `--yes` — which is a person
 * agreeing in advance rather than a prompt nobody saw.
 */
final class Confirmation
{
    /** @var resource */
    private $input;

    /**
     * @param  resource|null  $input  Defaults to STDIN.
     */
    public function __construct(
        private readonly Output $output,
        $input = null,
    ) {
        $this->input = $input ?? STDIN;
    }

    /**
     * Whether there is a person on the other end of stdin to answer.
     */
    public function isInteractive(): bool
    {
        return stream_isatty($this->input);
    }

    /**
     * Ask, and hand back only a clear yes.
     */
    public function confirm(string $question): bool
    {
        $this->output->write($question.' ');

        $answer = fgets($this->input);

        if ($answer === false) {
            return false;
        }

        return in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }
}
