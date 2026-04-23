<?php

declare(strict_types=1);

namespace SuperAgent\Harness\Wire;

use SuperAgent\Harness\PermissionRequestEvent;
use SuperAgent\Permissions\PermissionBehavior;
use SuperAgent\Permissions\PermissionCallbackInterface;
use SuperAgent\Permissions\PermissionDecision;
use SuperAgent\Permissions\PermissionUpdate;

/**
 * Decorator that emits a `PermissionRequestEvent` on the wire stream
 * every time a tool call needs user approval, then delegates the
 * actual decision to an inner callback (typically
 * `ConsolePermissionCallback`).
 *
 * Usage (typically from `AgentFactory` when `--output json-stream`
 * is active so IDE bridges / CI consumers see pending approvals):
 *
 *   $inner   = new ConsolePermissionCallback(...);
 *   $emitter = $factory->makeJsonStreamEmitter();
 *   $wrapped = new WireProjectingPermissionCallback(
 *       $inner,
 *       static fn (\SuperAgent\Harness\Wire\WireEvent $e) => $emitter->emit($e),
 *   );
 *   // hand $wrapped to the PermissionEngine instead of $inner.
 *
 * The wire event is emitted **before** we call the inner callback's
 * `askUserPermission()` so a remote UI can race the TTY prompt if it
 * wants (future bidirectional ACP bridge). For the v1 stdio stream
 * MVP, nothing reads the event back — it's purely diagnostic, and the
 * inner callback still drives the final decision.
 *
 * Every other callback method (granted/denied hooks, auto classifier,
 * permission-update selector) is a pure pass-through. We don't project
 * those onto the wire because they're either post-decision (no need
 * to race) or synchronous UI input (no point streaming).
 */
final class WireProjectingPermissionCallback implements PermissionCallbackInterface
{
    /** @var \Closure(WireEvent): void */
    private \Closure $emit;

    public function __construct(
        private readonly PermissionCallbackInterface $inner,
        callable $emit,
    ) {
        $this->emit = $emit instanceof \Closure ? $emit : \Closure::fromCallable($emit);
    }

    public function askUserPermission(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): PermissionBehavior {
        // Project the pending approval onto the wire stream. We lean
        // on the decision's message + reason-type to populate the
        // event's `reason` string so IDE UIs can surface "why are we
        // asking" without re-running the engine.
        $reason = $decision->message;
        if ($reason === null && $decision->decisionReason !== null) {
            $reason = $decision->decisionReason->type
                . ($decision->decisionReason->detail !== null
                    ? (': ' . $decision->decisionReason->detail)
                    : '');
        }

        ($this->emit)(new PermissionRequestEvent(
            toolName: $toolName,
            toolUseId: (string) ($input['_tool_use_id'] ?? ''),
            toolInput: $input,
            reason: $reason,
            defaultAction: 'ask',
        ));

        return $this->inner->askUserPermission($toolName, $input, $decision);
    }

    public function runAutoClassifier(string $prompt): bool
    {
        return $this->inner->runAutoClassifier($prompt);
    }

    public function onPermissionGranted(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): void {
        $this->inner->onPermissionGranted($toolName, $input, $decision);
    }

    public function onPermissionDenied(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): void {
        $this->inner->onPermissionDenied($toolName, $input, $decision);
    }

    public function selectPermissionUpdate(array $suggestions): ?PermissionUpdate
    {
        return $this->inner->selectPermissionUpdate($suggestions);
    }
}
