<?php

namespace DeskHQ\LaravelWorktree\Doctor;

use DeskHQ\LaravelWorktree\Compose\AppService;
use DeskHQ\LaravelWorktree\Compose\ComposeFile;
use DeskHQ\LaravelWorktree\Compose\ComposeVersion;
use DeskHQ\LaravelWorktree\Compose\Overlay;
use DeskHQ\LaravelWorktree\Compose\PublishedPorts;
use DeskHQ\LaravelWorktree\Compose\Services;
use DeskHQ\LaravelWorktree\Config\Configuration;
use DeskHQ\LaravelWorktree\Config\Env;
use DeskHQ\LaravelWorktree\Config\Schema;
use DeskHQ\LaravelWorktree\Console\Manifest;
use DeskHQ\LaravelWorktree\Console\Output;
use DeskHQ\LaravelWorktree\Console\ShutdownHandler;
use DeskHQ\LaravelWorktree\Exceptions\WorktreeException;
use DeskHQ\LaravelWorktree\Git\Anchor;
use DeskHQ\LaravelWorktree\Naming\Identities;
use DeskHQ\LaravelWorktree\Naming\Issues;
use DeskHQ\LaravelWorktree\Process\ProcessRunner;
use DeskHQ\LaravelWorktree\Registry\Allocator;
use DeskHQ\LaravelWorktree\Registry\BindProbe;
use DeskHQ\LaravelWorktree\Registry\DeadEntries;
use DeskHQ\LaravelWorktree\Registry\DeadEntry;
use DeskHQ\LaravelWorktree\Registry\Entry;
use DeskHQ\LaravelWorktree\Registry\Liveness;
use DeskHQ\LaravelWorktree\Registry\Locks;
use DeskHQ\LaravelWorktree\Registry\Ports;
use DeskHQ\LaravelWorktree\Registry\Registry;
use DeskHQ\LaravelWorktree\Runtime\Docker;
use DeskHQ\LaravelWorktree\Runtime\Orphan;
use DeskHQ\LaravelWorktree\Runtime\Orphans;
use DeskHQ\LaravelWorktree\Runtime\SailRuntime;

/**
 * Every pre-flight this package has, run against this repository and this
 * machine, creating nothing.
 *
 * All of these checks already existed, and every one of them fired only as a
 * side effect of `create` — so the way to find out whether a repository was
 * configured correctly was to bootstrap a worktree, and the way to find out
 * whether a machine could run one was to try. That is a slow, side-effecting
 * answer to a question that is pure computation almost all the way down (#57).
 *
 * ## Nothing here writes anything
 *
 * No slot is claimed, no lock is taken, no directory is made, no container is
 * started and no registry is written. The registry, the lock directory and the
 * Compose file are read; the daemon is asked what it has; and the bind probe
 * opens sockets and closes them again. That is the whole of what this touches,
 * and it is what makes the command safe to run on a repository somebody is
 * merely evaluating.
 *
 * ## The checks that need a slot
 *
 * Two of them do — the port block, and the ports a worktree would publish — and
 * `doctor` is not allocating one. So the block is probed on the **next slot a
 * create would take**: the lowest one no registry entry claims, skipping the
 * ones something outside the registry is holding exactly as {@see Allocator}
 * skips them, and saying which slot it landed on. The published-port pre-flight
 * needs no slot at all — it is about whether a mapping moves per worktree, not
 * about which numbers this one would get.
 *
 * ## Three ways a check can decline to pass
 *
 * A create-breaking answer fails, and it is the only thing that makes the run
 * exit non-zero. A true-but-survivable one warns. And a question that could not
 * be put at all — no daemon, a `config/worktree.php` that did not load, a
 * Compose file that would not parse — is {@see Verdict::Unchecked} rather than
 * either: reporting a machine as healthy on the strength of a question nobody
 * could answer is the shape of the-desk#1095, and it is the one mistake a
 * command called `doctor` must not make.
 */
final class Examination
{
    /** What every check that reads the configuration says when there is none. */
    private const string Unconfigured = 'this check reads '.Schema::File.', which did not load; the config check above says why';

    private Services|WorktreeException|null $compose = null;

    private ?Docker $docker = null;

    private ?Registry $registry = null;

