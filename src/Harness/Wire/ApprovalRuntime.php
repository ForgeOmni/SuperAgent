<?php

declare(strict_types=1);

namespace SuperAgent\Harness\Wire;

use SuperAgent\Harness\PermissionRequestEvent;

/**
 * Minimal ApprovalRuntime — projects pending tool-call approvals as
 * `PermissionRequestEvent`s on the wire stream.
 *
 * Motivation (from kimi-cli's `src/kimi_cli/approval_runtime/`):
 * today our `PermissionEngine` resolves decisions inline via the
 * injected `PermissionCallbackInterface`. That works fine for TTY
 * prompts (callback drops into `readline`), but it doesn't compose
 * with the wire stream: IDE bridges / ACP servers want to see a
 * `permission_request` wire event, route it to the user somehow
 * (modal, webview, notification), then inject the decision back.
 *
 * This class is the thin projection layer:
 *   1. `emitRequest()` → emit a PermissionRequestEvent for any UI
 *      listening on the wire stream.
 *   2. `awaitDecision()` → block on the caller's chosen back-channel
 *      until a decision lands.
 *
 * The back-channel is intentionally NOT a wire event — different UIs
 * handle the read-back differently (stdin prompt, TCP socket, HTTP
 * callback). `awaitDecision()` takes a closure so the wiring stays
 * UI-agnostic at this layer.
 *
 * This class is deliberately minimal; full integration into
 * `PermissionEngine` is deferred to a focused follow-up that also
 * wires the corresponding ACP bridge (see docs/WIRE_PROTOCOL.md §6).
 */
final class ApprovalRuntime
{
    /**
     * @param callable(WireEvent): void            $emit         Where to send the request event.
     * @param callable(string, string): string     $awaitChannel fn($toolUseId, $default) → 'allow'|'deny'|'ask'.
     */
    public function __construct(
        private $emit,
        private $awaitChannel,
    ) {
    }

    public function request(
        string $toolName,
        string $toolUseId,
        array $toolInput = [],
        ?string $reason = null,
        string $defaultAction = 'ask',
    ): string {
        $event = new PermissionRequestEvent(
            toolName: $toolName,
            toolUseId: $toolUseId,
            toolInput: $toolInput,
            reason: $reason,
            defaultAction: $defaultAction,
        );
        ($this->emit)($event);

        return ($this->awaitChannel)($toolUseId, $defaultAction);
    }
}
