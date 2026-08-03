# How cheap is a directory copy, and what do sibling worktree tools do about untracked sidecars?

Research for [deskhq/laravel-worktree#85](https://github.com/deskhq/laravel-worktree/issues/85), feeding the
`carry` spec mapped in [#84](https://github.com/deskhq/laravel-worktree/issues/84).

Every claim below is traced to the source that owns it: a man page on this machine, a project's own
documentation, or its source code. Measurements were taken on this machine and the command is given so
they can be re-run. Nothing here is drawn from a secondary write-up.

**Measurement host.** macOS 26.5.1 (build 25F80, `sw_vers`), APFS, `php -v` → 8.5.0, Apple NVMe.
Numbers are from a fast local SSD; on slower storage the ratios widen rather than narrow.

---

## Part 1 — what copying a directory actually costs

### 1.1 The headline measurement

A 1.1 GB tree of 2000 files (200 KB files with a 4 MB file every tenth, 20 top-level directories,
two levels deep), copied four ways on the same APFS volume. `free_KB_consumed` is the drop in
`df -k` free space across the copy — the only honest measure, for reasons in §1.6.

| Invocation | Wall time | Disk consumed |
|---|---:|---:|
| `cp -Rc src dst` (clone) | **0.27 s** | **1.2 MB** |
| `cp -R src dst` | 1.92 s | 1.18 GB |
| `ditto src dst` | 2.25 s | 1.18 GB |
| `rsync -a src/ dst/` (openrsync) | 6.77 s | 1.18 GB |
| PHP `RecursiveIteratorIterator` + `copy()` | 4.28 s | 1.18 GB |

So on APFS a clone is **~7× faster than `cp -R`, ~16× faster than a PHP-level recursive copy, ~25×
faster than rsync, and costs ~0.1 % of the disk.** A clone is close enough to free that the cost
question stops being about time or space and becomes a question about *semantics* — what a clone
means when the source later changes (§1.7).

Caveat: the page cache was warm and could not be purged without `sudo`, so the absolute numbers are
optimistic for every row. The *ratio* is not affected — a clone does no data I/O at all.

### 1.2 macOS: `cp -c` is real, and it is safe unconditionally

`man cp` on macOS 26.5.1, the `-c` option, verbatim:

> `-c`  copy files using clonefile(2). Note that if the source and target are on different
> filesystems, or the target filesystem does not support cloning, `cp` will fallback to using
> copyfile(2) instead to ensure the copy still succeeds.

That fallback clause is the whole story: **`cp -Rc` never needs a capability probe.** BSD `cp`
already runs it. Verified empirically by cloning onto an HFS+ disk image (`hdiutil create -fs HFS+`):

| Case | Exit | Result |
|---|---:|---|
| `cp -c` APFS → HFS+ (cross-filesystem, `EXDEV` territory) | 0 | 16 MB file, `cmp` identical |
| `cp -Rc` APFS → HFS+ | 0 | tree copied |
| `cp -c` within HFS+ (filesystem cannot clone at all) | 0 | 16 MB file, `cmp` identical |

`clonefile(2)` (`man 2 clonefile`) is the syscall underneath:

> The `clonefile()` function causes the named file *src* to be cloned to the named file *dst*. The
> cloned file *dst* shares its data blocks with the *src* file but has its own copy of attributes and
> extended attributes […] Subsequent writes to either the original or cloned file are private to the
> file being modified (copy-on-write).

Three cautions from that page that matter to a spec:

1. **Do not clone directories with the syscall.** "Cloning directories with these functions is
   strongly discouraged. Use `copyfile(3)` to clone directories instead." This is not theoretical —
   see the worktrunk regression in §2.3.
2. **`dst` must not exist.** "The named file *dst* must not exist for the call to be successful."
   BSD `cp -c` handles this for you: copying over an existing file returns exit 0 and the
   destination has the source's contents (measured). But a bespoke implementation calling the
   syscall must unlink first.
3. **Deferred `ENOSPC`.** "Since the `clonefile()` system call might not allocate new storage for
   data blocks, it is possible for a subsequent overwrite of an existing data block to return
   `ENOSPC`." A clone can succeed on a nearly-full disk and the *write* fail later.

The documented capability probe, if one is ever wanted: "A volume can be tested for `clonefile()`
support by using `getattrlist(2)` to get the volume capabilities attribute `ATTR_VOL_CAPABILITIES`,
and then testing the `VOL_CAP_INT_CLONE` flag."

**A clone is more faithful than a plain copy, not less.** Because `clonefile(2)` copies attributes,
`cp -Rc` preserves what `cp -R` drops. Measured:

| Attribute | `cp -R` | `cp -Rc` (no `-p`) |
|---|---|---|
| mtime | **lost** (reset to now) | preserved |
| mode `0755` on a script | preserved | preserved |
| mode `0600` on a file | preserved | preserved |
| directory mode `0700` | preserved | preserved |
| symlinks (incl. broken) | preserved as links | preserved as links |

### 1.3 Linux: `cp --reflink=auto`, and the version that decides whether you need to say it

GNU coreutils manual, `cp` invocation, `--reflink[=when]` — verbatim:

> Perform a lightweight, copy-on-write (COW) copy, if supported by the file system.
> - **always**: If the copy-on-write operation is not supported then report the failure for each file and exit with a failure status.
> - **auto**: If the copy-on-write operation is not supported then fall back to the standard copy behavior. **This is the default if no `--reflink` option is given.**
> - **never**: Disable copy-on-write operation and use the standard copy behavior.

<https://www.gnu.org/software/coreutils/manual/html_node/cp-invocation.html>

"This is the default" is load-bearing but *recent*. coreutils `NEWS`
(<https://github.com/coreutils/coreutils/blob/master/NEWS>):

- **9.0 (2021-09-24)** — "cp and install now default to copy-on-write (COW) if available. I.e., cp
  now uses `--reflink=auto` mode by default." Same release: "cp, install and mv now use the
  `copy_file_range` syscall if available."
- **9.2 (2023-03-20)** — "cp, mv, and install now immediately acknowledge transient errors when
  creating copy-on-write or cloned reflink files, on supporting file systems like XFS, BTRFS, APFS,
  etc."
- **9.3 (2023-04-18)** — "cp `--reflink=auto` (the default), mv, and install will again fall back to a
  standard copy in more cases." (i.e. 9.2 broke the fallback in some cases and 9.3 restored it.)
- **9.4 (2023-08-29)** — "`cp --sparse=never` will avoid copy-on-write (reflinking) and copy
  offloading, to ensure no holes present in the destination copy."

Consequence for a spec: **pass `--reflink=auto` explicitly.** Long-support distributions still ship
pre-9.0 coreutils, where the default is `never` and a plain `cp -a` copies every byte —
Ubuntu 22.04 LTS ships **8.32-4.1ubuntu1** (<https://packages.ubuntu.com/jammy/coreutils>), against
Debian 12's **9.1-1** (<https://packages.debian.org/bookworm/coreutils>). The flag is a no-op on 9.x
and the difference between free and not-free on 8.32. `--reflink=always` is the flag to *avoid*: it
turns an unsupported filesystem into a hard failure.

BusyBox `cp` (Alpine images, some minimal CI runners) does implement `--reflink[=auto]`, gated on a
build option that defaults on — `coreutils/cp.c`:

```
//config:config FEATURE_CP_REFLINK
//config:	bool "Enable --reflink[=auto]"
//config:	default y
//config:	depends on FEATURE_CP_LONG_OPTIONS
```

<https://github.com/mirror/busybox/blob/master/coreutils/cp.c>. Note the `depends on
FEATURE_CP_LONG_OPTIONS`: on a BusyBox built without long options, `--reflink=auto` is an *error*,
not a silent no-op.

### 1.4 Which Linux filesystems can actually reflink

`ioctl_ficlonerange(2)` (man7.org, kernel man-pages) is the mechanism GNU `cp` uses:

> If a filesystem supports files sharing physical storage between multiple files ("reflink"), this
> `ioctl(2)` operation can be used to make some of the data in the *src_fd* file appear in the
> *dest_fd* file by sharing the underlying storage, which is faster than making a separate physical
> copy of the data.

Introduced in **Linux 4.5** (previously the btrfs-specific `BTRFS_IOC_CLONE`). Failure modes:
`EOPNOTSUPP` / `EBADF` / `EINVAL` when the filesystem cannot reflink, **`EXDEV` when the two files
are not on the same mounted filesystem**, `ETXTBSY` on swap files.

The authoritative list of supporting filesystems is the set of in-tree implementers of
`remap_file_range` (GitHub code search over `torvalds/linux`, paths under `fs/`):

`fs/btrfs/`, `fs/xfs/`, `fs/ocfs2/`, `fs/nfs/nfs4file.c` (NFSv4.2 server-side clone),
`fs/smb/client/`, `fs/overlayfs/file.c`, plus the generic plumbing in `fs/remap_range.c` and
`fs/dax.c`.

**`fs/ext4` has no `remap_file_range` implementation** (search returns 0 hits). ext4 is the default
root filesystem on Ubuntu and Debian. So for a large fraction of Linux developers, `cp
--reflink=auto` degrades to a full byte copy and is exactly as expensive as `cp -a`.

Where reflink *is* available:

- **XFS** — on by default. `man mkfs.xfs`, `-m reflink=value`: "By default, `mkfs.xfs` will create
  reference count btrees and therefore will enable the reflink feature." Requires the default
  `-m crc=1`; incompatible with the `dax` mount option. XFS is the default on RHEL and derivatives.
- **btrfs** — reflink is native; this is where the ioctl came from.
- **OpenZFS ≥ 2.2** — from the 2.2.0 release notes: "Block cloning […] a shallow copy made where the
  existing data blocks are referenced rather than copied […] This facility is used to implement
  'reflinks' or 'file-level copy-on-write'. Many common file copying programs, including newer
  versions of `/bin/cp` on Linux, will try to create clones automatically."
  <https://github.com/openzfs/zfs/releases/tag/zfs-2.2.0>
- **ext4, tmpfs, f2fs, and every overlay in a plain Docker container's writable layer** — no.

`copy_file_range(2)` is the other in-kernel path (used by coreutils ≥ 9.0 and by PHP, §1.7). It
"gives filesystems an opportunity to implement copy acceleration techniques, such as the use of
reflinks or server-side-copy (in the case of NFS)". Cross-filesystem behaviour is version-dependent:
`EXDEV` before Linux 5.3, a kernel fallback copy from 5.3, and from 5.19 "cross-filesystem copies
can be achieved when both filesystems are of the same type". <https://man7.org/linux/man-pages/man2/copy_file_range.2.html>

### 1.5 The portability trap, measured

The two flags are not merely different — each is a hard error on the other platform. On this
machine:

```
$ cp --reflink=auto a b
cp: illegal option -- -
usage: cp [-R [-H | -L | -P]] [-fi | -n] [-aclpSsvXx] source_file target_file
$ echo $?
64
```

And GNU `cp` has no `-c` at all. Its short-option string in `src/cp.c` is
`"abdfHilLnprst:uvxPRS:TZ"` — no `c`
(<https://github.com/coreutils/coreutils/blob/master/src/cp.c>), so `cp -c` exits with `invalid
option -- 'c'`. There is no invocation that works on both. **A spec must branch on platform, not
probe for a flag.**

The cheapest correct branch is `PHP_OS_FAMILY === 'Darwin'`, because the two branches are
*unconditionally* safe on their own platform:

| Platform | Invocation | Why it is safe without probing |
|---|---|---|
| Darwin | `cp -Rc <src> <dst>` | BSD `cp` falls back to `copyfile(2)` internally on any non-cloning or cross-device target (man page + measured) |
| everything else | `cp -a --reflink=auto <src> <dst>` | `auto` is documented to "fall back to the standard copy behavior"; harmless on coreutils ≥ 9.0 where it is already the default |

`-a` on the Linux branch is not optional: **`FICLONE` clones data extents only and does not carry
mode bits.** Worktrunk's CHANGELOG, on the macOS-specific optimisation of skipping a follow-up
`chmod`: "Linux (btrfs/XFS) still sets permissions, since `FICLONE` clones data extents only and
drops the execute bit."
([PR #3149](https://github.com/max-sixty/worktrunk/pull/3149)). GNU `cp` restores mode itself; a
bespoke `FICLONE` implementation would not.

One `cp` gotcha that bites both platforms: `cp -R src dst` where `dst` already exists nests into
`dst/src` rather than merging. Measured on macOS; it is standard `cp` semantics. If the destination
may exist, the source must be spelled `src/.` or the destination must be a not-yet-existing path.

### 1.6 `du` lies about clones; only free space tells the truth

After the 1.1 GB clone, `du -sh` reported **1.1 G for the clone** and 1.1 G for the plain copy — they
are indistinguishable to `du`, because each file reports its own allocated blocks and APFS attributes
the shared extents to both. Free space had moved by 1.2 MB.

This matters beyond measurement: **any future `worktree list` disk-usage column, or any "am I about
to fill the disk" preflight, will over-report by the full size of every carried cache** if it is
built on `du`. `df` deltas, or `--apparent-size`-style accounting, are the only truthful basis.

### 1.7 What PHP's own copy costs, and the one place it is free

PHP has **no** binding for `clonefile` — a code search for `clonefile` across `php/php-src` returns
0 hits. On macOS, PHP-level copying can never be a clone.

But `copy()` is not a naive read/write loop on Linux. `copy()` → `php_copy_file_ctx()` →
`php_stream_copy_to_stream_ex()` (`ext/standard/file.c`), and in `main/streams/streams.c` on the
`PHP-8.5` branch:

```c
#ifdef HAVE_COPY_FILE_RANGE
	if (php_stream_is(src, PHP_STREAM_IS_STDIO) &&
			php_stream_is(dest, PHP_STREAM_IS_STDIO) &&
			src->writepos == src->readpos) {
		/* both php_stream instances are backed by a file descriptor, are not filtered and the
		 * read buffer is empty: we can use copy_file_range() */
```

with the comment a few lines down: "For networking file systems like NFS and Ceph, it even
eliminates copying data to the client, **and local filesystems like Btrfs and XFS can create shared
extents.**"

This path is present in the `PHP-8.2` branch and absent in `PHP-8.1` (grep for
`HAVE_COPY_FILE_RANGE` in `main/streams/streams.c`: 2 hits on 8.2–8.5, 0 on 8.1). So:

- **Linux, PHP ≥ 8.2, btrfs/XFS-reflink/ZFS 2.2** — PHP's own `copy()` already reflinks. A
  PHP-level recursive copy is as cheap as `cp --reflink=auto` there.
- **Linux, ext4** — `copy_file_range` still avoids the user-space round trip, so PHP's copy is
  faster than a naive loop but consumes the full size.
- **macOS** — no clone path at any PHP version. Measured: 4.28 s and 1.18 GB, versus 0.27 s and
  1.2 MB for `cp -Rc`. **PHP's copy is the single worst option on the platform where the best option
  is best.**

For completeness, PHP 8.6 generalises this into a new internal `php_io_copy()` API
(`main/io/php_io_{linux,macos,freebsd,solaris,windows}.c`, `UPGRADING.INTERNALS` for "PHP 8.6
INTERNALS UPGRADE NOTES": "php_io_copy() copies bytes between file descriptors using the most
efficient platform primitive available (sendfile, splice, copy_file_range, TransmitFile)"). The
macOS implementation uses `sendfile()`, **not** `clonefile` — so PHP 8.6 does not change the macOS
picture either.

Also relevant: `composer.json` requires only `illuminate/contracts`, `spatie/laravel-package-tools`,
`symfony/process` and `symfony/yaml`. There is no `symfony/filesystem` and no `illuminate/filesystem`
dependency, so `Filesystem::copyDirectory()` is a host-application affordance the package does not
declare. A PHP-level recursive copy would have to be hand-rolled or a dependency added.

### 1.8 Is `rsync` safely assumable in 2026? No.

**On macOS 26.5.1, `/usr/bin/rsync` is not rsync.** Measured on this machine:

```
$ rsync --version
openrsync: protocol version 29
rsync version 2.6.9 compatible
```

Apple's own `rsync` open-source project confirms the swap: the `rsync-184` tag of
`apple-oss-distributions/rsync` contains both an `rsync/` and an `openrsync/` directory
(`gh api repos/apple-oss-distributions/rsync/contents?ref=rsync-184`). openrsync is a
BSD-licensed reimplementation that advertises compatibility with rsync **2.6.9** — a 2006 release.

The practical consequence, measured:

```
$ rsync -a --info=progress2 src/ dst/
rsync: unrecognized option `--info=progress2'
```

`--info` arrived in GNU rsync 3.1.0. Any flag newer than 2.6.9 is a hard failure on a stock modern
Mac. And openrsync has no reflink support of any kind — the 6.77 s / 1.18 GB row in §1.1 is what
`rsync -a` costs on APFS, the slowest of everything measured.

`rsync` is also not installed by default on many minimal Linux images (Debian `slim`, Alpine without
`apk add rsync`, several official language base images). **`cp` is POSIX and always present;
`rsync` is neither guaranteed present nor consistent in its flag set.** If a spec wants
`rsync`-shaped behaviour — incremental re-sync, `--exclude`, `--delete` — it must be a documented
opt-in with a preflight that names the missing binary, not an assumption.

### 1.9 What actually constrains the decision

1. **A clone is not a link.** After `cp -Rc`, the two trees are independent: writing to the clone
   leaves the original untouched, and deleting the original leaves the clone fully readable
   (measured; also `clonefile(2)`: "Subsequent writes to either the original or cloned file are
   private to the file being modified"). This is the property a symlink and a hardlink both lack,
   and it is why "copy" and "link" are genuinely different semantics rather than two
   implementations of one. A carried `graphify-out/` that is a clone is *the worktree's copy* — it
   can go stale, but it cannot be corrupted by the main checkout and cannot corrupt it.
2. **The cost is not uniform, and the worst case is the common one.** Free on APFS and on
   btrfs/XFS/ZFS-2.2; full price on ext4, on any cross-filesystem copy, and inside a container's
   overlay layer. A spec that says "copying is cheap" is true on the author's Mac and false on a
   Ubuntu-on-ext4 CI runner. 25 MB (the motivating `graphify-out/`) is fine either way; the
   ruled-out `node_modules`/`vendor` cases are exactly the ones where the difference is measured in
   gigabytes.
3. **There is no portable invocation.** Two branches, chosen on `PHP_OS_FAMILY`, each safe without
   probing. Not one command with a probe.
4. **Doing it in PHP forfeits the free case on macOS entirely** and only reaches it on Linux by
   accident of `copy_file_range`. Shelling out through `ProcessRunner` is the strictly better
   mechanism, and `ProcessRunner::attempt()` is already the shape for "keep the output, print it
   only if it failed".
5. **Cloning a directory as one syscall is a trap** — see §2.3. Per-file is the answer even though
   it is slower.

---

## Part 2 — what sibling tools do about untracked sidecars

### 2.1 Summary

| Tool | Strategy | Selection model | Default |
|---|---|---|---|
| **worktrunk** (`wt`, 6.3k★) | reflink copy, per-file | gitignored-by-default, narrowed by `.worktreeinclude` + excludes | opt-in via a `[post-start]` hook |
| **k1LoW/git-wt** (549★) | reflink copy **or** symlink, per-pattern | git *status* class (`copyignored`/`copyuntracked`/`copymodified`) + gitignore-syntax patterns | all off |
| **wtp** (satococoa) | `copy` / `symlink` / `command` hook types | explicit `from`/`to` paths | none configured |
| **gtr** (coderabbitai, 1.7k★) | `find` + `cp -r` | `gtr.copy.include`/`exclude` (+ reads `.worktreeinclude`) | none configured |
| **gwq** (d-kuro) | naive `io.Copy`, files only | glob patterns, gitignore-unaware | none configured |
| **git-worktree.nvim** (both forks) | nothing | — | a `CREATE` hook you implement |
| **jj workspaces** | nothing | — | — |
| **pnpm** | reflink → hardlink → copy from a shared store | package identity | `packageImportMethod: auto` |
| **Nx**, **Turborepo** | share the main worktree's cache by path redirection | — | automatic |

**Copy dominates. Symlink is a minority option (two tools, both as an explicit per-pattern
alternative). Nobody hardlinks.** Everyone who copies enough bytes to care converged on
reflink/CoW.

### 2.2 The selection model splits two ways

- **Path patterns** — gwq, wtp, gtr, copy-configs. Simple; gitignore-unaware, so a misconfigured
  glob will happily copy tracked files over the worktree's own checkout.
- **Git status class** — only `k1LoW/git-wt`, whose entire config surface is `git config` keys
  (`internal/git/config.go`): `wt.copyignored`, `wt.copyuntracked`, `wt.copymodified` (all default
  `false`), plus `wt.copy` / `wt.nocopy` gitignore-syntax pattern lists where `wt.nocopy` wins on
  conflict, and `wt.symlink` — "Patterns for directories to symlink instead of copy (gitignore
  syntax)". It uses `clonefile(2)` on Darwin (`internal/git/copy_file_darwin.go`) and
  `copy_file_range(2)` on Linux, with an `io.Copy` fallback — the same two-branch shape §1.5
  arrives at.
- **Both** — worktrunk: the candidate set *is* the gitignored set, and `.worktreeinclude`
  (gitignore syntax, at repo root) narrows it. From <https://worktrunk.dev/step/>:

  > All gitignored files are copied by default, except for built-in excluded directories: VCS
  > metadata (`.bzr/`, `.hg/`, `.jj/`, `.pijul/`, `.sl/`, `.svn/`), tool-state (`.conductor/`,
  > `.entire/`, `.worktrees/`), and nested worktrees. Tracked files are never touched. Discovery
  > handles nested `.gitignore` files, global excludes, and `.git/info/exclude`. Existing files in
  > the destination are skipped, so re-running is safe; `--force` overwrites them.

  Note "existing files in the destination are skipped, so re-running is safe" — the same
  write-once-never-again discipline `src/Config/EnvFile.php` already applies to `.env`.

  Worktrunk also *changed this default in a breaking way*: `.worktreeinclude` was originally
  required and specified what to copy; it was later flipped so everything gitignored copies by
  default and the file narrows. `.worktreeinclude` is becoming a cross-tool convention — `gtr`
  reads the same filename, and gwq has an open request to adopt it
  ([gwq#82](https://github.com/d-kuro/gwq/issues/82)).

### 2.3 The regression list worth treating as a test matrix

Worktrunk is the only tool in this survey that has copied enough bytes to find the sharp edges, and
its CHANGELOG names every one. Verified individually against its tracker:

| Symptom | Reference |
|---|---|
| **macOS shell freeze**: "Atomic `clonefile()` on directories saturated disk I/O, blocking shell startup. Now uses per-file reflink which is slower but keeps the system responsive." | CHANGELOG, reverting the 0.14.0 feature "Atomic COW directory cloning on macOS […] ~12-15x faster for large directories like `target/`" |
| Untracked symlinks copied as regular files — broke `node_modules/.bin/` | [#1488](https://github.com/max-sixty/worktrunk/issues/1488) (closed) |
| Execute bit lost on reflink copy | [#1936](https://github.com/max-sixty/worktrunk/issues/1936) (closed) |
| Directory permissions lost — `0700` (Postgres data dirs) became `0755` | [#1589](https://github.com/max-sixty/worktrunk/issues/1589) (closed) |
| Copied nested worktrees into themselves when worktrees live inside the main worktree | [#641](https://github.com/max-sixty/worktrunk/issues/641) |
| **Path escape**: a symlinked destination directory let the copy write *outside* the worktree; `--force` overwrote outside files | [PR #2501](https://github.com/max-sixty/worktrunk/pull/2501) |
| FD exhaustion ("too many open files") on large trees | [#1865](https://github.com/max-sixty/worktrunk/issues/1865) |
| Broke on non-ASCII filenames (git `quotePath` escaping) | [PR #3487](https://github.com/max-sixty/worktrunk/pull/3487) |
| Failed in bare-repo setups | [#598](https://github.com/max-sixty/worktrunk/issues/598) |

The first row is the important one for this package. It is the clonefile man page's "cloning
directories with these functions is strongly discouraged" playing out in the field: the fast path
was ~12–15× faster and had to be abandoned because it made the machine unusable while it ran.
**Per-file, not per-directory.** `cp -Rc` is per-file by construction (it walks the tree and clones
leaves), so shelling out to `cp -Rc` gets this right for free — the trap is only there for an
implementation that reaches for `clonefile()` on the directory itself.

The second important row, from the other direction: worktrunk found it must `chmod` after every
Linux reflink because `FICLONE` drops mode, but can skip it on macOS because `clonefile` preserves
it ([PR #3149](https://github.com/max-sixty/worktrunk/pull/3149)). §1.2 measures the macOS half of
that; it is why the Linux branch needs `-a`.

### 2.4 The characteristic bug is the silent no-op

Two independent codebases, same failure: **the worktree is created and reported successful, the
sidecars silently are not.**

- [gwq#84](https://github.com/d-kuro/gwq/issues/84) (closed) — `setup_commands` and `copy_files` did
  nothing when `gwq add` was run *from inside an existing worktree*, because repository identity came
  from `git rev-parse --show-toplevel` instead of `--git-common-dir`. No error.
- [wtp#78](https://github.com/satococoa/wtp/issues/78) (closed) — every hook was mandatory, so a
  `copy` hook naming a file that did not exist (`compose.override.yaml`) aborted the rest of the
  chain: "Warning: Hook execution failed: failed to execute hook 3: source path does not exist" —
  hooks 4 and 5 never ran, worktree reported created.

This is precisely the trap `config/worktree.php`'s standing position is aimed at, and it is the
strongest argument for `carry` refusing loudly (like the pre-create compose port check) rather than
warning.

### 2.5 Copying untracked files is a security surface, and most tools ignore it

- [gwq#105](https://github.com/d-kuro/gwq/issues/105) (closed) — "Security: Local `.gwq.toml`
  `setup_commands` are executed without user confirmation." A cloned repository shipping its own
  config got arbitrary code execution on `gwq add`. Fixed with a direnv-style trust store keyed by
  `(absolute path, sha256)` in a `0600` file, with a `[y/N]` prompt.
- Worktrunk requires per-command approval for project hooks, stored in `approvals.toml`.
- `gtr` documents a dev-vs-production `.env` split and warns that `node_modules` subdirectories
  (`.npm`, `.cache`) may hold tokens.
- wtp, copy-configs and both nvim plugins say nothing.

`carry` copies *within* one machine from a checkout the user already controls, so the exfiltration
half does not apply — but the "a repository's own config file decides what gets read and written"
half does, and gwq's fix is the precedent if `carry` ever grows a command form.

### 2.6 gwq, in detail, as the counter-example

`pkg/models/models.go`:

```go
type RepositorySetting struct {
	Repository    string   `mapstructure:"repository"`
	SetupCommands []string `mapstructure:"setup_commands"`
	CopyFiles     []string `mapstructure:"copy_files"`
	BaseDir       string   `mapstructure:"basedir"`
}
```

`internal/worktree/copy_files.go` — the implementation, read in full:

- source root is the **main repository**, not the worktree you invoked from;
- matching is `doublestar.Glob(os.DirFS(srcRoot), pattern)` — **`.gitignore` is never consulted**;
- `if info.IsDir() { continue }` — **directories are silently skipped**, so `graphify-out/`-shaped
  paths cannot be carried at all ([gwq#112](https://github.com/d-kuro/gwq/issues/112), open);
- the copy is `io.Copy` into `fs.Create(dstPath)` with `MkdirAll(..., 0755)` — **no reflink, no mode
  preservation, no symlink preservation**;
- errors are non-fatal and printed to stderr.

Every one of those five is a decision `carry` has to make deliberately, and gwq is a worked example
of making them all by omission.

### 2.7 jj workspaces: nothing, by design

`docs/working-copy.md#workspaces` (<https://github.com/jj-vcs/jj/blob/main/docs/working-copy.md>) is
the whole specification of what a workspace inherits, and it says nothing about ignored, untracked
or copied files. The only thing `jj workspace add` carries is sparse patterns
(`cli/src/commands/workspace/add.rs`: `enum SparseInheritance { Copy, Full, Empty }`).

The reason it can get away with that is structural: jj auto-tracks, so git's "untracked" category
mostly collapses into "committed" —

> added files are implicitly tracked by default […] Files with paths matching [ignore files] are
> never tracked automatically.

What is left is exactly the gitignored set — `.env`, `node_modules/`, `target/` — and jj offers no
mechanism at all to bring it across. There is no hook system either
([jj#3577](https://github.com/jj-vcs/jj/issues/3577), "FR: Generalized hook support", open since
2024).

### 2.8 The JS ecosystem solved the same problem by sharing, not copying

- **pnpm.** `packageImportMethod` default is `auto` — a documented ladder: "try to clone packages
  from the store. If cloning is not supported then hardlink […] If neither cloning nor linking is
  possible, fall back to copying", where `clone` is "(AKA copy-on-write or reference link)"
  (<https://pnpm.io/settings/node-modules>). The same page: "Cloning is the best way to write
  packages to `node_modules`. It is the fastest way and safest way." And the constraint that decides
  the hardlink rung: "It is possible to set a store from a different disk but in that case pnpm will
  copy packages from the store instead of hard-linking them, **as hard links are only possible on the
  same filesystem**" (<https://pnpm.io/settings/store>).

  pnpm now has a **dedicated git-worktree page**, <https://pnpm.io/git-worktrees>, framed around
  exactly this package's use case ("pnpm + Git Worktrees for Multi-Agent Development"): with
  `enableGlobalVirtualStore: true` (added v10.12.1, default `false`), "each worktree's `node_modules`
  contains only symlinks pointing there […] no files are copied or hardlinked into the worktree at
  all." It carries the same trust caveat: "Do not use one writable pnpm store for mutually untrusted
  agents or users."

- **Nx** and **Turborepo** both went the other way and made the *cache* worktree-aware by path
  redirection rather than copying anything. Nx: "In a git worktree this resolves to the main repo's
  cache dir so all worktrees share the same cache"
  (<https://nx.dev/docs/reference/devkit/cacheDir>). Turborepo: "When you create a linked worktree
  with `git worktree add`, Turborepo detects this configuration and automatically redirects the cache
  to the main worktree's `.turbo/cache` directory"
  (<https://turborepo.dev/docs/crafting-your-repository/caching>) — with the caveat that setting an
  explicit `cacheDir` **disables** the sharing.

The pattern across all three: **the tool that owns the artifact makes it shareable; the worktree
manager does not copy it.** That is the strongest available argument for `carry` staying out of
`vendor/`/`node_modules/` — already ruled out in #84 — and it is also an argument that the ideal
long-term answer for `graphify-out/` is graphify-side, not `carry`-side. #84 has already ruled that
out of scope, correctly; but it is the reason `carry` should be framed as *carrying a cache a tool
cannot share*, not as *the way to share caches*.

### 2.9 `git worktree` does expose a hook — exactly one

`Documentation/githooks.adoc` at tag `v2.55.0`, in the `post-checkout` section
(<https://github.com/git/git/blob/v2.55.0/Documentation/githooks.adoc#L209-L212>):

> It is also run after `git clone`, unless the `--no-checkout` (`-n`) option is used. The first
> parameter given to the hook is the null-ref, the second the ref of the new HEAD and the flag is
> always 1. **Likewise for `git worktree add` unless `--no-checkout` is used.**

The call site, `builtin/worktree.c` at `v2.55.0`, in `add_worktree()`
(<https://github.com/git/git/blob/v2.55.0/builtin/worktree.c#L605-L623>):

```c
	if (!ret && opts->checkout && !opts->orphan) {
		struct run_hooks_opt opt = RUN_HOOKS_OPT_INIT_FORCE_SERIAL;

		strvec_pushl(&opt.env, "GIT_DIR", "GIT_WORK_TREE", NULL);
		strvec_pushl(&opt.args, oid_to_hex(null_oid(the_hash_algo)),
			     oid_to_hex(&commit->object.oid), "1", NULL);
		opt.dir = path;

		ret = run_hooks_opt(the_repository, "post-checkout", &opt);
	}
```

Details that matter:

- `opt.dir = path` — the hook runs with **cwd inside the new worktree**.
- `GIT_DIR` and `GIT_WORK_TREE` are pushed as bare names, i.e. **unset** in the hook's environment,
  so the hook discovers the repo from its cwd.
- `!opts->orphan` — **`git worktree add --orphan` does not run the hook.** This is not documented in
  `githooks.adoc`, which only carves out `--no-checkout`.
- Hook failure does not delete the worktree, but its exit status becomes the command's.
- It is the **only** hook invocation in `builtin/worktree.c`. There is nothing for `git worktree
  remove`, `move`, `prune`, `lock`, and no pre-add hook.

Hooks are shared across worktrees — `gitrepository-layout`: the `hooks` directory "is ignored if
`$GIT_COMMON_DIR` is set and `"$GIT_COMMON_DIR/hooks"` will be used instead" — so one script serves
every worktree. A caution if `core.hooksPath` is ever relevant: a **relative** `core.hooksPath`
resolves against the directory the hooks run in, which for `worktree add` is the *new* worktree.

**Git copies exactly two things into a new worktree**, both in `add_worktree()`:
`info/sparse-checkout` (when sparse-checkout is on) and `config.worktree` (when
`extensions.worktreeConfig` is on). **No working-tree files, no untracked files, no `.env`.** So
there is no git-level mechanism `carry` could delegate to.

One finding that lands directly on an existing decision in this package: **`$GIT_DIR/info/exclude`
is shared, not per-worktree.** `gitrepository-layout` on the `info` directory: "This directory is
ignored if `$GIT_COMMON_DIR` is set and `"$GIT_COMMON_DIR/info"` will be used instead." The lone
exception is `info/sparse-checkout`, which is why git explicitly copies it. Anything
`src/Git/Excludes.php` writes there applies to every worktree of the repository, including the main
checkout — worth confirming against that module's intent if `carry` grows an exclude of its own.

---

## Sources

**Man pages read on the measurement host (macOS 26.5.1):** `cp(1)`, `clonefile(2)`, `openrsync(1)`.

**Primary documentation and source:**

- GNU coreutils manual, `cp` invocation — <https://www.gnu.org/software/coreutils/manual/html_node/cp-invocation.html>
- GNU coreutils `NEWS` — <https://github.com/coreutils/coreutils/blob/master/NEWS>
- `ioctl_ficlonerange(2)` — <https://man7.org/linux/man-pages/man2/ioctl_ficlonerange.2.html>
- `copy_file_range(2)` — <https://man7.org/linux/man-pages/man2/copy_file_range.2.html>
- `mkfs.xfs(8)` — <https://man7.org/linux/man-pages/man8/mkfs.xfs.8.html>
- OpenZFS 2.2.0 release notes — <https://github.com/openzfs/zfs/releases/tag/zfs-2.2.0>
- Linux kernel `remap_file_range` implementers — code search over <https://github.com/torvalds/linux> under `fs/`
- BusyBox `coreutils/cp.c` — <https://github.com/mirror/busybox/blob/master/coreutils/cp.c>
- `apple-oss-distributions/rsync`, tag `rsync-184` — <https://github.com/apple-oss-distributions/rsync>
- php-src: `ext/standard/file.c`, `main/streams/streams.c`, `main/io/*`, `UPGRADING.INTERNALS` — <https://github.com/php/php-src>
- git `v2.55.0`: `Documentation/githooks.adoc`, `builtin/worktree.c`, `git-worktree(1)`, `gitrepository-layout(5)`

**Sibling tools:**

- worktrunk — <https://github.com/max-sixty/worktrunk>, <https://worktrunk.dev/step/>, <https://worktrunk.dev/hook/>, `src/copy.rs`, `CHANGELOG.md`
- gwq — <https://github.com/d-kuro/gwq>, `pkg/models/models.go`, `internal/worktree/copy_files.go`
- wtp — <https://github.com/satococoa/wtp>
- git-wt — <https://github.com/k1LoW/git-wt>, `internal/git/config.go`, `internal/git/copy_file_darwin.go`
- gtr — <https://github.com/coderabbitai/git-worktree-runner>, `lib/config.sh`
- git-worktree.nvim — <https://github.com/polarmutex/git-worktree.nvim> (`lua/git-worktree/hooks.lua`), <https://github.com/ThePrimeagen/git-worktree.nvim>
- jujutsu — <https://github.com/jj-vcs/jj/blob/main/docs/working-copy.md>, `cli/src/commands/workspace/add.rs`
- pnpm — <https://pnpm.io/settings/node-modules>, <https://pnpm.io/settings/store>, <https://pnpm.io/git-worktrees>, <https://pnpm.io/motivation>
- Nx — <https://nx.dev/docs/reference/devkit/cacheDir>, <https://nx.dev/docs/reference/nx-json>
- Turborepo — <https://turborepo.dev/docs/crafting-your-repository/caching>, <https://turborepo.dev/docs/reference/configuration>
