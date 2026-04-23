<?php

declare(strict_types=1);

namespace SuperAgent\Harness\Wire;

/**
 * Resolve a DSN into a writable resource {@see WireStreamOutput} can
 * consume. The 0.9.0 MVP only supported STDOUT via `--output json-stream`;
 * this class extends the transport layer to file, TCP, and unix-socket
 * sinks without changing the `JsonStreamRenderer` serialisation code path.
 *
 * ## Supported DSN shapes
 *
 * **Client mode** — caller opens a pre-existing sink and pipes events
 * into it. Suitable for log-file capture, IDE harnesses that run an
 * `accept()` server and want the agent to `connect()` to them, or a
 * parent process that listens on a socket.
 *
 *   - `stdout`                       STDOUT (default / backwards-compatible)
 *   - `stderr`                       STDERR (useful for piping alongside regular output)
 *   - `file:///path/to/log.ndjson`   Append-mode file write (flushed per-event)
 *   - `tcp://host:port`              Connect to a listening TCP peer
 *   - `unix:///path/to/sock.sock`    Connect to a listening unix-domain socket
 *
 * **Server mode** — SDK opens a listening socket and blocks once to
 * accept a single client. Suitable for IDE integrations where the
 * editor plugin attaches after the agent starts.
 *
 *   - `listen://tcp/host:port`       Listen on TCP, accept one client
 *   - `listen://unix//path/sock.sock` Listen on unix-socket, accept one client
 *
 * The renderer layer (`JsonStreamRenderer::emit`) is byte-identical
 * regardless of sink — same NDJSON, same `wire_version: 1` contract.
 *
 * Client-disconnect handling: once the accepted connection drops, the
 * renderer keeps succeeding (fwrite returns 0, which WireStreamOutput
 * already tolerates). The agent does not pause on disconnect — a
 * lost IDE shouldn't brick the loop.
 *
 * ## Non-goals
 *
 *   - No reconnect / multiplexing. One connection per run; once the
 *     peer drops, the renderer becomes a no-op.
 *   - No ACP protocol framing. Each line is still a plain WireEvent
 *     dict. Adding request/response framing is a layer on top and
 *     would live in a separate class.
 *   - No TLS. TCP is plain-text; run it over localhost or tunnel
 *     through ssh if the network isn't trusted.
 */
final class WireTransport
{
    /**
     * Result of {@see self::open()} — bundles the writable resource
     * and an optional server handle that the caller must close when
     * the stream ends. For client DSNs the server handle is null.
     *
     * @param resource      $stream  Passed to WireStreamOutput.
     * @param resource|null $server  Non-null only for listen:// DSNs;
     *                               calling close() shuts it down.
     * @param string        $dsn     Original DSN, for diagnostics.
     */
    public function __construct(
        /** @var resource */
        public readonly mixed $stream,
        /** @var resource|null */
        public readonly mixed $server,
        public readonly string $dsn,
    ) {}

    /**
     * Resolve the DSN, opening (and for `listen://` variants,
     * listening + accepting) as needed.
     *
     * @param  array{connect_timeout?:float, accept_timeout?:float} $options
     *         connect_timeout: seconds to wait for TCP/unix connect (default 5)
     *         accept_timeout:  seconds to wait for a listen peer (default 30)
     * @throws \RuntimeException if the DSN is unsupported or the open fails.
     */
    public static function open(string $dsn, array $options = []): self
    {
        $dsn = trim($dsn);
        if ($dsn === '' || $dsn === 'stdout') {
            $stream = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
            return new self($stream, null, 'stdout');
        }
        if ($dsn === 'stderr') {
            $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
            return new self($stream, null, 'stderr');
        }

        if (str_starts_with($dsn, 'file://')) {
            $path = substr($dsn, 7);
            if ($path === '') {
                throw new \RuntimeException("WireTransport: missing path in DSN '{$dsn}'");
            }
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $h = @fopen($path, 'ab');
            if ($h === false) {
                throw new \RuntimeException("WireTransport: cannot open {$path} for append");
            }
            return new self($h, null, $dsn);
        }

        if (str_starts_with($dsn, 'tcp://') || str_starts_with($dsn, 'unix://')) {
            return self::openClient($dsn, (float) ($options['connect_timeout'] ?? 5.0));
        }

        if (str_starts_with($dsn, 'listen://')) {
            return self::openServer($dsn, (float) ($options['accept_timeout'] ?? 30.0));
        }

        throw new \RuntimeException("WireTransport: unsupported DSN '{$dsn}'");
    }

    /**
     * Close the server handle (if any). The caller's `$stream` is
     * owned by them — we never close it, consistent with
     * {@see WireStreamOutput}'s no-close contract on its input stream.
     */
    public function close(): void
    {
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    private static function openClient(string $dsn, float $timeout): self
    {
        // Map our DSN shape (`tcp://host:port`, `unix:///path`) onto
        // stream_socket_client's expected form. It already speaks both
        // `tcp://` and `unix://`; the difference is the trailing slash
        // rules for unix paths, which we preserve verbatim.
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($dsn, $errno, $errstr, $timeout);
        if ($sock === false) {
            throw new \RuntimeException("WireTransport: connect to {$dsn} failed ({$errno}): {$errstr}");
        }
        // Non-blocking so the agent loop never stalls on a slow IDE peer.
        @stream_set_blocking($sock, false);
        return new self($sock, null, $dsn);
    }

    private static function openServer(string $dsn, float $acceptTimeout): self
    {
        [$scheme, $addr] = self::parseListenDsn($dsn);

        $serverDsn = match ($scheme) {
            'tcp'  => "tcp://{$addr}",
            'unix' => "unix://{$addr}",
            default => throw new \RuntimeException("WireTransport: unsupported listen scheme '{$scheme}'"),
        };

        // Unix socket: unlink a stale sock if one's sitting around.
        if ($scheme === 'unix' && is_file($addr)) {
            @unlink($addr);
        }

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server($serverDsn, $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("WireTransport: listen on {$dsn} failed ({$errno}): {$errstr}");
        }

        $peer = @stream_socket_accept($server, $acceptTimeout);
        if ($peer === false) {
            @fclose($server);
            if ($scheme === 'unix') @unlink($addr);
            throw new \RuntimeException("WireTransport: no client connected to {$dsn} within {$acceptTimeout}s");
        }
        @stream_set_blocking($peer, false);

        return new self($peer, $server, $dsn);
    }

    /**
     * Parse `listen://tcp/host:port` or `listen://unix//path` into
     * `[scheme, addr]`. The unix form uses a leading slash after the
     * scheme to signal an absolute path — `listen://unix//tmp/s.sock`
     * → scheme=`unix`, addr=`/tmp/s.sock`.
     *
     * @return array{0:string,1:string}
     */
    private static function parseListenDsn(string $dsn): array
    {
        if (! str_starts_with($dsn, 'listen://')) {
            throw new \RuntimeException("WireTransport: '{$dsn}' is not a listen DSN");
        }
        $tail = substr($dsn, strlen('listen://'));
        $slash = strpos($tail, '/');
        if ($slash === false || $slash === 0) {
            throw new \RuntimeException("WireTransport: listen DSN must specify scheme/addr — got '{$dsn}'");
        }
        $scheme = substr($tail, 0, $slash);
        $addr   = substr($tail, $slash + 1);
        if ($addr === '') {
            throw new \RuntimeException("WireTransport: listen DSN missing address — got '{$dsn}'");
        }
        return [$scheme, $addr];
    }
}
