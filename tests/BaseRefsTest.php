<?php

use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;

/**
 * Base-ref resolution: the regression suite for the-desk#619 (git's `-b` DWIM)
 * and the-desk#639 (a stale local branch beating the remote it is behind).
 *
 * Every case runs against a throwaway clone whose `develop` exists only as
 * `origin/develop` and carries a commit `master` does not, so what was forked
 * from is assertable by SHA.
 */
beforeEach(function () {
    [$this->clone, $this->root] = temporaryClone();
});

afterEach(function () {
    deleteDirectory($this->root);
});

it('resolves a base that exists only on the remote to its remote-tracking ref', function () {
    expect(baseRefsIn($this->clone)->resolve('develop'))->toBe('origin/develop');
});

it('lets the remote copy win over a local branch of the same name', function () {
    // A local `develop` that is merely behind origin: git would have forked
    // from it, and the stale baseline would only surface later as a conflict
    // or a missing commit (the-desk#639).
    runGit($this->clone, 'checkout', '--quiet', '-b', 'develop', 'origin/develop');
    runGit($this->clone, 'checkout', '--quiet', 'master');

    runGit($this->root.'/upstream', 'checkout', '--quiet', 'develop');
    file_put_contents($this->root.'/upstream/README.md', "develop moved on\n");
    runGit($this->root.'/upstream', 'commit', '--quiet', '-am', 'develop moved on');
    runGit($this->root.'/upstream', 'checkout', '--quiet', 'master');

    $ref = baseRefsIn($this->clone)->resolve('develop');

    expect($ref)->toBe('origin/develop')
        ->and(gitRevision($this->clone, $ref))->toBe(gitRevision($this->root.'/upstream', 'develop'))
        ->and(gitRevision($this->clone, $ref))->not->toBe(gitRevision($this->clone, 'develop'));
});

it('uses a local branch when the base exists on no remote at all', function () {
    runGit($this->clone, 'checkout', '--quiet', '-b', 'epic', 'origin/develop');
    file_put_contents($this->clone.'/README.md', "epic only\n");
    runGit($this->clone, 'commit', '--quiet', '-am', 'epic only');
    runGit($this->clone, 'checkout', '--quiet', 'master');

    expect(baseRefsIn($this->clone)->resolve('epic'))->toBe('epic');
});

it('resolves HEAD and @ to the current checkout rather than to origin/HEAD', function () {
    // refs/remotes/origin/HEAD exists in any clone, so the remote lookup would
    // otherwise redirect both of these onto the upstream's default branch.
    file_put_contents($this->clone.'/README.md', "local only\n");
    runGit($this->clone, 'commit', '--quiet', '-am', 'local only');

    $baseRefs = baseRefsIn($this->clone);

    expect($baseRefs->resolve('HEAD'))->toBe('HEAD')
        ->and($baseRefs->resolve('@'))->toBe('@')
        ->and(gitRevision($this->clone, 'HEAD'))->not->toBe(gitRevision($this->clone, 'origin/HEAD'));
});

it('refuses a base carried by several remotes, naming the candidates', function () {
    runGit($this->clone, 'remote', 'add', 'mirror', $this->root.'/upstream');
    runGit($this->clone, 'fetch', '--quiet', 'mirror');

    $failure = null;

    try {
        baseRefsIn($this->clone)->resolve('develop');
    } catch (WorktreeException $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(WorktreeException::class)
        ->and($failure->getMessage())
        ->toContain("base 'develop' is ambiguous")
        ->toContain('mirror/develop')
        ->toContain('origin/develop')
        ->toContain('pass the remote-tracking ref explicitly');
});

it('refuses a base that exists nowhere, and says to fetch it', function () {
    expect(fn () => baseRefsIn($this->clone)->resolve('nope'))
        ->toThrow(WorktreeException::class, "base 'nope' does not exist locally or on any remote (fetch it first?)");
});

it('accepts anything else that resolves to a commit', function () {
    runGit($this->clone, 'tag', 'v1.0.0', 'origin/develop');
    $sha = gitRevision($this->clone, 'origin/develop');

    $baseRefs = baseRefsIn($this->clone);

    expect($baseRefs->resolve('v1.0.0'))->toBe('v1.0.0')
        ->and($baseRefs->resolve($sha))->toBe($sha)
        ->and($baseRefs->resolve('origin/develop'))->toBe('origin/develop');
});

it('still resolves from the clone when every fetch fails', function () {
    // Offline, or a remote that has moved: the fetch is a refresh, never a
    // precondition.
    runGit($this->clone, 'remote', 'set-url', 'origin', $this->root.'/gone');

    expect(baseRefsIn($this->clone)->resolve('develop'))->toBe('origin/develop');
});

it('defaults to the repository default branch rather than to a hardcoded master', function () {
    $baseRefs = baseRefsIn($this->clone);

    expect($baseRefs->defaultBranch())->toBe('master')
        ->and($baseRefs->resolve(null))->toBe('origin/master')
        ->and($baseRefs->resolve(''))->toBe('origin/master');
});

it('falls back to the current branch when the clone records no origin/HEAD', function () {
    runGit($this->clone, 'symbolic-ref', '--delete', 'refs/remotes/origin/HEAD');
    runGit($this->clone, 'checkout', '--quiet', '-b', 'wip');

    expect(baseRefsIn($this->clone)->defaultBranch())->toBe('wip');
});

it('answers the same from inside a worktree as from the main checkout', function () {
    runGit($this->clone, 'worktree', 'add', '--quiet', '-b', 'attached', $this->root.'/attached', 'origin/develop');

    expect(baseRefsIn($this->root.'/attached')->resolve('develop'))->toBe('origin/develop')
        ->and(baseRefsIn($this->root.'/attached')->defaultBranch())->toBe('master');
});
