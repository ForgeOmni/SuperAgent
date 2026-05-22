<?php

declare(strict_types=1);

namespace SuperAgent\ACP;

/**
 * Pluggable backend that the {@see Server} delegates ACP method calls to.
 *
 * Hosts implement this to bridge ACP requests into their concrete agent
 * runner (SuperAgent's `Agent`, a Laravel-backed session, a custom orchestrator,
 * ...). The methods receive already-parsed params and return either the result
 * value (plain array) for success, or throw to surface a JSON-RPC error.
 *
 * Notifications (e.g. `session/update` events from the agent → client) are
 * pushed via {@see Server::notify()} from inside `prompt()` while it runs.
 */
interface Handler
{
    /**
     * Negotiate protocol version + advertise capabilities.
     *
     * @param array<string, mixed> $params Initialize params (protocolVersion, clientCapabilities, ...).
     * @return array<string, mixed> InitializeResponse (protocolVersion, capabilities, authMethods, agentInfo).
     */
    public function initialize(array $params): array;

    /**
     * Create a fresh session. Returns the assigned sessionId.
     *
     * @param array<string, mixed> $params NewSessionRequest (cwd, mcpServers, ...).
     * @return array<string, mixed> NewSessionResponse (sessionId, modes?, models?, ...).
     */
    public function newSession(array $params): array;

    /**
     * Load a previously-persisted session.
     *
     * @param array<string, mixed> $params LoadSessionRequest (sessionId).
     * @return array<string, mixed> LoadSessionResponse.
     */
    public function loadSession(array $params): array;

    /**
     * Run a user prompt to completion. Implementations should call
     * `$server->notify('session/update', …)` to stream partial output back to
     * the editor while the model runs.
     *
     * @param array<string, mixed> $params PromptRequest (sessionId, prompt[]).
     * @return array<string, mixed> PromptResponse (stopReason, usage, ...).
     */
    public function prompt(array $params, Server $server): array;

    /**
     * Cancel the currently-running prompt for a session (notification, no result).
     *
     * @param array<string, mixed> $params CancelNotification (sessionId).
     */
    public function cancel(array $params): void;

    /**
     * Optional auth flow (return empty array when not required).
     *
     * @param array<string, mixed> $params AuthenticateRequest.
     * @return array<string, mixed> AuthenticateResponse.
     */
    public function authenticate(array $params): array;

    /**
     * Pi-borrowed: inject a mid-turn correction without aborting the current
     * turn. The currently-running `prompt()` should observe the steer via
     * its session entry (e.g. via `Server::peekSteer($sessionId)`) at its
     * next safe checkpoint and adjust its plan accordingly.
     *
     * Differences vs. `cancel`: cancel terminates; steer continues but with
     * a redirected goal. Differences vs. `prompt`: prompt starts a new turn;
     * steer modifies the in-flight turn.
     *
     * Default impl in {@see DefaultHandler}: append to the session's
     * `steerQueue`; the user's promptFn closure is responsible for draining
     * it at each iteration.
     *
     * @param array<string, mixed> $params {sessionId, prompt[]}
     */
    public function steer(array $params): void;

    /**
     * Pi-borrowed: queue a prompt for after the current turn completes.
     * Equivalent to a backpressure-aware multi-message send. The
     * follow-ups should be drained by the host in FIFO order at the next
     * `prompt()` call boundary.
     *
     * @param array<string, mixed> $params {sessionId, prompt[]}
     */
    public function followUp(array $params): void;
}
