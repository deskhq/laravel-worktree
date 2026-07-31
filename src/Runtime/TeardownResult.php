<?php

namespace DeskHQ\LaravelWorktree\Runtime;

/**
 * What a teardown left on the machine, which is the only honest way to say
 * whether it worked.
 *
 * An exit code is not that answer. The version this design replaced logged
 * "compose teardown reported nothing to remove (already down?)" on *any*
 * non-zero exit and carried on to delete the registry entry, so a failure was
 * indistinguishable from a clean run until the disk filled — and by then nothing
 * pointed at the volumes any more (the-desk#1095). So teardown re-queries
 * afterwards, and hands back what it found.
 *
 * Three outcomes, and the middle one is the point:
 *
 * - nothing survived, and the machine was asked — done;
 * - something survived — named here, and `remove` exits non-zero over it;
 * - nothing could be asked — {@see $reason}, which is *not* success, because
 *   an unreachable daemon proves nothing about what is on its disk.
 */
final readonly class TeardownResult
{
    /**
     * @param  string  $project  The Compose project this is the teardown of.
     * @param  list<string>  $containers  Containers still carrying its label.
     * @param  list<string>  $volumes  Volumes still carrying its label.
     * @param  string|null  $reason  Why the machine could not be asked, when it could not.
     */
    public function __construct(
        public string $project,
        public array $containers = [],
        public array $volumes = [],
        public ?string $reason = null,
    ) {}

    /**
     * A teardown that never got to ask. Not a failure of the worktree, and not
     * a success either.
     */
    public static function unanswered(string $project, string $reason): self
    {
        return new self($project, reason: $reason);
    }

    public function succeeded(): bool
    {
        return $this->reason === null && $this->containers === [] && $this->volumes === [];
    }

    /**
     * Everything still on disk, in one list, for a caller that only needs to
     * name them.
     *
     * @return list<string>
     */
    public function survivors(): array
    {
        return [...$this->containers, ...$this->volumes];
    }

    /**
     * What happened, in the words a person reading the run needs.
     */
    public function describe(): string
    {
        if ($this->reason !== null) {
            return "could not confirm that $this->project is gone: $this->reason";
        }

        if ($this->succeeded()) {
            return "$this->project is gone: nothing on this machine carries its label any more";
        }

        $survived = [];

        foreach (['container' => $this->containers, 'volume' => $this->volumes] as $kind => $resources) {
            if ($resources !== []) {
                $survived[] = count($resources).' '.$kind.(count($resources) === 1 ? '' : 's').' ('.implode(', ', $resources).')';
            }
        }

        return "$this->project survived teardown: ".implode(' and ', $survived).' could not be removed';
    }
}
