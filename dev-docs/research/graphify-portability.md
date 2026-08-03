# Is a copied graphify graph still valid on another branch?

Research for [#86](https://github.com/deskhq/laravel-worktree/issues/86), feeding the `carry` spec in [#84](https://github.com/deskhq/laravel-worktree/issues/84).

**Where this lives.** The repo has no existing notes directory — and `/docs` is gitignored
(`.gitignore`), so it cannot go there. `dev-docs/research/` is new, chosen to be a tracked home
for map research that is not part of the published package.

**Primary sources.** The real artifact at `/Users/epaul/www/z_perso/the-desk/graphify-out`
(25M, built 2026-08-03 09:16–09:18), the skill source at `/Users/epaul/.claude/skills/graphify/`,
and the installed library at
`/Users/epaul/dotfiles/.local/share/uv/tools/graphifyy/lib/python3.14/site-packages/graphify/`
(referred to below as `graphify/`). Everything is cited to the file and line it came from. Nothing
in `the-desk` or its worktrees was modified.

---

## Answers in one paragraph each

**1. What breaks on arrival.** Exactly one file: `.graphify_root`, which holds the absolute path
`/Users/epaul/www/z_perso/the-desk`. Every other path in the 25M directory is repo-relative or
content-addressed, deliberately so. The graph also carries a `built_at_commit` SHA, but nothing
validates it — it is display metadata.

**2. Incremental update.** Yes, and cheaply. Change detection falls back to an MD5 content
compare when mtimes differ, so a fresh checkout's all-new mtimes cost one hash pass, not a rebuild.
Only files whose content actually differs between the built commit and the worktree's branch get
re-extracted, and code-only changes skip the LLM entirely. **A copy is a legitimate seed, not a
stale artifact — provided `.graphify_root` is rewritten.**

**3. Write-heavy at query time.** Yes, heavily — including an `fcntl` lock, a mutable stat index,
and a file written on every single query. **Sharing one directory between worktrees via symlink is
unsafe**, and unsafe in the silent way: the last worktree to rebuild overwrites `graph.json` with
its own branch's content, and every sibling then queries another branch's code.

---

## The artifact

`ls -la` and `du -sh` of `/Users/epaul/www/z_perso/the-desk/graphify-out`:

| Path | Size | Format | Portable? |
|---|---|---|---|
| `graph.json` | 12M | networkx node-link JSON, 10895 nodes | yes (relative `source_file`) |
| `cache/ast/v0.9.32/<sha256>.json` | 10M | content-addressed AST cache | yes (no path in key) |
| `cache/semantic/pd5fd89c46bb5/<sha256>.json` | 972K | content-addressed semantic cache | yes (no path in key) |
| `graph.html` | 664K | standalone viewer | yes |
| `manifest.json` | 452K | 2342 entries, `path -> {mtime, ast_hash, semantic_hash}` | yes (relative keys) |
| `cache/stat-index.json` | 416K | mutable `(size, mtime_ns) -> hashes` index, mode `0600` | yes on disk, but see Q3 |
| `GRAPH_REPORT.md` | 108K | generated report | yes |
| `.graphify_labels.json` | 17K | community labels | yes |
| `cost.json` | 219B | token ledger, 1 run | yes |
| `.graphify_python` | 64B | **absolute** interpreter path | machine-local |
| `.graphify_root` | 34B | **absolute** scan root | **breaks on move** |
| `cache/last_query_stamp` | 17B | unix float, rewritten per query | n/a (runtime) |

Absent but created on demand: `.rebuild.lock`, `needs_update`, `cache/hook_sessions/`, `.vocab.txt`,
`memory/`, `reflections/LESSONS.md` (see Q3).

---

## Q1 — What does it embed that a move would invalidate?

### Absolute paths: two files, and only one matters

`grep -c '/Users/epaul'` returns **0** for `graph.json`, `manifest.json`, `graph.html`,
`GRAPH_REPORT.md`, `.graphify_labels.json` and `cache/stat-index.json`. `grep -rl` over
`cache/ast/` and `cache/semantic/` returns no files. The only two hits in the whole 25M tree are:

- **`.graphify_root`** — `/Users/epaul/www/z_perso/the-desk`. Written by `SKILL.md:100`
  (`# Save scan root so 'graphify update' (no args) knows where to look next time`).
- **`.graphify_python`** — `/Users/epaul/dotfiles/.local/share/uv/tools/graphifyy/bin/python`.
  Machine-local, so it survives a same-machine worktree copy; it would break on a different machine.

`.graphify_root` is the whole problem, and its failure mode is silent. Four call sites read it and
scan whatever it names:

- `graphify/cli.py:1922-1930` — `graphify update` **with no path argument** recovers the scan root
  from `.graphify_root` and rebuilds from there.
- `graphify/hooks.py:136-141` — the PostToolUse edit-rebuild hook body.
- `graphify/hooks.py:185-190` — the post-checkout rebuild hook body.
- `graphify/build.py:356-377` (`_infer_merge_root`) — "Prefers the committed
  `graphify-out/.graphify_root` marker — the authoritative scan root", used to relativize paths
  during a merge.

So a bare copy into `the-desk-worktrees/<slug>/` produces a worktree where `graphify update` scans
**the main checkout**, merges the main checkout's content into the worktree's graph, and never looks
at the worktree's own files. No error is raised. **Any `carry` implementation that copies
`graphify-out/` must rewrite `.graphify_root` to the destination**, or the carried graph is actively
wrong rather than merely stale.

The escape hatch already exists and is already documented downstream: `the-desk`'s own
`.gitignore` reads `# Generated knowledge graph, regenerable with 'graphify update .'` — the
explicit `.` bypasses `.graphify_root` (`cli.py:1922`, `watch_arg is not None`).

### A commit SHA, recorded but never enforced

`graph.json` ends with:

```json
"built_at_commit": "6daaff045e3627442218f42d31f7148c11a992ab"
```

Written by `graphify/export.py:315-317` from `_git_head()`. Verified against `the-desk`: that
commit is `test: prove the team roster on the shell, not through a rendered page (#1178)`,
2026-08-03 08:55. Current `develop` HEAD is `1fdd56f`, **18 commits ahead**. The graph on disk is
already stale against its own checkout.

It is used only for display — `graphify/report.py:135-139` (`- Built from commit: ...`) and
`graphify/callflow_html.py:1334` — and is explicitly *excluded* from change comparison
(`graphify/watch.py:702` and `:721`, `canonical.pop("built_at_commit", None)`). **Nothing refuses
to serve a graph whose SHA does not match HEAD.** An agent that trusts the stamp is trusting
metadata no code validates.

### No git refs

`grep -oE 'refs/heads/[...]'` over `graph.json`, `manifest.json` and `GRAPH_REPORT.md` returns
nothing. There is no branch name anywhere in the artifact.

### Everything else is relative by design

- `manifest.json`: 2342 entries, **0 absolute keys**. Keyed `app/Actions/Channels/PostMessage.php`
  → `{"mtime": ..., "ast_hash": ..., "semantic_hash": ...}`.
- `graph.json`: 10895 nodes, `source_file` values relative (`resources/js/images/shell/desktop-dark.png`).
- `cache/ast/` and `cache/semantic/`: filenames are `sha256(content + salt)`
  (`graphify/cache.py:382-386`). The key contains no path at all.
- `cache/stat-index.json`: serialized with relative keys — `graphify/cache.py` `_flush_stat_index`,
  "store in-anchor keys as forward-slash relative paths **so the index survives a corpus
  move/clone**".

This is intentional, not incidental. `graphify/detect.py:1548-1600` implements
`_to_relative_for_storage` / `_to_absolute_from_storage` specifically so manifests re-anchor to a
new root on load (`load_manifest(..., root=)`, `detect.py:1603-1627`). The skill states the goal
outright at `references/update.md:143-146`:

> root= matches the build_merge call above so the manifest keys stay relative to the scan root —
> **portable across clones/machines**, so --update keeps matching cached files instead of missing
> every one after a move (#1417).

**Verdict.** The artifact was designed to be moved. One file was left behind by that design, and
it is the one that decides what gets scanned.

---

## Q2 — Can it incrementally update against a moved HEAD?

**Yes.** `graphify/detect.py:1802` `detect_incremental(root, ...)` is the incremental path,
driven by `references/update.md`. Two modes: `kind="ast"` for `graphify update`, `kind="semantic"`
for `graphify extract`.

### mtime is a fast path; content hash decides

This is the fact that settles the question. `graphify/detect.py:1877-1898`:

```python
stored_mtime = stored.get("mtime")
...
if stored_mtime is None or current_mtime != stored_mtime:
    # mtime bumped — verify with content hash before re-extracting
    changed = _md5_file(Path(f)) != stored_hash
else:
    changed = False
```

and the docstring at `detect.py:1822-1824`:

> Fast path: mtime unchanged + hash matches → unchanged (free, no disk IO beyond stat).
> Slow path: mtime bumped → compare MD5 against the relevant hash field before re-extracting.

A fresh `git worktree add` writes every file at checkout time, so **every** mtime will differ from
the carried manifest. That does not trigger a rebuild. It triggers one MD5 pass over the 2342-file
corpus, after which only files whose *content* differs between `built_at_commit` and the worktree's
branch are re-extracted. For a feature branch a few commits off `develop`, that is a handful of
files.

(The `!=` rather than `>` is deliberate for the legacy schema too — `detect.py:1869-1871`: "so
backwards mtime motion (**git checkout of an older commit**, tarball restore, rsync --times) still
triggers a re-extract; the previous `>` silently kept the stale cache and the graph drifted from
disk (#1859)".)

### Refresh cost

- **Code-only changes**: `references/update.md:64` — "Code-only changes detected - skipping
  semantic extraction (no LLM needed)", runs AST extraction only, "skip Step 3B entirely (no
  subagents)". The CLI path prints the same (`cli.py:1936`, "Re-extracting code files ... (no LLM
  needed)").
- **Any doc/paper/image changed**: full Steps 3A–3C with LLM subagents.
- **Deletions only**: an empty extraction is synthesised purely to let the merge prune
  (`update.md:69-80`).

For scale: `cost.json` records the single full build as **1,198,501 input tokens over 2342 files**.
An incremental refresh of a few changed files is orders of magnitude below that, and free of LLM
cost when the changes are code.

### What this means for the spec

A carried graph is a **seed**, not a trap — the ticket's first branch. Staleness is real but
bounded and closed by one cheap command (`graphify update .`, with the explicit `.`). The spec does
**not** need to declare a carried graph misleading.

It does need to say two things out loud:

1. The copy is not usable until `.graphify_root` names the worktree. Without that rewrite,
   `graphify update` (bare) silently re-scans the main checkout.
2. `built_at_commit` will keep reading as the main checkout's commit until a rebuild regenerates
   `graph.json`, and nothing enforces it.

---

## Q3 — Is it write-heavy at query time?

**Yes — this is the decisive answer, and it rules out sharing.**

### Observed live

The graph was built 09:16–09:18. `cache/last_query_stamp` has mtime **18:03** — nine hours later,
from reads alone. The directory is not a read-only artifact.

### Full write inventory

| What | When | Source |
|---|---|---|
| `cache/last_query_stamp` | every `query` / `path` / `explain` | `cli.py:437-446` (`_touch_query_stamp`), called at `cli.py:970`, `:1300`, `:1434` |
| `cache/hook_sessions/<sid>.denied` | first strict read-block per agent session, `O_CREAT\|O_EXCL` | `cli.py:460-474`, reached from `cli.py:611-615` |
| `.vocab.txt` | **every single query** — the skill's mandatory Step 0 dumps the whole node vocabulary | `references/query.md:43` |
| `cache/stat-index.json` | flushed at interpreter exit whenever dirty | `cache.py:269` (`atexit.register(_flush_stat_index)`), dirty set at `cache.py:402` |
| `.rebuild.lock` | held for the duration of any rebuild — a real `fcntl.flock` | `watch.py:157-214` |
| `needs_update` | written and cleared as a staleness flag | `watch.py:1288`, `:1333`, `:1533-1534`, `:1560`, `:1569`; read at `cli.py:601` |
| `memory/*.md`, `reflections/LESSONS.md` | after every answered query (`save-result`) | `query.md:171`, `:249`, `:310`; `hooks.py:144-152` |
| `graph.json`, `manifest.json`, `GRAPH_REPORT.md`, `graph.html`, `cost.json`, `cache/ast/**` | every update | `update.md` |

### And it is live in `the-desk` today

`the-desk/.claude/settings.json` — **a tracked file, so every worktree inherits it** — registers:

```json
{"matcher": "Bash|Grep", "hooks": [{"command": ".../graphify hook-guard search"}]}
{"matcher": "Read|Glob", "hooks": [{"command": ".../graphify hook-guard read"}]}
```

So merely reading a file in a worktree runs graphify code that stats `cache/last_query_stamp`,
reads `manifest.json` (`cli.py:622-634`, `_target_is_indexed`), and may create a session marker.
Note `paths.py:287` `out_path` resolves `graphify-out` relative to **cwd** (overridable by
`GRAPHIFY_OUT`), not via `.graphify_root` — so the guard looks in the worktree, finds nothing, and
stays quiet. That is precisely the "agent falls back to reading files directly" symptom #84
describes.

### Why sharing one directory between worktrees fails

Ordered by severity:

1. **Correctness, silently.** There is one `graph.json`, one `manifest.json`, one
   `built_at_commit`. Whichever worktree rebuilds last overwrites the graph with *its* branch's
   content. Every sibling worktree then queries another branch's code, with no signal that it is
   doing so. This is exactly the "quietly mislead an agent" failure the ticket wants avoided —
   sharing *creates* it rather than avoiding it.
2. **The lock is per-directory, not per-checkout.** `watch.py:158` `_rebuild_lock(out_dir)` flocks
   `<out_dir>/.rebuild.lock`. Two worktrees sharing a directory serialize on the same lock. The
   hook path is **non-blocking** (`watch.py:186`, `LOCK_EX | LOCK_NB`, yields `False`), so worktree
   B's hook-driven rebuild is *skipped* while A holds it — B's edits never land in the graph. Only
   interactive `graphify update` blocks (`cli.py:1940`, `block_on_lock=True`).
3. **The stat index thrashes.** `cache/stat-index.json` keys on relative path plus
   `(size, mtime_ns)` (`cache.py:388-401`). Sibling worktrees have identical relative paths and
   different `mtime_ns`, so each worktree's flush invalidates the other's entries and the hash fast
   path never hits. `_flush_stat_index` additionally *prunes* every key whose file does not exist
   relative to the flushing process's anchor.
4. **Concurrent flushes lose data.** `_flush_stat_index` writes via `mkstemp` + `os.replace` —
   atomic per write, but unlocked. Two processes exiting together: one result is silently
   discarded.
5. **Runtime state leaks across worktrees.** `last_query_stamp` and `cache/hook_sessions/` are
   global to the directory. A query in worktree A satisfies the strict read-guard TTL in worktree B
   (`cli.py:449-457`, default 1800s), suppressing the orientation nudge in a worktree that was
   never oriented.

### The one part that *is* shareable

`cache/ast/` and `cache/semantic/` are content-addressed — `sha256(content + salt)`,
`cache.py:382-386` — with no path in the key. Identical file content hits the same cache entry
regardless of which checkout it lives in, and these two directories are **10.9M of the 25M**. If
sharing is ever wanted, that is the seam, not the whole directory.

The mechanism for such a split already exists: `cached_word_count(..., cache_root=)`
(`detect.py:1261-1264`) and `_ensure_stat_index(root, cache_root)` (`cache.py:238-251`) separate
the cache **file location** from the scan **root** — added, per the comment at `cache.py:242-249`,
exactly so `extract --out` keeps the cache out of the wrong `graphify-out/`. Pursuing that is
graphify's business, though, which #84 puts out of scope.

**Verdict.** Copy, do not symlink. A symlinked share trades 25M of disk for a graph that
intermittently describes the wrong branch.

---

## Consequences for the `carry` spec

Stated as findings, not as decisions — the decisions belong to #84.

1. **A copy is valid on another branch, with one mandatory fixup.** `.graphify_root` must be
   rewritten to the destination worktree. Everything else in the directory is already
   move-portable by design.
2. **Carry cannot be a pure file copy for this case.** It needs either a post-copy rewrite hook or
   an explicit acknowledgement that carried directories may contain self-referential absolute
   paths the package will not fix. Note the precedent: `src/Config/EnvFile.php` copies *and*
   rewrites.
3. **Sharing is not a viable alternative to copying** for anything with graphify's write profile.
   If `carry` ever grows a `link` mode, graphify is a counter-example that must not qualify for it.
4. **Staleness is bounded, so `carry` need not solve it** — but the fact that `built_at_commit`
   is recorded and never validated means nothing downstream will warn the agent. If the spec wants
   a warning, the package has to emit it.
5. **`the-desk`'s `.claude/settings.json` is tracked**, so worktrees already inherit the graphify
   hook-guards. A carried `graphify-out/` activates behaviour in the worktree that is currently
   dormant — carrying it is not a purely additive, inert change.
