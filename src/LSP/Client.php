<?php

declare(strict_types=1);

namespace SuperAgent\LSP;

/**
 * Minimal LSP JSON-RPC stdio client. Spawns a language server subprocess via
 * `proc_open`, sends LSP requests using the official Content-Length framing,
 * and exposes synchronous wait-for-response helpers.
 *
 * Scope: just enough of the protocol to drive a coding agent — initialize,
 * textDocument/didOpen, textDocument/didChange, textDocument/diagnostic (pull
 * model), textDocument/hover, textDocument/definition, shutdown. Server-pushed
 * `publishDiagnostics` notifications are accumulated and surfaced via
 * {@see drainDiagnostics()}.
 *
 * Architecture note: PHP doesn't ship an async runtime; we use blocking reads
 * with a short timeout for the response loop. That's fine for batch use ("open
 * file, wait for diagnostics, close") but not for sustained background streaming.
 * Callers that need streaming should spawn a worker process.
 *
 * Lifecycle:
 *
 *     $client = new Client(['phpactor', 'language-server'], '/abs/project');
 *     $client->initialize();
 *     $client->didOpen('/abs/project/src/Foo.php', 'php', file_get_contents(...));
 *     $diags = $client->diagnostics('/abs/project/src/Foo.php');
 *     $client->shutdown();
 *
 * Throws {@see LspException} on protocol/transport errors.
 */
final class Client
{
    /** @var resource|null */
    private $proc = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private int $nextId = 1;

    /** @var array<int, array<string, mixed>> id → pending request envelope */
    private array $pending = [];

    /** @var array<string, list<array<string, mixed>>> uri → diagnostics list */
    private array $diagnosticsByUri = [];

    private bool $initialized = false;

    /**
     * @param array<int, string> $command argv to exec for the language server
     */
    public function __construct(
        private readonly array $command,
        private readonly string $rootDir,
        private readonly array $initializationOptions = [],
        private readonly int $responseTimeoutSeconds = 10,
    ) {
    }

