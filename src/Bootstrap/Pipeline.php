<?php

namespace DeskHQ\LaravelWorktree\Bootstrap;

use DeskHQ\LaravelWorktree\Config\Placeholders;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Exceptions\TimedOutException;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Excludes;
use DeskHQ\LaravelWorktree\Naming\Identity;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;

/**
 * The bootstrap recipe, run in order (D4).
 *
 * This is the mechanism that keeps one application's recipe out of this
 * package. `composer install`, `artisan migrate`, `npm run build`, the
 * generated TypeScript, the browser binaries — all of it is `steps` in
 * `config/worktree.php`, and none of it is a class in here.
 *
 * ## Where the DSL stops
 *
 * `host` / `sail` / `sail_root`, plus `sentinel`, `when`, `allow_failure`,
 * `degrade` and `timeout`. That covers ten of the twelve steps the bash
 * original ran. The other two are left out on purpose:
 *
 * - **Finally-semantics.** The original brackets an apt install with parking
 *   and restoring the container's third-party sources, and the restore has to
 *   run even when the install failed, or the container is left with an apt
 *   config that breaks every later install. `allow_failure` means "ignore this
 *   failure", not "run this regardless".
 * - **Probe-gating.** It decides whether to install Chromium by running a probe
 *   in the container and reading its exit code, because where the browsers land
 *   depends on `PLAYWRIGHT_BROWSERS_PATH`. No `sentinel` can ask that.
 *
 * Both are genuinely exotic, and covering them would mean adding `always:` and
 * `when: shell_fails:<cmd>` — inventing a worse programming language, then
 * documenting and testing it. So the escape hatch is a script the application
 * owns, invoked as one ordinary step:
 *
 * ```php
 * ['host' => 'bin/worktree-playwright {{path}}',
 *  'allow_failure' => true,
 *  'degrade' => 'Playwright is not fully installed; tests/Browser will not run here'],
 * ```
 *
 * ## Resolve everything, then run anything
 *
 * Placeholders are resolved for the whole recipe before the first step starts,
 * so a typo in step twelve is an error now rather than after eleven minutes of
 * Composer, npm and image pulls.
 *
 * ## A step that never returns is a step that failed
 *
 * Every step carries a `timeout` — its own, or the package's `step_timeout`
 * default — and one that runs past it is killed and reported like any other
 * failure. That composition is the whole design: `allow_failure` and `degrade`
 * mean for a timeout exactly what they mean for a non-zero exit, and a timed
 * out step without `allow_failure` stops the bootstrap where it stands and
 * leaves the worktree resumable.
 *
 * It matters more here than the ordinary hang argument suggests, because the
 * per-worktree lock is held for the whole run: an `npm ci` against a registry
 * that accepts the connection and then stalls does not just hang its own run,
 * it blocks every later entry into that worktree behind it — and an agent
 * shelling out to `worktree create` has nothing to tell a wedged step from a
 * slow one.
 */
