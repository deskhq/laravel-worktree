<?php

namespace DeskHQ\LaravelWorktree\Registry;

use DeskHQ\LaravelWorktree\Config\Configuration;

/**
 * The block of host ports a slot owns, derived rather than invented.
 *
 * A port is `port_base + slot * port_stride + the port's index in ports`, so
 * the whole block follows from the slot integer and the configuration — which
 * is what lets a registry entry be repaired instead of rejected. The bash
 * original made exactly this trade for one port: it derived redis as
 * `db_port + 1` at use time specifically so that entries written before redis
 * existed stayed readable on resume. The config-driven list supersedes the
 * arithmetic, but the reason survives it, so {@see complete()} fills in what an
 * entry does not name rather than failing the run.
 *
 * What an entry *does* name always wins over what the slot derives: those are
 * the ports the worktree's containers were actually published on, and a
 * `port_base` that has changed since must not silently re-point a running
 * worktree at ports nothing is listening on.
 */
final readonly class Ports
{
    private function __construct(
        /** The first host port of slot 0. */
        public int $base,
        /** How many ports each slot reserves. */
        public int $stride,
        /**
         * The port names, in offset order.
         *
         * @var list<string>
         */
        public array $names,
    ) {}

    public static function fromConfiguration(Configuration $config): self
    {
        return new self($config->portBase, $config->portStride, $config->ports);
    }

    /**
     * The whole block belonging to $slot.
     *
     * @return array<string, int>
     */
    public function forSlot(int $slot): array
    {
        $block = [];

        foreach ($this->names as $index => $name) {
            $block[$name] = $this->base + $slot * $this->stride + $index;
        }

        return $block;
    }

    /**
     * The block for $slot as the registry recorded it, with anything the
     * current configuration declares and the entry does not derived.
     *
     * A name the entry carries but the configuration no longer declares is
     * dropped: it belongs to no offset, so nothing can be published on it.
     *
     * @param  array<mixed>  $recorded  The entry's `ports`, as it was read from disk.
     * @return array<string, int>
     */
    public function complete(int $slot, array $recorded): array
    {
        $block = [];

        foreach ($this->forSlot($slot) as $name => $derived) {
            $port = $recorded[$name] ?? null;

            $block[$name] = is_int($port) && $port > 0 ? $port : $derived;
        }

        return $block;
    }
}