    public function start(): void
    {
        if ($this->proc !== null) {
            return;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $cmd = array_map('escapeshellarg', $this->command);
        $proc = proc_open(implode(' ', $cmd), $descriptors, $pipes, $this->rootDir);
        if (! is_resource($proc)) {
            throw new LspException('Failed to spawn LSP server: ' . implode(' ', $this->command));
        }
        $this->proc = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    public function initialize(): array
    {
        $this->start();

        $params = [
            'processId' => getmypid(),
            'rootUri' => 'file://' . $this->rootDir,
            'rootPath' => $this->rootDir,
            'capabilities' => [
                'textDocument' => [
                    'synchronization' => ['didOpen' => true, 'didChange' => true, 'didClose' => true],
                    'publishDiagnostics' => ['relatedInformation' => true],
                    'diagnostic' => ['dynamicRegistration' => true],
                    'hover' => ['contentFormat' => ['markdown', 'plaintext']],
                    'definition' => ['linkSupport' => false],
                ],
                'workspace' => [
                    'workspaceFolders' => true,
                    'configuration' => true,
                ],
            ],
            'initializationOptions' => $this->initializationOptions ?: null,
            'workspaceFolders' => [
                ['uri' => 'file://' . $this->rootDir, 'name' => basename($this->rootDir)],
            ],
        ];

        $result = $this->request('initialize', $params);
        $this->notify('initialized', (object) []);
        $this->initialized = true;
        return $result;
    }

    public function didOpen(string $path, string $languageId, string $content): void
    {
        $this->notify('textDocument/didOpen', [
            'textDocument' => [
                'uri' => 'file://' . $path,
                'languageId' => $languageId,
                'version' => 1,
                'text' => $content,
            ],
        ]);
    }

    public function didChange(string $path, string $content, int $version = 2): void
    {
        $this->notify('textDocument/didChange', [
            'textDocument' => ['uri' => 'file://' . $path, 'version' => $version],
            'contentChanges' => [['text' => $content]],
        ]);
    }

    public function didClose(string $path): void
    {
        $this->notify('textDocument/didClose', [
            'textDocument' => ['uri' => 'file://' . $path],
        ]);
    }

    /**
     * Pull diagnostics for one file. Servers that support the LSP pull model
     * (3.17+) respond to `textDocument/diagnostic`; otherwise we fall back to
     * draining accumulated `publishDiagnostics` for $path.
     *
     * @return array<int, array<string, mixed>>
     */
    public function diagnostics(string $path): array
    {
        try {
            $result = $this->request('textDocument/diagnostic', [
                'textDocument' => ['uri' => 'file://' . $path],
            ]);
            if (isset($result['items']) && is_array($result['items'])) {
                return $result['items'];
            }
        } catch (LspException) {
            // Server doesn't support pull diagnostics; fall through to push cache.
        }
        // Drain server-pushed diagnostics that arrived asynchronously.
        $this->pumpIncoming(50_000); // 50ms
        return $this->diagnosticsByUri['file://' . $path] ?? [];
    }

    public function hover(string $path, int $line, int $character): ?array
    {
        try {
            return $this->request('textDocument/hover', [
                'textDocument' => ['uri' => 'file://' . $path],
                'position' => ['line' => $line, 'character' => $character],
            ]);
        } catch (LspException) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definition(string $path, int $line, int $character): array
    {
        try {
            $result = $this->request('textDocument/definition', [
                'textDocument' => ['uri' => 'file://' . $path],
                'position' => ['line' => $line, 'character' => $character],
            ]);
            if (! is_array($result)) {
                return [];
            }
            // Servers may return a single Location or array of Locations.
            return isset($result['uri']) ? [$result] : array_values($result);
        } catch (LspException) {
            return [];
        }
    }

    /**
     * Snapshot of every server-pushed diagnostics notification received so far,
     * keyed by URI. Caller may clear with {@see clearDiagnostics()}.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function drainDiagnostics(): array
    {
        $this->pumpIncoming(50_000);
        $snapshot = $this->diagnosticsByUri;
        $this->diagnosticsByUri = [];
        return $snapshot;
    }

    public function clearDiagnostics(): void
    {
        $this->diagnosticsByUri = [];
    }

    public function shutdown(): void
    {
        if ($this->proc === null) {
            return;
        }
        try {
            if ($this->initialized) {
                $this->request('shutdown', null);
                $this->notify('exit', null);
            }
        } catch (\Throwable) {
            // Best-effort.
        }
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        $this->stdin = $this->stdout = $this->stderr = null;
        proc_terminate($this->proc);
        proc_close($this->proc);
        $this->proc = null;
        $this->initialized = false;
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * Issue a synchronous LSP request and return the `result` field.
     *
     * @return mixed
     */
    public function request(string $method, mixed $params)
    {
        $this->start();
        $id = $this->nextId++;
        $envelope = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $envelope['params'] = $params;
        }
        $this->writeMessage($envelope);

        $deadline = microtime(true) + $this->responseTimeoutSeconds;
        while (microtime(true) < $deadline) {
            $msg = $this->readMessage(200_000); // 200ms
            if ($msg === null) {
                continue;
            }
            $this->handleIncoming($msg);
            if (isset($msg['id']) && $msg['id'] === $id) {
                if (isset($msg['error'])) {
                    throw new LspException(sprintf(
                        'LSP %s failed: %s',
                        $method,
                        is_array($msg['error']) ? (string) ($msg['error']['message'] ?? json_encode($msg['error'])) : (string) $msg['error'],
                    ));
                }
                return $msg['result'] ?? null;
            }
        }
        throw new LspException("LSP request '{$method}' timed out after {$this->responseTimeoutSeconds}s");
    }

    public function notify(string $method, mixed $params): void
    {
        $this->start();
        $envelope = ['jsonrpc' => '2.0', 'method' => $method];
        if ($params !== null) {
            $envelope['params'] = $params;
        }
        $this->writeMessage($envelope);
    }

    private function writeMessage(array $envelope): void
    {
        if (! is_resource($this->stdin)) {
            throw new LspException('LSP stdin is closed');
        }
        $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new LspException('Failed to encode LSP message: ' . json_last_error_msg());
        }
        $header = 'Content-Length: ' . strlen($body) . "\r\n\r\n";
        fwrite($this->stdin, $header . $body);
        fflush($this->stdin);
    }

    /**
     * Read one LSP message (Content-Length framed). Returns null on timeout.
     *
     * @return array<string, mixed>|null
     */
    private function readMessage(int $timeoutMicros): ?array
    {
        if (! is_resource($this->stdout)) {
            return null;
        }

        // Read headers
        $headerBuf = '';
        $deadline = microtime(true) + $timeoutMicros / 1_000_000;
        while (! str_contains($headerBuf, "\r\n\r\n")) {
            if (microtime(true) >= $deadline) {
                return null;
            }
            $chunk = fread($this->stdout, 1);
            if ($chunk === '' || $chunk === false) {
                usleep(1_000);
                continue;
            }
            $headerBuf .= $chunk;
        }

        if (! preg_match('/Content-Length:\s*(\d+)/i', $headerBuf, $m)) {
            return null;
        }
        $len = (int) $m[1];

        // Read body
        $body = '';
        $deadline = microtime(true) + $this->responseTimeoutSeconds;
        while (strlen($body) < $len) {
            if (microtime(true) >= $deadline) {
                throw new LspException('Timed out reading LSP message body');
            }
            $chunk = fread($this->stdout, $len - strlen($body));
            if ($chunk === '' || $chunk === false) {
                usleep(1_000);
                continue;
            }
            $body .= $chunk;
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new LspException('LSP server returned invalid JSON: ' . substr($body, 0, 200));
        }
        return $decoded;
    }

    /**
     * Drain whatever messages the server has pushed without blocking long.
     */
    private function pumpIncoming(int $totalMicros): void
    {
        $deadline = microtime(true) + $totalMicros / 1_000_000;
        while (microtime(true) < $deadline) {
            $msg = $this->readMessage(20_000); // 20ms per attempt
            if ($msg === null) {
                return;
            }
            $this->handleIncoming($msg);
        }
    }

    private function handleIncoming(array $msg): void
    {
        // Server → client notifications. The one we actually care about is
        // textDocument/publishDiagnostics so we can answer pull-style.
        $method = $msg['method'] ?? null;
        if ($method === 'textDocument/publishDiagnostics') {
            $uri = (string) ($msg['params']['uri'] ?? '');
            $diags = $msg['params']['diagnostics'] ?? [];
            if ($uri !== '') {
                $this->diagnosticsByUri[$uri] = is_array($diags) ? $diags : [];
            }
            return;
        }
        // Ignore other server-initiated traffic (window/logMessage etc.)
    }
}
