<?php

namespace DeskHQ\LaravelWorktree\Doctor;

use DeskHQ\LaravelWorktree\Runtime\Status;

/**
 * What one check of `worktree doctor` came back with.
 *
 * Four answers rather than two, and the two extra ones carry the whole argument
 * of the command:
 *
 * 1. **{@see Failed}** — a create would stop here. This is the only verdict that
 *    makes the run exit non-zero, which is what lets CI run `worktree doctor`
 *    and mean it.
 * 2. **{@see Warned}** — true, worth saying, and not a refusal: `gh` absent
 *    names a worktree `issue-441` instead of `441-fix-login`, and a slot block
 *    something outside the registry is holding is one the allocator skips.
 * 3. **{@see Unchecked}** — the question could not be put. A daemon that is not
 *    answering, a `config/worktree.php` that did not load, a Compose file that
 *    would not parse: the checks behind those are not passing and are not
 *    failing, and saying so is the rule the orphan warning has always been under
 *    — silence is not proof (the-desk#1095).
 * 4. **{@see Passed}** — asked, and answered.
 *
 * The tokens are lowercase single words from a small closed set, for
 * {@see Status}'s reason: they go into `--json` and into a column somebody
 * greps, so they are not prose and do not get to become it.
 */
enum Verdict: string
{
    /** Asked, and answered: nothing here would stop a create. */
    case Passed = 'ok';

    /** True and worth saying, and not something a create stops on. */
    case Warned = 'warn';

    /** A create would stop here, which is what makes the run exit non-zero. */
    case Failed = 'fail';

    /** The question could not be put, so nothing is claimed either way. */
    case Unchecked = 'unchecked';
}