    public function __construct(
        private readonly Anchor $anchor,
        /** The repository's settings, or null when `config/worktree.php` did not load. */
        private readonly ?Configuration $config,
        /** Why it did not, in the words the run would otherwise have failed with. */
        private readonly ?string $unreadable,
        private readonly ProcessRunner $runner,
        private readonly Output $output,
        private readonly ShutdownHandler $shutdown,
    ) {}

    /**
     * Every check, in the order a person reads them: what this repository says,
     * then what this machine has.
     */
    public function report(): Report
    {
        $report = new Report($this->anchor->mainRoot);

        foreach ([
            $this->configuration(),
            $this->names(),
            $this->composeVersion(),
            $this->appService(),
            $this->sail(),
            $this->publishedPorts(),
            $this->daemon(),
            $this->issues(),
            $this->slots(),
            $this->portBlock(),
            $this->locks(),
            $this->entries(),
            $this->orphans(),
        ] as $finding) {
            $report->add($finding);
        }

        return $report;
    }

    /**
     * Whether `config/worktree.php` is legal, and what it ended up saying.
     *
     * The failure is {@see Schema}'s own, untouched: it names the offending key,
     * suggests the one it was probably meant to be, and is the best thing this
     * package has to teach somebody what the file may contain. A repository with
     * no file at all is not an error — it runs on the documented defaults, which
     * is what makes `composer require` followed by `worktree create` work with
     * nothing published — so the pass says which of the two happened.
     */
    private function configuration(): Finding
    {
        if ($this->unreadable !== null) {
            return Finding::failed('config', $this->unreadable);
        }

        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('config', 'the configuration was never read');
        }

        $steps = count($config->steps);

