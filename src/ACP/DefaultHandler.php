<?php

declare(strict_types=1);

namespace SuperAgent\ACP;

/**
 * Reference {@see Handler} suitable for hosts that just want a working ACP
 * endpoint without writing the boilerplate. Accepts a closure as the prompt
 * executor; that closure receives `(SessionEntry $session, array $params,
 * Server $server)` and returns the PromptResponse array.
 *
 * Capability advertisement is conservative: filesystem + permission requests
 * are NOT marked supported by default — hosts that want them flip the flags
 * via {@see withCapabilities()}.
 *
 *     $handler = new DefaultHandler(
 *         agentName: 'superagent',
 *         agentVersion: '1.0.2',
 *         promptFn: function (SessionEntry $s, array $params, Server $server): array {
 *             // … run SuperAgent\Agent, stream `session/update` via $server->notify(...) …
 *             return ['stopReason' => 'end_turn', 'usage' => [...]];
 *         },
 *     );
 *     (new Server($handler))->serve();
 */
final class DefaultHandler implements Handler
{
    private SessionRegistry $sessions;

    /** @var array<string, mixed> */
    private array $capabilities = [
        'loadSession' => false,
        'promptCapabilities' => [
            'image' => false,
            'audio' => false,
            'embeddedContext' => true,
        ],
    ];

    /** @var array<string, true> sessionId set with a pending cancel signal */
    private array $cancelled = [];

    /**
     * @param \Closure(SessionEntry, array<string,mixed>, Server): array<string,mixed> $promptFn
     */
    public function __construct(
        private readonly string $agentName,
        private readonly string $agentVersion,
        private readonly \Closure $promptFn,
        ?SessionRegistry $sessions = null,
    ) {
        $this->sessions = $sessions ?? new SessionRegistry();
    }

    /**
     * @param array<string, mixed> $caps
     */
    public function withCapabilities(array $caps): self
    {
        $this->capabilities = array_replace($this->capabilities, $caps);
        return $this;
    }

    public function sessions(): SessionRegistry
    {
        return $this->sessions;
    }

    public function initialize(array $params): array
    {
        return [
            'protocolVersion' => Protocol::PROTOCOL_VERSION,
            'agentCapabilities' => $this->capabilities,
            'authMethods' => [],
            'agentInfo' => [
                'name' => $this->agentName,
                'version' => $this->agentVersion,
            ],
        ];
    }

    public function newSession(array $params): array
    {
        $cwd = (string) ($params['cwd'] ?? getcwd() ?: '/');
        $id = $this->sessions->newId();
        $this->sessions->put(new SessionEntry($id, $cwd, [
            'mcpServers' => $params['mcpServers'] ?? [],
        ]));
        return ['sessionId' => $id];
    }

    public function loadSession(array $params): array
    {
        $sessionId = (string) ($params['sessionId'] ?? '');
        if ($sessionId === '' || $this->sessions->get($sessionId) === null) {
            throw new AcpException("session not found: {$sessionId}", Protocol::ERR_SESSION_NOT_FOUND);
        }
        return ['sessionId' => $sessionId];
    }

    public function prompt(array $params, Server $server): array
    {
        $sessionId = (string) ($params['sessionId'] ?? '');
        $entry = $this->sessions->get($sessionId);
        if ($entry === null) {
            throw new AcpException("session not found: {$sessionId}", Protocol::ERR_SESSION_NOT_FOUND);
        }
        if (isset($this->cancelled[$sessionId])) {
            unset($this->cancelled[$sessionId]);
            return ['stopReason' => 'cancelled'];
        }
        return ($this->promptFn)($entry, $params, $server);
    }

    public function cancel(array $params): void
    {
        $sessionId = (string) ($params['sessionId'] ?? '');
        if ($sessionId !== '') {
            $this->cancelled[$sessionId] = true;
        }
    }

    public function authenticate(array $params): array
    {
        // No-op by default; hosts override to implement OAuth/API key flows.
        return [];
    }

    public function wasCancelled(string $sessionId): bool
    {
        return isset($this->cancelled[$sessionId]);
    }

    public function steer(array $params): void
    {
        $sessionId = (string) ($params['sessionId'] ?? '');
        if ($sessionId === '') return;
        $entry = $this->sessions->get($sessionId);
        if ($entry === null) return;

        $prompt = $params['prompt'] ?? [];
        if (!isset($entry->meta['steerQueue']) || !is_array($entry->meta['steerQueue'])) {
            $entry->meta['steerQueue'] = [];
        }
        $entry->meta['steerQueue'][] = [
            'prompt' => $prompt,
            'at' => microtime(true),
        ];
    }

    public function followUp(array $params): void
    {
        $sessionId = (string) ($params['sessionId'] ?? '');
        if ($sessionId === '') return;
        $entry = $this->sessions->get($sessionId);
        if ($entry === null) return;

        $prompt = $params['prompt'] ?? [];
        if (!isset($entry->meta['followUpQueue']) || !is_array($entry->meta['followUpQueue'])) {
            $entry->meta['followUpQueue'] = [];
        }
        $entry->meta['followUpQueue'][] = [
            'prompt' => $prompt,
            'at' => microtime(true),
        ];
    }

    /**
     * Pop the steer queue for a session (host calls this inside promptFn at
     * safe checkpoints). Empties the queue and returns the entries in FIFO.
     *
     * @return list<array{prompt:mixed,at:float}>
     */
    public function drainSteer(string $sessionId): array
    {
        $entry = $this->sessions->get($sessionId);
        if ($entry === null) return [];
        $queue = $entry->meta['steerQueue'] ?? [];
        $entry->meta['steerQueue'] = [];
        return is_array($queue) ? array_values($queue) : [];
    }

    /**
     * Pop the follow-up queue (called by the host at the start of the next
     * prompt cycle, or whenever it wants to consume backlogged user input).
     *
     * @return list<array{prompt:mixed,at:float}>
     */
    public function drainFollowUp(string $sessionId): array
    {
        $entry = $this->sessions->get($sessionId);
        if ($entry === null) return [];
        $queue = $entry->meta['followUpQueue'] ?? [];
        $entry->meta['followUpQueue'] = [];
        return is_array($queue) ? array_values($queue) : [];
    }
}
