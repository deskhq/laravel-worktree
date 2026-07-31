<?php

namespace DeskHQ\LaravelWorktree\Registry;

/**
 * A pre-flight check that the ports a slot owns are actually free.
 *
 * Fifteen lines that convert `Bind for :::20002 failed: port is already
 * allocated`, four minutes into a bootstrap and after the git worktree, the
 * `.env` and half a `composer install` already exist, into a clear message
 * before any work starts.
 *
 * It also catches port users the registry cannot possibly know about — a stray
 * Postgres, a crashed container still holding a binding, a colleague's dev
 * server — which is the case no amount of bookkeeping fixes.
 *
 * **This is TOCTOU, and is documented as such rather than pretended away.**
 * Compose binds these ports minutes later, and anything could take one in
 * between. The probe is a strong pre-flight hint, not a guarantee; the
 * guarantee against *this tool's own* worktrees is the registry, under lock.
 */
final readonly class BindProbe
{
    /**
     * The first port of $ports that something is already listening on.
     *
     * Binds the wildcard address rather than loopback because that is what
     * Docker publishes on: a port held on `0.0.0.0` is a port Compose cannot
     * take, even when `127.0.0.1` looks free.
     *
     * @param  array<string, int>  $ports
     * @return array{string, int}|null The port's name and number, or null when the block is free.
     */
    public function firstTaken(array $ports): ?array
    {
        foreach ($ports as $name => $port) {
            $socket = @stream_socket_server(
                "tcp://0.0.0.0:$port",
                $code,
                $message,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );

            if ($socket === false) {
                return [$name, $port];
            }

            fclose($socket);
        }

        return null;
    }
}