        return Finding::passed('config', (is_file($this->anchor->mainRoot.'/'.Schema::File)
            ? Schema::File.' is understood'
            : 'there is no '.Schema::File.' here, so this repository runs on the documented defaults')
            .": $config->slots slots of $config->portStride ports from $config->portBase, publishing "
            .implode(', ', $config->ports).', and '
            .($steps === 0 ? 'no bootstrap step' : $steps.' bootstrap '.($steps === 1 ? 'step' : 'steps')));
    }

    /**
     * What this repository is called, which every other name follows from.
     *
     * `repo_slug` or the checkout's directory name, and the directory name is the
     * one that can fail — a checkout whose name slugifies to nothing has no legal
     * Compose project name to build keys out of, and every command that scopes
     * itself to this repository fails on it ({@see Identities::repoSlug()}).
     */
    private function names(): Finding
    {
        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('names', self::Unconfigured);
        }

        try {
            $slug = $this->repoSlug($config);
        } catch (WorktreeException $unnamed) {
            return Finding::failed('names', $unnamed->getMessage());
        }

        return Finding::passed('names', "this repository is '$slug', so its worktrees are Compose projects named "
            .Identities::Marker.$slug.'-<slug>, in '.dirname($this->anchor->mainRoot).'/'.$slug.Identities::Directory);
    }

    /**
     * Whether this machine's Compose understands the tag the overlay is written
     * with.
     *
     * The one that fails silently rather than loudly: an older Compose merges
     * `depends_on` where `!override` replaces it, so the worktree starts every
     * service the application declares and quietly becomes the thing this package
     * exists to avoid.
     */
    private function composeVersion(): Finding
    {
        $compose = ComposeVersion::for($this->runner);
        $problem = $compose->problem();

        if ($problem !== null) {
            return Finding::failed('compose', $problem);
        }

        $found = $compose->found();

        // A version that cannot be read is not a version that is too old — the
        // subcommand answered, which is the part that would have failed on v1 —
        // but it is not a version that was checked either, and `create` letting
        // it through is not the same as `doctor` calling it healthy.
        if ($compose->version() === null) {
            return Finding::unchecked('compose', "'".Docker::configuredBinary()." compose version --short' answered '$found', "
                .'which does not name a version; '.Overlay::File." is written with the '!override' merge tag, which needs "
                .'Compose >= '.ComposeVersion::Minimum.', and nothing here could tell whether this is it');
        }

        return Finding::passed('compose', "Docker Compose $found, which is at least ".ComposeVersion::Minimum
            ." and so understands the '!override' merge tag ".Overlay::File.' is written with');
    }

    /**
     * Whether the service a create boots is a service this application declares.
     *
     * `bin/sail` reads `${APP_SERVICE:-"laravel.test"}` from the file it sources,
     * and `create` runs `sail up -d` against whatever that resolves to — so a
     * name the Compose file does not carry is a boot that fails minutes in.
     *
     * A declared-but-invisible service is the reason this warns rather than
     * fails: {@see Services} does not follow `include:` or `extends:`, so a
     * service reached only through one of those is real and unseen here, and a
     * refusal over it would be this command telling somebody their working
     * application is broken.
     */
    private function appService(): Finding
    {
        $named = ComposeFile::in($this->anchor->mainRoot);

        if (! is_file($this->anchor->mainRoot.'/'.$named)) {
            return Finding::failed('service', 'this checkout has no Compose file (none of '
                .implode(', ', ComposeFile::Names).'), and a create boots the app service with Compose');
        }

        $compose = $this->compose();

        if ($compose instanceof WorktreeException) {
            return Finding::failed('service', $compose->getMessage());
        }

        $service = AppService::at($this->anchor->mainRoot);

        if ($compose->declares($service)) {
            return Finding::passed('service', "the app service is '$service', which $compose->file declares; "
                ."a create boots the worktree with 'sail up -d $service'");
        }

        return Finding::warned('service', "$compose->file declares no service named '$service', which is what "
            .AppService::Variable." resolves to in this checkout; a create runs 'sail up -d $service', so either that "
            .'name is wrong or the service is reached through an include: or extends:, which is not followed here');
    }

    /**
     * Whether there is a Sail for a worktree to be driven through.
     *
     * A fresh worktree has no `vendor/`, so `create` builds one through a
     * throwaway Composer container purely to obtain `vendor/bin/sail`
     * ({@see SailRuntime}) — which means the question here is not whether this
     * checkout has Sail installed, but whether the `composer.json` a worktree
     * would resolve asks for it. Either answer passes; neither is the failure
     * that arrives three minutes into a bootstrap.
     */
    private function sail(): Finding
    {
        $root = $this->anchor->mainRoot;

        if (is_file($root.'/'.SailRuntime::Binary)) {
            return Finding::passed('sail', 'this checkout has '.SailRuntime::Binary
                .', which is what a worktree is driven through');
        }

        $manifest = $root.'/composer.json';

        if (self::requiresSail($manifest)) {
            return Finding::passed('sail', 'composer.json requires laravel/sail, which a create installs into the '
                .'worktree through a throwaway Composer container before anything else runs');
        }

        return Finding::failed('sail', 'a worktree is driven through Sail, and this checkout has neither '
            .SailRuntime::Binary.' nor a laravel/sail entry in '.(is_file($manifest) ? 'composer.json' : 'a composer.json')
            .'; a create would bootstrap vendor/ and still find no '.SailRuntime::Binary
            .' (composer require laravel/sail --dev)');
    }

    /**
     * The pre-flight from #36: every host port a worktree's services publish is
     * one something offsets per worktree.
     *
     * The refusal is {@see PublishedPorts}' own, in full — every colliding
     * mapping, why the service publishing it is running at all, and the one edit
     * that fixes each. It is the check this command most exists for: it is a
     * property of the *application's* Compose file, so it is broken by people who
     * have never read this package's configuration, which is exactly what a CI
     * run of `worktree doctor` catches on the pull request that broke it.
     */
    private function publishedPorts(): Finding
    {
        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('ports', self::Unconfigured);
        }

        $compose = $this->compose();

        if ($compose instanceof WorktreeException) {
            return Finding::unchecked('ports', 'the Compose file could not be read, so nothing here could say what a '
                .'worktree would publish; the service check above says why');
        }

        $published = new PublishedPorts($compose, AppService::at($this->anchor->mainRoot));
        $problem = $published->problem($config);

        if ($problem !== null) {
            return Finding::failed('ports', $problem);
        }

        $started = array_keys($published->started($config));

        return Finding::passed('ports', count($started).' '.(count($started) === 1 ? 'service' : 'services')
            .' would start in a worktree ('.implode(', ', $started).'), and every host port '
            .(count($started) === 1 ? 'it publishes is' : 'they publish is').' offset per worktree');
    }

    /**
     * Whether there is a daemon to boot anything on.
     *
     * A warning rather than a failure, and the distinction is deliberate: every
     * other failure here is something somebody has to *change* — a config key, a
     * Compose mapping, a `composer.json` — and a closed Docker Desktop is a
     * moment rather than a misconfiguration. Failing on it would also make
     * `worktree doctor` useless in the place the issue names first, which is a CI
     * job checking a `compose.yaml` change on a runner that has no daemon at all.
     */
    private function daemon(): Finding
    {
        $binary = Docker::configuredBinary();

        if ($this->docker()->isRunning()) {
            return Finding::passed('docker', "the Docker daemon is answering ('$binary info')");
        }

        return Finding::warned('docker', "no Docker daemon is answering on this machine ('$binary info' fails), so a "
            .'create could not boot anything here; the checks below that need one were not made, and nothing in this '
            .'report says the machine is clean');
    }

    /**
     * Whether `gh` is here to name an issue, which nothing requires.
     *
     * `create 441` looks the title up so that `git branch` says `441-fix-login`
     * three days later; without `gh` the same run names the worktree `issue-441`
     * and everything else is identical ({@see Issues}). So this warns, and says
     * what the difference actually is.
     *
     * It stays a warning even though `create --pr` cannot run without `gh` at
     * all: that flag is one way into one command, and a report that failed over
     * it would be calling a machine unfit for a package it can run every other
     * part of. The consequence is named rather than graded.
     */
    private function issues(): Finding
    {
        if (self::onPath('gh')) {
            return Finding::passed('gh', "gh is on this PATH, so 'worktree create 441' names the worktree from the "
                ."issue's title, and 'worktree create --pr 441' has something to ask for a pull request's head "
                .'branch — when it is logged in and online, which only the lookup itself can say');
        }

        return Finding::warned('gh', "gh is not on this PATH, so 'worktree create 441' names the worktree issue-441 "
            ."rather than 441-fix-login; the only thing here that cannot work without it is 'worktree create --pr'");
    }

    /**
     * How much of this machine's slot range is spoken for.
     *
     * Machine-wide, because slots are: host ports are a machine-scoped resource,
     * so a second clone of this repository draws from the same registry, and a
     * count scoped to this checkout would answer a question nobody asked.
     */
    private function slots(): Finding
    {
        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('slots', self::Unconfigured);
        }

        $entries = $this->entriesOf($config);

        if ($entries instanceof WorktreeException) {
            return Finding::failed('slots', $entries->getMessage());
        }

        $claimed = count($entries);
        $free = $config->slots - $claimed;

        if ($free > 0) {
            return Finding::passed('slots', "$claimed of $config->slots slots are claimed on this machine, so "
                .$free.($free === 1 ? ' is' : ' are').' free');
        }

        $dead = count(array_filter($entries, DeadEntries::isDead(...)));

        return Finding::failed('slots', "all $config->slots slots are in use, so the next "
            ."'worktree create' of a worktree that does not have one has nowhere to put it; "
            .($dead === 0
                ? "free one with 'worktree remove <slug>', or raise 'slots'"
                : $dead.' of them '.($dead === 1 ? 'is held by a registry entry whose' : 'are held by registry entries whose')
                  ." worktree directory is gone, which 'worktree reap --all' reclaims"));
    }

    /**
     * Whether the next slot a create would take has a free block of host ports.
     *
     * The allocator's own search without the claim: the lowest slot no entry
     * holds, skipped when {@see BindProbe} finds something already listening on
     * one of its ports, which is the case no amount of bookkeeping fixes — a
     * stray Postgres, a crashed container still holding a binding, somebody
     * else's dev server. A skip is a warning because that is what it costs: the
     * allocator moves on and takes the next slot. Skips all the way to the end of
     * the range are a failure, in the words the allocator refuses with.
     *
     * **This is TOCTOU and is documented as such**, exactly as the probe itself
     * is: the answer is a strong hint about this instant, not a reservation.
     */
    private function portBlock(): Finding
    {
        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('block', self::Unconfigured);
        }

        $entries = $this->entriesOf($config);

        if ($entries instanceof WorktreeException) {
            return Finding::unchecked('block', 'the registry could not be read, so the slots it holds are not known; '
                .'the slot check above says why');
        }

        $claimed = array_map(fn (Entry $entry): int => $entry->slot, array_values($entries));
        $ports = Ports::fromConfiguration($config);
        $probe = new BindProbe;
        $skipped = [];

        for ($slot = 0; $slot < $config->slots; $slot++) {
            if (in_array($slot, $claimed, true)) {
                continue;
            }

            $block = $ports->forSlot($slot);
            $taken = $probe->firstTaken($block);

            if ($taken === null) {
                $free = "slot $slot is the next one a create would take, and its ports ("
                    .self::describe($block).') are free';

                return $skipped === []
                    ? Finding::passed('block', $free)
                    : Finding::warned('block', implode("\n", $skipped)."\n".$free);
            }

            [$name, $port] = $taken;

            $skipped[] = "slot $slot is free in the registry, but port $port ($name) of its block is already in use by "
                .'something the registry does not know about, so a create would skip it';
        }

        if ($skipped === []) {
            return Finding::unchecked('block', 'every slot on this machine is claimed, so there is no next port block '
                .'to probe; the slot check above says what that means');
        }

        return Finding::failed('block', 'no free slot has a free port block ('.count($skipped).' of '
            .$config->slots.' slots skipped by the bind probe); stop whatever is holding those ports, or move '
            ."port_base:\n".implode("\n", $skipped));
    }

    /**
     * The locks this machine is holding, and whoever is behind each of them.
     *
     * A lock whose holder is provably gone is broken and taken by the next run
     * that wants it (#51), so this is a warning rather than a failure — but it is
     * worth saying, because a lock nobody can prove anything about is one every
     * run waits ten minutes for, and `worktree unlock` is the way out that people
     * do not find until they need it.
     */
    private function locks(): Finding
    {
        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('locks', self::Unconfigured);
        }

        $locks = new Locks($config->home, $this->shutdown, $this->output);
        $taken = $locks->taken();

        if ($taken === []) {
            return Finding::passed('locks', 'no lock is held on this machine');
        }

        $unsettled = [];
        $running = [];

        foreach ($taken as $lock) {
            $owner = $lock->owner();

            if ($owner === null) {
                $unsettled[$lock->path()] = 'records no holder, so nothing here can tell whether one is still running';

                continue;
            }

            $liveness = $owner->liveness($locks->machine());
            $held = 'taken by '.$owner->describe();

            if ($liveness === Liveness::Alive) {
                $running[$lock->path()] = $held.', which is still running';

                continue;
            }

            $unsettled[$lock->path()] = $held.($liveness === Liveness::Gone
                ? ', which is not running any more'
                : ', on another machine — nothing here can tell whether it is still running');
        }

        if ($unsettled === []) {
            return Finding::passed('locks', count($running).' '.(count($running) === 1 ? 'lock is' : 'locks are')
                ." held by a run that is still going, which is what a lock is for:\n"
                .implode("\n", Manifest::lines($running)));
        }

        return Finding::warned('locks', count($unsettled).' of '.count($taken).' '
            .(count($taken) === 1 ? 'lock' : 'locks').' on this machine '
            .(count($unsettled) === 1 ? 'is held by nothing this run could find' : 'are held by nothing this run could find')
            .":\n".implode("\n", Manifest::lines($unsettled))
            ."\na run that can prove the holder is gone breaks the lock and takes it; "
            ."'worktree unlock --all' removes them now, and refuses the ones whose holder is still running");
    }

    /**
     * The registry entries with nothing behind them (#53).
     *
     * Machine-wide for the reason the slot count is: they hold slots, and slots
     * are machine-wide. The lines are {@see DeadEntry}'s own, so what this report
     * names and what a `reap` asks permission to reclaim read identically.
     */
    private function entries(): Finding
    {
        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('entries', self::Unconfigured);
        }

        try {
            $dead = DeadEntries::for($this->registryOf($config))->of(null);
        } catch (WorktreeException) {
            return Finding::unchecked('entries', 'the registry could not be read; the slot check above says why');
        }

        if ($dead === []) {
            return Finding::passed('entries', 'every registry entry on this machine has a worktree directory behind it');
        }

        $here = array_filter($dead, fn (DeadEntry $entry): bool => $entry->isReclaimableFrom($this->anchor->mainRoot));

        return Finding::warned('entries', count($dead).' registry '.(count($dead) === 1 ? 'entry holds a slot' : 'entries hold slots')
            .' whose worktree '.(count($dead) === 1 ? 'directory is' : 'directories are')." gone:\n"
            .implode("\n", DeadEntry::manifest($dead))
            ."\n'worktree reap".(count($here) === count($dead) ? '' : ' --all')."' reclaims "
            .(count($dead) === 1 ? 'it' : 'them'));
    }

    /**
     * The projects on this daemon that no worktree claims.
     *
     * The scan `list` warns by and `reap` destroys by, scoped to this repository
     * exactly as `list`'s warning is — and silent about nothing: with no daemon
     * answering this is {@see Verdict::Unchecked}, because an empty scan and a
     * scan that never happened are different answers and collapsing them is the
     * shape of the-desk#1095.
     */
    private function orphans(): Finding
    {
        $config = $this->config;

        if ($config === null) {
            return Finding::unchecked('orphans', self::Unconfigured);
        }

        if (! $this->docker()->isRunning()) {
            return Finding::unchecked('orphans', 'there is no Docker daemon answering on this machine, so nothing '
                .'could be scanned for; nothing here says the disk is clean');
        }

        try {
            $slug = $this->repoSlug($config);
            $orphans = (new Orphans($this->docker(), $this->registryOf($config)))->under(Orphans::prefix($slug));
        } catch (WorktreeException $unscannable) {
            return Finding::unchecked('orphans', 'the scan could not be scoped to this repository: '.$unscannable->getMessage());
        }

        if ($orphans === []) {
            return Finding::passed('orphans', "no project of $slug is on this daemon that no worktree claims");
        }

        return Finding::warned('orphans', count($orphans).' '.(count($orphans) === 1 ? 'project' : 'projects')
            ." of $slug ".(count($orphans) === 1 ? 'is' : 'are')." still on this daemon that no worktree claims:\n"
            .implode("\n", Orphan::manifest($orphans))
            ."\n'worktree reap' removes ".(count($orphans) === 1 ? 'it' : 'them'));
    }

    /**
     * Whether $binary is an executable file on this run's `PATH`.
     *
     * Looked up rather than run, and the difference matters here more than
     * anywhere else in the package: `gh --version` answers the same question and
     * writes into `gh`'s own state directory in `$HOME` to do it, and a command
     * whose whole claim is that it creates nothing does not get to leave a file
     * behind to find out how a worktree would be named.
     */
    private static function onPath(string $binary): bool
    {
        $path = Env::get('PATH');

        if (! is_string($path) || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory !== '' && is_executable(rtrim($directory, '/').'/'.$binary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A block of host ports as its range, which is what it always is: the ports
     * of one slot are consecutive by construction ({@see Ports::forSlot()}).
     *
     * @param  array<string, int>  $block
     */
    private static function describe(array $block): string
    {
        $ports = array_values($block);

        return count($ports) === 1 ? (string) $ports[0] : $ports[0].'-'.$ports[count($ports) - 1];
    }

    /**
     * Whether the `composer.json` at $path asks for Sail, in either section.
     *
     * Unreadable and unparseable are the same answer as absent: this is deciding
     * whether to say that a create would find no Sail, and it may only say that
     * on evidence.
     */
    private static function requiresSail(string $path): bool
    {
        $manifest = json_decode((string) @file_get_contents($path), true);

        if (! is_array($manifest)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $section) {
            $packages = $manifest[$section] ?? null;

            if (is_array($packages) && array_key_exists('laravel/sail', $packages)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What names this repository, asked once however many checks want it.
     *
     * @throws WorktreeException when the checkout's directory name slugifies to nothing.
     */
    private function repoSlug(Configuration $config): string
    {
        return Identities::fromConfiguration($config, $this->anchor, $this->runner, $this->output)->repoSlug();
    }

    /**
     * The registry entries, or the reason there are none to be had.
     *
     * A corrupt registry is a create-breaking failure rather than an exception
     * that ends the report — which is the whole point of this command, and the
     * reason every read here hands its refusal back instead of throwing it.
     *
     * @return array<string, Entry>|WorktreeException
     */
    private function entriesOf(Configuration $config): array|WorktreeException
    {
        try {
            return $this->registryOf($config)->all();
        } catch (WorktreeException $unreadable) {
            return $unreadable;
        }
    }

    private function registryOf(Configuration $config): Registry
    {
        return $this->registry ??= Registry::fromConfiguration($config);
    }

    /**
     * The application's Compose file, read once: the service check and the
     * published-port check are both about it, and a file parsed twice is a file
     * that can be reported on twice in two different ways.
     */
    private function compose(): Services|WorktreeException
    {
        if ($this->compose !== null) {
            return $this->compose;
        }

        try {
            return $this->compose = Services::in($this->anchor->mainRoot);
        } catch (WorktreeException $unreadable) {
            return $this->compose = $unreadable;
        }
    }

    /**
     * One Docker for the run, so that "is there a daemon?" is asked once and the
     * two checks that turn on it cannot disagree.
     */
    private function docker(): Docker
    {
        return $this->docker ??= Docker::for($this->runner, $this->output);
    }
}
