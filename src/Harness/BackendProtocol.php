<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

/**
 * JSON-lines protocol for communicating between a backend (agent engine)
 * and a frontend (TUI, web UI, etc.).
 *
 * Backend -> Frontend: events prefixed with "SAJSON:" followed by JSON
 * Frontend -> Backend: JSON requests on stdin
 */
class BackendProtocol
{
    public const PREFIX = 'SAJSON:';

    /** @var resource writable stream (stdout or custom) */
    private $output;

    /** @var resource readable stream (stdin or custom) */
    private $input;

    /**
     * @param resource|null $output
     * @param resource|null $input
     */
    public function __construct($output = null, $input = null)
    {
        $this->output = $output ?? STDOUT;
        $this->input = $input ?? STDIN;
    }

    // ── Emit events (backend -> frontend) ────────────────────────

    /**
     * Emit a typed event to the frontend.
     */
    public function emit(string $type, array $data = []): void
    {
        $event = array_merge(['type' => $type, 'ts' => microtime(true)], $data);
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($this->output, self::PREFIX . $json . "\n");
        if ($this->output !== STDOUT) {
            fflush($this->output);
        }
    }

    // Convenience emitters:

    public function emitReady(array $initialState): void
    {
        $this->emit('ready', ['state' => $initialState]);
    }

    public function emitAssistantDelta(string $text): void
    {
        $this->emit('assistant_delta', ['text' => $text]);
    }

    public function emitAssistantComplete(string $fullText, ?array $usage = null): void
    {
        $this->emit('assistant_complete', ['text' => $fullText, 'usage' => $usage]);
    }

    public function emitToolStarted(string $toolName, string $toolUseId, array $input = []): void
    {
        $this->emit('tool_started', [
            'tool_name' => $toolName,
            'tool_use_id' => $toolUseId,
            'input' => $input,
        ]);
    }

    public function emitToolCompleted(string $toolName, string $toolUseId, string $output, bool $isError = false): void
    {
        $this->emit('tool_completed', [
            'tool_name' => $toolName,
            'tool_use_id' => $toolUseId,
            'output' => $output,
            'is_error' => $isError,
        ]);
    }

    public function emitStatus(string $message, array $data = []): void
    {
        $this->emit('status', ['message' => $message, 'data' => $data]);
    }

    public function emitError(string $message, bool $recoverable = true, ?string $code = null): void
    {
        $this->emit('error', [
            'message' => $message,
            'recoverable' => $recoverable,
            'code' => $code,
        ]);
    }

    public function emitStateUpdate(array $state): void
    {
        $this->emit('state_update', ['state' => $state]);
    }

    public function emitModalRequest(string $requestId, string $modalType, array $options = []): void
    {
        $this->emit('modal_request', [
            'request_id' => $requestId,
            'modal_type' => $modalType,  // 'permission', 'question', 'select'
            'options' => $options,
        ]);
    }

    // ── Read requests (frontend -> backend) ──────────────────────

    /**
     * Read one request from the input stream (blocking).
     * Returns null if stream closed or invalid data.
     */
    public function readRequest(): ?array
    {
        $line = fgets($this->input);
        if ($line === false) {
            return null;
        }
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $data = json_decode($line, true);
        if (!is_array($data) || !isset($data['type'])) {
            return null;
        }
        return $data;
    }

    /**
     * Read request with timeout (non-blocking poll).
     */
    public function readRequestWithTimeout(float $timeoutSeconds): ?array
    {
        $read = [$this->input];
        $write = $except = [];
        $sec = (int) $timeoutSeconds;
        $usec = (int) (($timeoutSeconds - $sec) * 1000000);

        $changed = stream_select($read, $write, $except, $sec, $usec);
        if ($changed === false || $changed === 0) {
            return null;
        }
        return $this->readRequest();
    }

    // ── StreamEvent bridge ───────────────────────────────────────

    /**
     * Create a StreamEventEmitter listener that emits events via this protocol.
     */
    public function createStreamBridge(): callable
    {
        return function (StreamEvent $event) {
            match (true) {
                $event instanceof TextDeltaEvent => $this->emitAssistantDelta($event->text),
                $event instanceof ToolStartedEvent => $this->emitToolStarted($event->toolName, $event->toolUseId, $event->toolInput),
                $event instanceof ToolCompletedEvent => $this->emitToolCompleted($event->toolName, $event->toolUseId, $event->output, $event->isError),
                $event instanceof TurnCompleteEvent => $this->emitAssistantComplete('', $event->usage),
                $event instanceof CompactionEvent => $this->emitStatus('Context compacted', ['tier' => $event->tier, 'tokens_saved' => $event->tokensSaved]),
                $event instanceof StatusEvent => $this->emitStatus($event->message, $event->data),
                $event instanceof ErrorEvent => $this->emitError($event->message, $event->recoverable, $event->code),
                $event instanceof AgentCompleteEvent => $this->emit('agent_complete', [
                    'total_turns' => $event->totalTurns,
                    'total_cost_usd' => $event->totalCostUsd,
                ]),
                default => null,
            };
        };
    }
}
