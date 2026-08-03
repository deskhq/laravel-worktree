<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Doctor\Examination;

/**
 * The search for the slot a create would take next, and what it passed on the
 * way.
 *
 * The one loop, run by two callers. {@see Allocator::allocate()} runs it under
 * the registry lock and claims what it finds; `worktree doctor` runs it to
 * report on the same slot without claiming anything ({@see Examination}). Both
 * used to walk the range themselves — skipping claimed slots the same way,
 * probing the same blocks, and refusing with the same sentence typed out twice
 * in two files, which had already drifted apart in two places by the time it was
 * noticed (#74).
 *
 * Nothing here writes: the search is a read of the registry and a set of binds
 * that are closed again ({@see BindProbe}), which is what makes it safe for a
 * command whose whole claim is that it creates nothing.
 *
 * **The answer is TOCTOU and is documented as such**, exactly as the probe
 * itself is. Under the registry lock the *claimed* half is a guarantee; the
 * probed half never is, because Compose binds those ports minutes later.
 */
final readonly class SlotSearch
{
    public function __construct(
        /** The lowest free slot with a free block, or null when there is none. */
        public ?int $slot,
        /**
         * The slots something outside the registry is holding a port of, in the
         * order they were passed, each with the first port that was taken.
         *
         * @var list<array{slot: int, name: string, port: int}>
         */
        public array $skipped,
        /** How many slots this machine allocates, which is what the range was. */
        public int $slots,
    ) {}

    /**
     * Whether every slot in the range is claimed in the registry — so there was
     * no block left to probe, rather than none left that was free.
     *
     * The two are different answers and the remedies are different: this one is
     * {@see Allocator::exhaustion()}'s, and a search that skipped its way to the
     * end is {@see refusal()}'s.
     */
    public function exhausted(): bool
    {
        return $this->slot === null && $this->skipped === [];
    }

    /**
     * The refusal for a range with free slots in it, none of whose blocks are
     * free — written once, here, and raised by the allocator or reported by
     * `doctor` (#74).
     */
    public function refusal(): string
    {
        return 'no free slot has a free port block ('.count($this->skipped).' of '.$this->slots
            .' slots skipped by the bind probe); stop whatever is holding those ports, or move port_base';
    }
}
