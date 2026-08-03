<?php

namespace DeskHQ\LaravelWorktree\Doctor;

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * One check, and what it found.
 *
 * The message is the point of this class, and it is deliberately the *same*
 * message the corresponding failure would have carried: `doctor` runs the
 * pre-flights a create runs, and a report that reworded them would send somebody
 * looking for a sentence that appears nowhere else in the package. So the checks
 * that used to only throw now build their refusal in one place and hand it here
 * ({@see Examination}), and the ones that throw something this cannot ask
 * differently are caught and their {@see WorktreeException::getMessage()} is
 * carried through untouched.
 *
 * It may be several lines. A published-port collision names every mapping, why
 * each service is running, and the one edit that fixes it — that is three lines
 * per collision, and shortening them here would undo the reason they are worth
 * printing at all. {@see Report} indents the continuation lines under the first.
 */
final readonly class Finding
{
    private function __construct(
        /** The check's name — `config`, `ports`, `locks`; a token, not a sentence. */
        public string $name,
        public Verdict $verdict,
        /** What it found, in the words the run itself would have used. */
        public string $message,
    ) {}

    public static function passed(string $name, string $message): self
    {
        return new self($name, Verdict::Passed, $message);
    }

    public static function warned(string $name, string $message): self
    {
        return new self($name, Verdict::Warned, $message);
    }

    public static function failed(string $name, string $message): self
    {
        return new self($name, Verdict::Failed, $message);
    }

    public static function unchecked(string $name, string $message): self
    {
        return new self($name, Verdict::Unchecked, $message);
    }

    /**
     * The finding as `--json` publishes it.
     *
     * @return array{name: string, verdict: string, message: string}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'verdict' => $this->verdict->value,
            'message' => $this->message,
        ];
    }

    /**
     * The message as the lines it is made of, with the trailing blank ones the
     * multi-line refusals carry taken off.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return explode("\n", rtrim($this->message));
    }
}
