<?php

declare(strict_types=1);

namespace SuperAgent\ACP;

/**
 * ACP server transport: reads JSON-RPC messages from stdin (one JSON object per
 * `\n` — ACP does **not** use LSP-style Content-Length framing), dispatches each
 * to a {@see Handler}, and writes responses/notifications to stdout.
 *
 * Run with:
 *
 *     $server = new Server($handler);
 *     $server->serve();           // blocks until stdin EOF or SIGTERM
 *
 * The server is single-threaded by design: it reads one line, runs the handler,
 * writes the response, and loops. Handlers that need to push streaming updates
 * during a long-running prompt do so via {@see notify()}, which is safe to call
 * synchronously from inside a handler method.
 *
 * Filehandles default to STDIN/STDOUT; pass alternates for tests.
 */
final class Server
{
    /** @var resource */
    private $in;
    /** @var resource */
    private $out;

    private bool $shuttingDown = false;

    public function __construct(
        private readonly Handler $handler,
        $in = null,
        $out = null,
    ) {
        $this->in = $in ?? STDIN;
        $this->out = $out ?? STDOUT;
    }

    /**
     * Blocking serve loop. Exits when stdin returns EOF.
     */
    public function serve(): void
    {
        while (! $this->shuttingDown && ! feof($this->in)) {
            $line = fgets($this->in);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $this->handleLine($line);
        }
    }

    /**
     * Process a single JSON-RPC line. Public so tests can inject lines without
     * standing up a real pipe.
     */
    public function handleLine(string $line): void
    {
        $msg = json_decode($line, true);
        if (! is_array($msg)) {
            $this->send(Protocol::envelopeError(null, Protocol::ERR_PARSE, 'Parse error: invalid JSON'));
            return;
        }

        $id = $msg['id'] ?? null;
        $method = (string) ($msg['method'] ?? '');
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        if ($method === '') {
            if ($id !== null) {
                $this->send(Protocol::envelopeError($id, Protocol::ERR_INVALID_REQUEST, 'Missing method'));
            }
            return;
        }

        // Notifications (no id) — no response.
        $isNotification = $id === null;

        try {
            $result = $this->dispatch($method, $params);
            if (! $isNotification) {
                $this->send(Protocol::envelopeOk($id, $result));
            }
        } catch (AcpException $e) {
            if (! $isNotification) {
                $this->send(Protocol::envelopeError($id, $e->getCode() ?: Protocol::ERR_INTERNAL, $e->getMessage(), $e->data));
            }
        } catch (\Throwable $e) {
            if (! $isNotification) {
                $this->send(Protocol::envelopeError($id, Protocol::ERR_INTERNAL, $e->getMessage()));
            }
        }
    }

    /**
     * Send a server-initiated notification (e.g. `session/update`).
     */
    public function notify(string $method, mixed $params): void
    {
        $this->send(Protocol::notification($method, $params));
    }

    public function stop(): void
    {
        $this->shuttingDown = true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function dispatch(string $method, array $params): array
    {
        switch ($method) {
            case Protocol::METHOD_INITIALIZE:
                return $this->handler->initialize($params);
            case Protocol::METHOD_AUTHENTICATE:
                return $this->handler->authenticate($params);
            case Protocol::METHOD_SESSION_NEW:
                return $this->handler->newSession($params);
            case Protocol::METHOD_SESSION_LOAD:
                return $this->handler->loadSession($params);
            case Protocol::METHOD_SESSION_PROMPT:
                return $this->handler->prompt($params, $this);
            case Protocol::METHOD_SESSION_CANCEL:
                $this->handler->cancel($params);
                return [];
            default:
                throw new AcpException("Method not found: {$method}", Protocol::ERR_METHOD_NOT_FOUND);
        }
    }

    private function send(array $envelope): void
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        fwrite($this->out, $json . "\n");
        fflush($this->out);
    }
}
