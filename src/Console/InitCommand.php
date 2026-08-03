<?php

namespace DeskHQ\LaravelWorktree\Console;

use DeskHQ\LaravelWorktree\Compose\PublishedPorts;
use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\Derivation;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Config\Stencil;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;

/**
 * Write `config/worktree.php` from the repository's own `compose.yaml` (#58).
 *
 * ```
 * $ worktree init
 * worktree init — /Users/agent/the-desk
 *
 *   compose.yaml     laravel.test, pgsql, redis, mailpit  (4 services started)
 *   ports            app, vite, db, redis, mailpit, mailpit_8025  (port_stride 10)
 *   env              APP_PORT, VITE_PORT, FORWARD_DB_PORT, FORWARD_REDIS_PORT, …
 *   keep_services    pgsql, redis, mailpit
 *   port_overrides   mailpit
 *   steps            none — the bootstrap recipe is the one part nothing can derive
 *
 * config/worktree.php written; 'worktree doctor' checks it against this machine
 * ```
 *
 * This is the package's steepest adoption step, and it was a page of careful
 * reading. Everything else is `composer require` and a path in a `cd`; this was
 * a derivation performed by hand — against a file the package can read, using a
 * rule the package already implements ({@see Derivation}) — where a mistake
 * surfaces minutes into a bootstrap on somebody's second worktree.
 *
 * It also closes a loop. The pre-flight made the package refuse a wrong config,
 * and refusing is half of *the package owns its own correctness*; generating the
 * right one is the other half, out of the same walk over the same file.
 *
 * ## It checks what it wrote
 *
 * Before anything is written, the derived values are put through
 * {@see Configuration::fromArray()} and then through the pre-flight they came
 * from ({@see PublishedPorts::problem()}). A generated file that would not
 * survive the package's own `create` is a bug in this command, and it says so in
 * those words rather than leaving somebody to discover it as a refusal against a
 * file they were told to trust.
 *
 * ## What it will not do
 *
 * It refuses to overwrite an existing `config/worktree.php` without `--force`,
 * because that file usually carries a `steps` recipe and nothing derived can
 * reproduce one. `--dry-run` prints the file instead of writing it, which is the
 * one to reach for on every run after the first: the two can be diffed, and the
 * parts worth keeping moved across by hand.
 *
 * Like `doctor`, it runs against a repository whose configuration will not load
 * at all ({@see Diagnostic}) — a broken `config/worktree.php` is a reason to
 * generate one, not a reason to refuse.
 */
final readonly class InitCommand implements Diagnostic
{
    /** Replace a `config/worktree.php` that is already there. */
    public const string Force = 'force';

    /** Print what would be written, and write nothing. */
    public const string DryRun = 'dry-run';

    public function __construct(
        private Output $output,
        private Emitter $emitter,
    ) {}

    public function name(): string
    {
        return 'init';
    }

    public function usage(): array
    {
        return [
            '[--dry-run] [--force]',
            'Write config/worktree.php from this repository\'s compose.yaml.',
        ];
    }

    public function run(array $arguments, Anchor $anchor, Configuration $config): int
    {
        return $this->generate($arguments, $anchor);
    }

    /**
     * The same run against a repository whose configuration would not load,
     * which is one of the two states this command exists to end.
     */
    public function diagnose(array $arguments, Anchor $anchor, WorktreeException $unreadable): int
    {
        $this->output->line('note: '.$unreadable->getMessage());
        $this->output->line();

        return $this->generate($arguments, $anchor);
    }

    private function generate(array $arguments, Anchor $anchor): int
    {
        $invocation = Arguments::parse($arguments, [self::Force, self::DryRun], takes: Arity::options($this->name()));

        $path = $anchor->mainRoot.'/'.Schema::File;
        $dryRun = $invocation->has(self::DryRun);
        $existed = is_file($path);

        // Refused before anything is derived, so that a run which cannot write
        // says only that — a summary of a file nobody is getting is noise in
        // front of the one sentence that matters.
        if ($existed && ! $dryRun && ! $invocation->has(self::Force)) {
            throw new WorktreeException(
                Schema::File." is already there, and this would replace all of it — including any 'steps' recipe "
                .'it carries, which nothing derived can reproduce: '
                ."'worktree init --dry-run' prints what this run would have written, to diff against it, "
                ."and 'worktree init --force' replaces it"
            );
        }

        $derivation = Derivation::at($anchor->mainRoot);

        $this->verify($derivation, $anchor->mainRoot);
        $this->summarise($derivation, $anchor);

        $generated = Stencil::render($derivation);

        if ($dryRun) {
            $this->emitter->emit(rtrim($generated, "\n"));
            $this->output->line();
            $this->output->line('nothing was written; this is what '.Schema::File.' would have been');

            return ExitCode::Success;
        }

        $this->write($path, $generated);

        // The path, on stdout, where every other answer a script reads goes:
        // `git diff "$(worktree init --force)"` is the run after this one.
        $this->emitter->emit($path);

        $this->output->line();
        $this->output->line(Schema::File.($existed ? ' replaced' : ' written')
            ."; 'worktree doctor' checks it against this machine, and 'worktree create' is what it is for");

        return ExitCode::Success;
    }

    /**
     * @throws WorktreeException when the file, or the directory it goes in, cannot be written.
     */
    private function write(string $path, string $generated): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new WorktreeException("could not create $directory to write ".Schema::File.' into');
        }

        if (@file_put_contents($path, $generated) === false) {
            throw new WorktreeException("could not write $path");
        }
    }

    /**
     * The pre-flight, run against what is about to be written rather than
     * against what somebody wrote.
     *
     * @throws WorktreeException when the generated configuration would not survive a create.
     */
    private function verify(Derivation $derivation, string $mainRoot): void
    {
        // Entered exactly where a create enters it, rather than assembled out of
        // the pieces this command already has: the check that runs here is the
        // one that will run there, or it is not a check.
        $problem = PublishedPorts::of($mainRoot)->problem(Configuration::fromArray($derivation->toArray()));

        if ($problem === null) {
            return;
        }

        throw new WorktreeException(
            "the configuration derived from $derivation->composeFile does not pass this package's own published-port "
            ."pre-flight, so it has not been written. That is a bug in 'worktree init' rather than anything about this "
            ."repository — please report it with the Compose file that produced it:\n\n".$problem
        );
    }

    /**
     * What was derived, in the terms the file names them — so that the summary
     * and the file can be read against each other.
     */
    private function summarise(Derivation $derivation, Anchor $anchor): void
    {
        $started = array_keys($derivation->started);
        $declared = count($derivation->started);

        $this->output->line('worktree init — '.$anchor->mainRoot);
        $this->output->line();

        $this->row($derivation->composeFile, implode(', ', $started)
            .'  ('.$declared.' '.($declared === 1 ? 'service' : 'services').' started)');
        $this->row('ports', implode(', ', $derivation->ports).'  (port_stride '.$derivation->portStride.')');
        $this->row('env', implode(', ', array_keys($derivation->env)));
        $this->row('keep_services', $derivation->keepServices === []
            ? 'none — the app service depends on nothing, so nothing is trimmed'
            : implode(', ', $derivation->keepServices));
        $this->row('port_overrides', $derivation->portOverrides === []
            ? 'none — every published port here can be offset in .env'
            : implode(', ', array_keys($derivation->portOverrides)));
        $this->row('steps', 'none — the bootstrap recipe is the one part nothing can derive');

        $this->output->line();
    }

    private function row(string $label, string $value): void
    {
        $this->output->line('  '.str_pad($label, 16).$value);
    }
}
