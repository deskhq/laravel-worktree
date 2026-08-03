<?php

namespace DeskHQ\LaravelWorktree\Registry;

/**
 * What a sweep of the fleet left behind: the keys it finished with, and the
 * keys that are still there ({@see Fleet::sweep()}).
 *
 * Both halves, always, because both commands that sweep report both. A `stop`
 * says how many worktrees went quiet *and* names the ones that would not, and a
 * `reap` says what it destroyed *and* names what survived, because a volume
 * that would not go is the thing a person has to act on. The keys nothing was
 * done to are in neither list: a sweep that skipped a key said why at the time.
 */
final readonly class Sweep
{
    /**
     * @param  list<string>  $succeeded  The keys the work finished with.
     * @param  list<string>  $survived  The keys it did not.
     */
    public function __construct(
        public array $succeeded = [],
        public array $survived = [],
    ) {}
}
