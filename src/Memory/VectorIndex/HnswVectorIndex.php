<?php

declare(strict_types=1);

namespace SuperAgent\Memory\VectorIndex;

/**
 * HNSW (Hierarchical Navigable Small World) vector index, fronted by a
 * Node.js subprocess that owns the actual `@ruvector/rvf-wasm` instance.
 *
 * **Why a subprocess?** ruvector's HNSW lives in WASM in a Node module.
 * PHP has no native HNSW (and a pure-PHP implementation is too slow to
 * justify). We run one long-lived `node bin/vector-index-server.js`
 * process that holds the WASM heap; PHP talks to it over stdin/stdout
 * with newline-delimited JSON-RPC. ~50ms cold-start, then ≤1ms per
 * query.
 *
 * **Auto-fallback.** If the bridge can't start (Node not on PATH, the
 * server script missing, an RPC fails / times out), this class
 * **degrades silently to `BruteForceVectorIndex`** for the rest of its
 * lifetime. Calling code never has to branch on backend availability.
 *
 * **Bridge wire format** (one JSON object per stdin/stdout line):
 *
 *   → {"id":"1", "method":"add",    "params":{"id":"x","vector":[...],"payload":{...}}}
 *   ← {"id":"1", "ok":true}
 *
 *   → {"id":"2", "method":"search", "params":{"query":[...],"k":5,"minScore":0.3}}
 *   ← {"id":"2", "ok":true, "result":[{"id":"x","score":0.91,"payload":{...}}]}
 *
 *   → {"id":"3", "method":"count"}
 *   ← {"id":"3", "ok":true, "result":42}
 *
 * The server script is intentionally not yet shipped — it requires an
 * npm install of `@ruvector/rvf-wasm`. Hosts that want HNSW author a
 * tiny script honoring the protocol above; hosts that don't get clean
 * brute-force automatically.
 *
 * **Dependency-free**: uses PHP's native `proc_open` so SuperAgent's
 * runtime needs nothing beyond `ext-pcntl`-less PHP 8.1 + a `node`
 * binary on PATH (only when the bridge is actually used).
 */
final class HnswVectorIndex implements VectorIndex
{
    /** @var resource|null process handle from proc_open */
    private $proc = null;

    /** @var resource|null stdin pipe */
    private $stdin = null;

    /** @var resource|null stdout pipe */
    private $stdout = null;

    /**
     * Failsafe pure-PHP backend. Always live. We mirror every successful
     * `add()` into it so a mid-life bridge crash falls back transparently
     * with the data already loaded.
     */
    private BruteForceVectorIndex $fallback;

    private bool $bridgeFailed = false;

    private int $rpcCounter = 0;

    /** @var string Buffered partial line from stdout. */
    private string $rxBuf = '';

    public function __construct(
        private readonly int $dimensions,
        private readonly string $serverScript,
        /** @var array<string, string>|null */
        private readonly ?array $env = null,
        private readonly float $rpcTimeoutSeconds = 5.0,
        private readonly string $nodeBinary = 'node',
        bool $bridgeDisabled = false,
    ) {
        $this->fallback = new BruteForceVectorIndex($dimensions);
        if ($bridgeDisabled) {
            $this->bridgeFailed = true;
        }
    }

