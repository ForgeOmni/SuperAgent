<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Browser;

/**
 * Native Messaging transport for browser bridge tools.
 *
 * Mozilla / Chromium Native Messaging frames every JSON message with a
 * 4-byte little-endian length prefix on both stdin (host → extension)
 * and stdout (extension → host). This class wraps the framing so callers
 * exchange decoded arrays only.
 *
 * The companion launcher binary is whatever the host registers in the
 * Native Messaging manifest (`forgeomni-bridge.json`, see
 * `FirefoxBridge` docblock for the full manifest layout). That binary
 * must:
 *   - Accept length-prefixed JSON on stdin.
 *   - Forward each message to the WebExtension via `runtime.connectNative`.
 *   - Write the WebExtension's reply length-prefixed back on stdout.
 *
 * In practice the launcher is a thin (~50-line) script that just
 * `exec`s into Firefox (or Chromium) with the WebExtension preinstalled.
 * jcode ships its own Rust launcher for the same protocol — porting is
 * straightforward because the wire shape is browser-defined and stable.
 *
 * **Robustness.** All reads use `fread` against a non-blocking stream
 * with a deadline. A truncated frame, EOF, or invalid JSON returns null
 * from `recv()` so the bridge can decide whether to retry, restart the
 * launcher, or surface an error to the agent.
 */
final class NativeMessagingTransport
{
    /** @var resource|null */
    private $proc = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    /** @var resource|null */
    private $stderr = null;

    private string $stderrBuffer = '';

    public function __construct(
        /** @var list<string>  argv for the Native Messaging launcher */
        private readonly array $launcherArgv,
        private readonly int $defaultTimeoutMs = 15_000,
    ) {
    }

    public function start(): bool
    {
        if ($this->proc !== null) return true;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($this->launcherArgv, $descriptors, $pipes);
        if (!is_resource($proc)) return false;

        $this->proc = $proc;
        [$this->stdin, $this->stdout, $this->stderr] = $pipes;
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
        return true;
    }

    public function isRunning(): bool
    {
        if ($this->proc === null) return false;
        $status = proc_get_status($this->proc);
        return (bool) ($status['running'] ?? false);
    }

    /**
     * Send a single JSON-encodable message. Returns false on any frame
     * write failure (EOF, broken pipe).
     */
    public function send(array $message): bool
    {
        if ($this->stdin === null) return false;
        $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $len = strlen($json);
        // Native Messaging protocol: 4-byte LE length, then payload.
        $frame = pack('V', $len) . $json;
        $written = @fwrite($this->stdin, $frame);
        return $written === strlen($frame);
    }

    /**
     * Read one framed message. Returns null on timeout, EOF, or invalid
     * frame. Caller decides whether that is fatal.
     */
    public function recv(?int $timeoutMs = null): ?array
    {
        if ($this->stdout === null) return null;
        $deadline = microtime(true) + (($timeoutMs ?? $this->defaultTimeoutMs) / 1000.0);

        $header = $this->readBytes(4, $deadline);
        if ($header === null) return null;

        $unpacked = unpack('Vlen', $header);
        if (!isset($unpacked['len']) || $unpacked['len'] <= 0 || $unpacked['len'] > 16 * 1024 * 1024) {
            return null;
        }
        $payload = $this->readBytes((int) $unpacked['len'], $deadline);
        if ($payload === null) return null;

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Drain any stderr the launcher emitted; useful for debugging hangs. */
    public function drainStderr(): string
    {
        if ($this->stderr === null) return $this->stderrBuffer;
        while (($chunk = @fread($this->stderr, 8192)) !== false && $chunk !== '') {
            $this->stderrBuffer .= $chunk;
        }
        return $this->stderrBuffer;
    }

    public function stop(): void
    {
        if ($this->stdin !== null)  @fclose($this->stdin);
        if ($this->stdout !== null) @fclose($this->stdout);
        if ($this->stderr !== null) @fclose($this->stderr);
        if ($this->proc !== null) {
            @proc_terminate($this->proc, 9);
            @proc_close($this->proc);
        }
        $this->proc = $this->stdin = $this->stdout = $this->stderr = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** Read exactly $count bytes or null on timeout/EOF. */
    private function readBytes(int $count, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $count) {
            $needed = $count - strlen($buf);
            $chunk = @fread($this->stdout, $needed);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                continue;
            }
            if (feof($this->stdout)) return null;
            if (microtime(true) >= $deadline) return null;
            usleep(20_000);
        }
        return $buf;
    }
}
