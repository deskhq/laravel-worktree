# Context

The words this package uses, and what each one is allowed to mean.

The README is the design document — *what collides*, and *why each decision is
the way it is*. This is the smaller thing underneath it: the vocabulary those
explanations are written in, so that a name in a class, a message on stderr and
a sentence in an issue are all talking about the same object. If you are adding
a concept, it belongs here before it belongs in a class name.

## What a command is called with

**Invocation** — one run's arguments, parsed: the positionals in order, and the
flags that were given. `src/Console/Arguments.php`. Long flags only, only the
ones the command declares, and every command's is `<command> <name> [base]
[--flags]`.

**Arity** — how many positionals a command takes, whether it can run without
the first one, and what it calls them. Declared in the same call as the accepted
flags, because `create 441 main extra` and `create 441 --refesh` are the same
kind of mistake: the command called wrong, refused before anything is read
(#75). `src/Console/Arity.php` writes those refusals, so nine commands that used
to phrase the same one apiece now phrase it once.

What it deliberately does not own is a missing name whose refusal depends on a
*flag* — `stop --all` and `unlock --all` name nothing on purpose, and `create`'s
sentence changes with `--pr`. Those commands declare only their count, and keep
that refusal.

**Usage error** — called wrong rather than failed. Exit 64, one line of usage,
and nothing touched: `src/Exceptions/UsageException.php`, answered in
`Application::run()`.

## The repository

**Checkout** — a clone of the application on this machine. There can be more
than one, side by side, which is most of why the registry is machine-global and
why `repo_slug` exists at all.

**Main checkout** — the working tree the worktrees hang off, and the directory
every command resolves to first however it was invoked. `src/Git/Anchor.php`.

**Anchor** — that resolution: the shared `.git` directory and the main checkout
above it. A command anchored inside a worktree behaves exactly as one anchored
in the main checkout.

**Worktree** — one isolated working copy, on its own branch, in
`../<repo-slug>-worktrees/<slug>`, with its own containers, its own `.env` and
its own host ports. It is the unit of everything below.

## The names one argument implies

**Identity** — every name a run derives from the argument it was given: the
`name`, the `slug`, the `key`, the `branch` and the `path`.
`src/Naming/Identity.php`, built by `src/Naming/Identities.php`.

**Slug** — the safe form of the name: `feat/checkout` and `441 Fix login` both
become `feat-checkout` and `441-fix-login`. It names the directory.

**Key**, also **project** — `wt-<repo-slug>-<slug>`. The one identifier that
reaches every layer this package has to reconcile: it is the registry key, the
Compose project name, the value of `com.docker.compose.project` on every
container and volume the worktree owns, and the name of its lock. When
something here says *key*, it means all of those at once.

**Marker** — the literal `wt-` prefix on every key. Not configurable, because it
is the whole of what scopes `reap`.

## What the machine records

**Registry** — `~/.laravel-worktree/registry.json`, one object per worktree,
keyed by project name. Machine-global rather than per-repository because host
ports are. `src/Registry/Registry.php`.

**Entry** — one worktree's row in it. The registry is a convenience rather than
the source of truth: `remove` still works with no entry at all, because both
facts a teardown needs are derivable from the name.

**Slot** — the small integer an entry holds, and the only thing allocation is
really about. **Port block** — the ports that follow from a slot. A slot is held
for as long as the entry is, including while the worktree is stopped.

**Degraded** — the bootstrap steps that failed and were allowed to, recorded on
the entry so the next run retries those and nothing else.

**Ready** — a worktree that has finished a bootstrap, marked by
`.worktree-ready`. `start` refuses one that is not; `create` resumes it.

## The two locks

**Worktree lock** — one per key, serialising the whole of one command's run
against one worktree. Held for the length of a `create`, `start` or `remove`,
and a key at a time during a sweep.

**Registry lock** — machine-wide, over the free-slot search and the write that
follows it. Held for milliseconds, never across slow work.

**Lock ordering** — the worktree's own lock first, then the registry's, always.
Stated in exactly one place, `Allocator::ordered()`, because a second place
stating it is a second place to get it backwards.

**Owner** — the record a lock directory carries about who took it, which is what
lets a run break a lock whose holder is provably gone, and what `unlock` names
when it removes one.

## The fleet

**Fleet** — the worktrees this machine is holding, and the module that owns the
lifecycle every command shares: a name in, a locked and verified worktree out.
`src/Registry/Fleet.php`.

It is the answer to seven commands each restating the same rules (#73), and it
owns them: what a name resolves to, the sentence for a name nothing holds, the
foreign-checkout refusal, the lock and both ways of holding it, the re-read once
the lock is held, and the sweep. What it deliberately does not own is *what to
do about a name nothing holds* — five commands give five different answers, and
every one of them is right for its command, so every one of them stays at its
call site.

The word was already in the codebase's prose before it was a class. `Worktrees`
was taken — `src/Git/Worktrees.php` is git's own idea of them, which is a
narrower thing: attach, detach, and what `git worktree list` says.

**Verify** — to establish that an entry belongs to *this* checkout. An entry
another checkout holds names another checkout's containers, and every command
refuses it: `src/Registry/ForeignCheckout.php` is that refusal, one sentence
with each command's own clause in the middle.

**Claim** (a lock) — take a worktree's lock and hold it to process exit.
**Sweep** — act on many worktrees, taking and giving back one key's lock at a
time. The two disciplines, and a command wants exactly one of them.

**Verdict** — what acting on one key during a sweep came to: whether it worked,
and what to say if it did not. `src/Registry/Verdict.php`.

## What is left behind

**Orphan** — a `wt-` project on the daemon that no registry entry claims. Found
by `list`, destroyed by `reap`.

**Dead entry** — the mirror image: an entry whose worktree directory is gone. It
is claimed, so it is not an orphan, and it holds its slot until something
reclaims it.

**Reap** — the sweep that deals with both. **Reclaim** — what it does to a dead
entry: tear the project down, then give the slot back, in that order.