    public function count(): int
    {
        if (!$this->ensureBridge()) return $this->fallback->count();
        $resp = $this->call('count', []);
        if ($resp === null) return $this->fallback->count();
        return (int) ($resp['result'] ?? 0);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function add(string $id, array $vector, array $payload = []): void
    {
        if (count($vector) !== $this->dimensions) {
            throw new \InvalidArgumentException(
                "vector dim {$this->dimensions} expected, got " . count($vector)
            );
        }
        $this->fallback->add($id, $vector, $payload);
        if (!$this->ensureBridge()) return;
        $this->call('add', ['id' => $id, 'vector' => $vector, 'payload' => $payload]);
    }

    public function addAll(iterable $items): void
    {
        foreach ($items as $item) {
            $this->add($item->id, $item->vector, $item->payload);
        }
    }

    public function remove(string $id): void
    {
        $this->fallback->remove($id);
        if (!$this->ensureBridge()) return;
        $this->call('remove', ['id' => $id]);
    }

    public function clear(): void
    {
        $this->fallback->clear();
        if (!$this->ensureBridge()) return;
        $this->call('clear', []);
    }

    public function search(array $query, int $k, float $minScore = 0.0): array
    {
        if (!$this->ensureBridge()) {
            return $this->fallback->search($query, $k, $minScore);
        }
        $resp = $this->call('search', ['query' => $query, 'k' => $k, 'minScore' => $minScore]);
        if ($resp === null || !is_array($resp['result'] ?? null)) {
            return $this->fallback->search($query, $k, $minScore);
        }
        $out = [];
        foreach ($resp['result'] as $row) {
            if (!is_array($row)) continue;
            $out[] = new SearchResult(
                id: (string) ($row['id'] ?? ''),
                score: (float) ($row['score'] ?? 0.0),
                payload: is_array($row['payload'] ?? null) ? $row['payload'] : [],
            );
        }
        return $out;
    }

    /** True iff the Node bridge has started and is still alive. */
    public function bridgeIsLive(): bool
    {
        return $this->proc !== null
            && !$this->bridgeFailed
            && is_resource($this->proc);
    }

    public function __destruct()
    {
        $this->shutdownBridge();
    }

    // ── Internals ────────────────────────────────────────────────────

    private function ensureBridge(): bool
    {
        if ($this->bridgeFailed) return false;
        if ($this->proc !== null) return true;

        if (!is_file($this->serverScript)) {
            $this->bridgeFailed = true;
            return false;
        }

        $cmd = [$this->nodeBinary, $this->serverScript, '--dim=' . $this->dimensions];
        $descriptors = [
            0 => ['pipe', 'r'], // stdin  (we write)
            1 => ['pipe', 'w'], // stdout (we read)
            2 => ['pipe', 'w'], // stderr (we discard)
        ];
        $pipes = [];

        try {
            $proc = @proc_open($cmd, $descriptors, $pipes, null, $this->env);
        } catch (\Throwable) {
            $this->bridgeFailed = true;
            return false;
        }

        if (!is_resource($proc)) {
            $this->bridgeFailed = true;
            return false;
        }

        // Non-blocking stdout so partial-line reads don't deadlock.
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            stream_set_blocking($pipes[1], false);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            stream_set_blocking($pipes[2], false);
        }

        $this->proc = $proc;
        $this->stdin = $pipes[0] ?? null;
        $this->stdout = $pipes[1] ?? null;

        if (!is_resource($this->stdin) || !is_resource($this->stdout)) {
            $this->bridgeFailed = true;
            $this->shutdownBridge();
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function call(string $method, array $params): ?array
    {
        if (!is_resource($this->stdin) || !is_resource($this->stdout)) {
            $this->bridgeFailed = true;
            return null;
        }

        $id = (string) (++$this->rpcCounter);
        $payload = json_encode([
            'id'     => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) return null;

        $written = @fwrite($this->stdin, $payload . "\n");
        if ($written === false || $written === 0) {
            $this->bridgeFailed = true;
            return null;
        }
        @fflush($this->stdin);

        // Read until we see a JSON line whose `id` matches our request.
        // Other lines (notifications, async events) are ignored — but
        // we cap total wait by $rpcTimeoutSeconds.
        $deadline = microtime(true) + $this->rpcTimeoutSeconds;
        while (microtime(true) < $deadline) {
            $line = $this->readLine();
            if ($line !== null) {
                $resp = json_decode($line, true);
                if (is_array($resp) && ($resp['id'] ?? null) === $id) {
                    if (($resp['ok'] ?? false) !== true) {
                        // Server-reported failure. Mark dead so we don't
                        // hammer it; fallback takes over.
                        $this->bridgeFailed = true;
                        return null;
                    }
                    return $resp;
                }
                continue; // some other line — keep reading
            }
            // No line yet; check process health, then idle briefly.
            if (!$this->procAlive()) {
                $this->bridgeFailed = true;
                return null;
            }
            usleep(1000); // 1ms
        }
        // Timeout. Fail the bridge so subsequent calls go straight to fallback.
        $this->bridgeFailed = true;
        return null;
    }

    /**
     * Read one complete \n-terminated line from stdout, or null if
     * none is fully available yet. Buffers partial reads.
     */
    private function readLine(): ?string
    {
        if (!is_resource($this->stdout)) return null;

        $chunk = @fread($this->stdout, 65536);
        if ($chunk !== false && $chunk !== '') {
            $this->rxBuf .= $chunk;
        }
        $nl = strpos($this->rxBuf, "\n");
        if ($nl === false) return null;

        $line = substr($this->rxBuf, 0, $nl);
        $this->rxBuf = substr($this->rxBuf, $nl + 1);
        return rtrim($line, "\r");
    }

    private function procAlive(): bool
    {
        if (!is_resource($this->proc)) return false;
        $status = @proc_get_status($this->proc);
        return is_array($status) && ($status['running'] ?? false);
    }

    private function shutdownBridge(): void
    {
        if (is_resource($this->stdin)) { @fclose($this->stdin); $this->stdin = null; }
        if (is_resource($this->stdout)) { @fclose($this->stdout); $this->stdout = null; }
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
            $this->proc = null;
        }
    }
}
