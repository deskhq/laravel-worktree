<?php

namespace DeskHQ\LaravelWorktree\Registry;

/**
 * What a lock's owner record turned out to be worth.
 *
 * Three answers rather than two, because the third is the one that keeps this
 * safe: {@see Unknown} is *I cannot tell*, and it is treated exactly as
 * {@see Alive} is — the lock is waited on. Collapsing it into either of the
 * others is the mistake worth naming: as "gone" it breaks live locks, and as a
 * separate silent case it teaches nobody what happened.
 */
enum Liveness
{
    /** The process that took the lock is still running. */
    case Alive;

    /** It provably is not, so the lock is nobody's and may be broken. */
    case Gone;

    /** Nothing here can say — another machine's pid, on a shared home. */
    case Unknown;
}