final readonly class Pipeline
{
    public function __construct(
        private Output $output,
        private Shell $shell,
        private Excludes $excludes,
    ) {}

    /**
     * The pipeline as the binary wires it: real git, and the shell the runtime
     * that just booted this worktree hands over — which is where a `sail:` step
     * picks up whatever Sail has to be told before it will behave.
     */
    public static function using(Output $output, ProcessRunner $runner, Shell $shell): self
    {
        return new self($output, $shell, new Excludes($runner, $output));
    }

    /**
     * Run the recipe against the worktree at $identity->path.
     *
     * @param  array<string, int>  $ports  The host ports its slot owns.
     * @param  list<array<string, string|bool|int|null>>  $steps  `steps`, as configured.
     * @param  string  $environmentFile  The worktree's `.env`, which `env_empty` reads.
     * @param  list<string>|null  $only  Retry just the steps recorded under these names; null runs the whole recipe.
     *
     * @throws WorktreeException when a step fails and was not allowed to, or the recipe cannot be resolved.
     */
    public function run(Identity $identity, array $ports, array $steps, string $environmentFile, ?array $only = null): Outcome
    {
        if (! is_dir($identity->path)) {
            throw new WorktreeException("cannot run the bootstrap: there is no worktree at $identity->path yet");
        }

        $recipe = $this->recipe($identity, $ports, $steps, $only);

        if ($recipe === []) {
            return new Outcome;
        }

        $total = count($recipe);
        $degraded = [];

        foreach ($recipe as $index => $step) {
            $position = '['.($index + 1)."/$total] ";
            $skipped = $this->skipped($step, $identity->path, $environmentFile);

            if ($skipped !== null) {
                $this->output->line($position.$step->name().' — skipped, '.$skipped);

                continue;
            }

            $this->output->line($position.$step->name());

            $failure = $this->attempt($step, $identity->path);

            if ($failure === null) {
                $this->markDone($step, $identity->path);

                continue;
            }

            if (! $step->allowFailure) {
                throw new WorktreeException(
                    $step->name()." $failure; the bootstrap stopped there — fix it, then "
                    ."'worktree create $identity->name' picks up where it left off"
                );
            }

            $this->output->line("warning: {$step->name()} $failure, and this step is allowed to fail");

            $degraded[] = ['name' => $step->name(), 'notice' => $step->degrade];
        }

        return new Outcome($degraded);
    }

    /**
     * Run one step, and say how it went wrong — or null when it did not.
     *
     * Two ways to fail, one sentence each, and they are deliberately different
     * sentences: `failed (exit 137)` on a step whose container was killed and
     * `timed out after 900s` on a step that never returned are the same
     * *outcome* and completely different problems, and a run that reported the
     * second as the first would send somebody looking through a log that
     * stops mid-sentence for an exit code nothing produced.
     */
    private function attempt(Step $step, string $path): ?string
    {
        try {
            $exitCode = $this->shell->run($step->commandLine(), $path, $step->timeout);
        } catch (TimedOutException $timedOut) {
            return "timed out after {$timedOut->seconds}s";
        }

        return $exitCode === 0 ? null : "failed (exit $exitCode)";
    }

    /**
     * The steps this run will consider, resolved, and narrowed to a retry list.
     *
     * @param  array<string, int>  $ports
     * @param  list<array<string, string|bool|int|null>>  $steps
     * @param  list<string>|null  $only
     * @return list<Step>
     */
    private function recipe(Identity $identity, array $ports, array $steps, ?array $only): array
    {
        $placeholders = Placeholders::for($identity, $ports);
        $recipe = [];

        foreach ($steps as $index => $step) {
            $recipe[] = Step::resolve($step, 'steps.'.$index, $placeholders);
        }

        if ($only === null) {
            return $recipe;
        }

        $retried = array_values(array_filter($recipe, fn (Step $step): bool => in_array($step->name(), $only, true)));

        // A step recorded as degraded that the recipe no longer has is not an
        // error — the configuration is allowed to change between runs — but it
        // is worth saying, because the alternative is a worktree that quietly
        // never regains whatever that step was for.
        foreach (array_diff($only, array_map(fn (Step $step): string => $step->name(), $retried)) as $name) {
            $this->output->line("'$name' degraded on an earlier run, but ".Schema::File.' no longer has a step by that name');
        }

        if ($retried !== []) {
            $this->output->line('retrying '.count($retried).' step'.(count($retried) === 1 ? '' : 's').' that degraded on an earlier run');
        }

        return $retried;
    }

    /**
     * Why this step is not being run, or null if it is.
     *
     * The sentinel is asked first: it is the cheaper question, and it is the one
     * that means "already done" rather than "not needed right now".
     */
    private function skipped(Step $step, string $path, string $environmentFile): ?string
    {
        $sentinel = $step->sentinelPath($path);

        if ($sentinel !== null && is_file($sentinel)) {
            return $step->sentinel.' is already there';
        }

        if ($step->when !== null && ! $step->when->holds($path, $environmentFile)) {
            return $step->when->unmet();
        }

        return null;
    }

    /**
     * Touch the sentinel, and keep it out of `git status`.
     *
     * Only on success, because a sentinel written after a failed step is a step
     * that never runs again. Failing to write one is not fatal: the cost is that
     * the step runs again on the next re-entry, which is what it would have done
     * anyway without a sentinel.
     */
    private function markDone(Step $step, string $path): void
    {
        $sentinel = $step->sentinelPath($path);

        if ($sentinel === null) {
            return;
        }

        if (@touch($sentinel) === false) {
            $this->output->line("warning: could not write $sentinel; {$step->name()} will run again on the next re-entry");

            return;
        }

        if ($step->sentinel !== null && ! str_starts_with($step->sentinel, '/')) {
            $this->excludes->ensure($path, $step->sentinel);
        }
    }
}
