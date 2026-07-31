<?php

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * Attachment: what a worktree ends up on, and the refusal that proves it.
 *
 * The resolution these cases lean on is covered in BaseRefsTest.php; what is
 * asserted here is the commit the worktree actually forked from — by SHA,
 * because `-b <new>` silently dropping to a checkout of `<base>` (the-desk#619)
 * puts the right *name* on the wrong line of history.
 */
beforeEach(function () {
    [$this->clone, $this->root] = temporaryClone();
    $this->diagnostics = fopen('php://memory', 'w+');
    $this->worktree = $this->root.'/wt';
});

afterEach(function () {
    fclose($this->diagnostics);
    deleteDirectory($this->root);
});

it('forks a new branch from a base the clone knows only as a remote ref', function () {
    worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'develop');

    expect(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->clone, 'origin/develop'))
        ->and(trim(runGit($this->worktree, 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('441-fix-login')
        // git's DWIM would have created a local `develop` and checked that out.
        ->and(runGit($this->clone, 'branch', '--list', 'develop')->getOutput())->toBe('')
        ->and(diagnosticsIn($this->diagnostics))->toContain('creating branch 441-fix-login from origin/develop');
});

it('forks from the remote tip when the local branch of that name is behind it', function () {
    runGit($this->clone, 'checkout', '--quiet', '-b', 'develop', 'origin/develop');
    runGit($this->clone, 'checkout', '--quiet', 'master');

    runGit($this->root.'/upstream', 'checkout', '--quiet', 'develop');
    file_put_contents($this->root.'/upstream/README.md', "develop moved on\n");
    runGit($this->root.'/upstream', 'commit', '--quiet', '-am', 'develop moved on');
    runGit($this->root.'/upstream', 'checkout', '--quiet', 'master');

    worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'develop');

    expect(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->root.'/upstream', 'develop'))
        ->and(gitRevision($this->worktree, 'HEAD'))->not->toBe(gitRevision($this->clone, 'develop'));
});

it('attaches an existing local branch instead of re-forking it', function () {
    runGit($this->clone, 'branch', '441-fix-login', 'master');

    worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'develop');

    expect(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->clone, 'master'))
        ->and(gitRevision($this->worktree, 'HEAD'))->not->toBe(gitRevision($this->clone, 'origin/develop'))
        ->and(diagnosticsIn($this->diagnostics))->toContain('attaching existing branch 441-fix-login');
});

it('forks HEAD from the local checkout, not from origin/HEAD', function () {
    file_put_contents($this->clone.'/README.md', "local only\n");
    runGit($this->clone, 'commit', '--quiet', '-am', 'local only');

    worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'HEAD');

    expect(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->clone, 'master'))
        ->and(gitRevision($this->worktree, 'HEAD'))->not->toBe(gitRevision($this->clone, 'origin/master'));
});

it('forks from the repository default branch when no base is given', function () {
    worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login');

    expect(gitRevision($this->worktree, 'HEAD'))->toBe(gitRevision($this->clone, 'origin/master'))
        ->and(diagnosticsIn($this->diagnostics))->toContain('creating branch 441-fix-login from origin/master');
});

it('refuses a worktree that is on an unexpected branch, before any work runs', function () {
    runGit($this->clone, 'worktree', 'add', '--quiet', '-b', 'other', $this->worktree, 'origin/develop');

    expect(fn () => worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'develop'))
        ->toThrow(
            WorktreeException::class,
            "worktree $this->worktree is on 'other', expected '441-fix-login' — refusing to continue, commits would land on the wrong branch"
        );
});

it('refuses a path that is not a worktree at all', function () {
    mkdir($this->worktree, 0755, true);

    expect(fn () => worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'develop'))
        ->toThrow(WorktreeException::class, 'commits would land on the wrong branch');
});

it('re-enters an existing worktree without touching it', function () {
    $worktrees = worktreesIn($this->clone, $this->diagnostics);
    $worktrees->attach($this->worktree, '441-fix-login', 'develop');

    file_put_contents($this->worktree.'/README.md', "work in progress\n");
    runGit($this->worktree, 'commit', '--quiet', '-am', 'work in progress');
    $work = gitRevision($this->worktree, 'HEAD');

    $worktrees->attach($this->worktree, '441-fix-login', 'develop');

    expect(gitRevision($this->worktree, 'HEAD'))->toBe($work);
});

it('creates nothing when the base cannot be resolved', function () {
    runGit($this->clone, 'remote', 'add', 'mirror', $this->root.'/upstream');
    runGit($this->clone, 'fetch', '--quiet', 'mirror');

    expect(fn () => worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'develop'))
        ->toThrow(WorktreeException::class, 'ambiguous')
        ->and(is_dir($this->worktree))->toBeFalse();
});

it('behaves identically invoked from inside an existing worktree', function () {
    // Everything anchors to the main working tree, so the second worktree is
    // created off the same checkout and from the same base as the first.
    worktreesIn($this->clone, $this->diagnostics)->attach($this->worktree, '441-fix-login', 'develop');

    worktreesIn($this->worktree, $this->diagnostics)->attach($this->root.'/second', '512-search', 'develop');

    expect(gitRevision($this->root.'/second', 'HEAD'))->toBe(gitRevision($this->clone, 'origin/develop'))
        ->and(trim(runGit($this->root.'/second', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('512-search')
        ->and(trim(runGit($this->clone, 'worktree', 'list', '--porcelain')->getOutput()))->toContain($this->root.'/second');
});
