<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

/**
 * A permission decision is pending for a tool call.
 *
 * This projects the existing `PermissionEngine` / `ApprovalStep`
 * state onto the wire stream so any UI — shell TUI, ACP IDE bridge,
 * stream-json consumer — can render the pending request and route
 * the user's decision back without reaching into the engine
 * directly. Matches the `ApprovalRuntime` pattern in kimi-cli.
 *
 * Lifecycle in the v1 wire protocol:
 *
 *   → permission_request   — emitted by the engine; UI shows prompt
 *   ← (user's decision arrives via out-of-band channel:
 *      `PermissionCallbackInterface::decide()`)
 *   → tool_started         — the call proceeds (or doesn't fire)
 *
 * The out-of-band decision channel is NOT modelled as a wire event
 * because decisions are per-UI (TTY prompt, IDE modal, API POST).
 * Each UI wires its own back-channel to `ConsolePermissionCallback`
 * or an equivalent.
 */
class PermissionRequestEvent extends StreamEvent
{
    public function __construct(
        public readonly string $toolName,
        public readonly string $toolUseId,
        /** @var array<string, mixed> */
        public readonly array $toolInput = [],
        /** Short human-readable rationale from PermissionEngine: "new tool", "rule:deny bash curl *", etc. */
        public readonly ?string $reason = null,
        /** Suggested UI affordance — `ask` / `allow` / `deny`. `ask` means no rule fired; ask the user. */
        public readonly string $defaultAction = 'ask',
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'permission_request';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'tool_name'      => $this->toolName,
            'tool_use_id'    => $this->toolUseId,
            'tool_input'     => $this->toolInput,
            'reason'         => $this->reason,
            'default_action' => $this->defaultAction,
        ];
    }
}
