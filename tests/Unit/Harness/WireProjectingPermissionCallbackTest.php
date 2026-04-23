<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Harness;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\PermissionRequestEvent;
use SuperAgent\Harness\Wire\WireEvent;
use SuperAgent\Harness\Wire\WireProjectingPermissionCallback;
use SuperAgent\Permissions\PermissionBehavior;
use SuperAgent\Permissions\PermissionCallbackInterface;
use SuperAgent\Permissions\PermissionDecision;
use SuperAgent\Permissions\PermissionDecisionReason;
use SuperAgent\Permissions\PermissionUpdate;

class WireProjectingPermissionCallbackTest extends TestCase
{
    public function test_ask_user_permission_emits_event_then_delegates(): void
    {
        $emitted = [];
        $inner = new StubCallback(behavior: PermissionBehavior::ALLOW);

        $wrapped = new WireProjectingPermissionCallback(
            $inner,
            static function (WireEvent $e) use (&$emitted) { $emitted[] = $e; },
        );

        $decision = PermissionDecision::ask('tool is network-attributed');
        $result = $wrapped->askUserPermission(
            'kimi_file_extract',
            ['file_path' => '/tmp/x.pdf'],
            $decision,
        );

        $this->assertSame(PermissionBehavior::ALLOW, $result);
        $this->assertSame(1, $inner->askCalls);

        // Event was emitted before delegation.
        $this->assertCount(1, $emitted);
        $this->assertInstanceOf(PermissionRequestEvent::class, $emitted[0]);
        $arr = $emitted[0]->toArray();
        $this->assertSame('permission_request', $arr['type']);
        $this->assertSame('kimi_file_extract', $arr['tool_name']);
        $this->assertSame(['file_path' => '/tmp/x.pdf'], $arr['tool_input']);
        $this->assertSame('tool is network-attributed', $arr['reason']);
        $this->assertSame('ask', $arr['default_action']);
    }

    public function test_reason_falls_back_to_decision_reason_when_no_message(): void
    {
        // When PermissionEngine emits just a DecisionReason (no user-
        // facing message), the event's `reason` should synthesize one
        // from the type + detail so IDEs have something to render.
        $emitted = [];
        $inner = new StubCallback(behavior: PermissionBehavior::DENY);

        $wrapped = new WireProjectingPermissionCallback(
            $inner,
            static function (WireEvent $e) use (&$emitted) { $emitted[] = $e; },
        );

        $decision = PermissionDecision::ask(
            message: null,
            reason: new PermissionDecisionReason('rule_match', 'bash curl *'),
        );
        $wrapped->askUserPermission('Bash', ['command' => 'curl x'], $decision);

        $this->assertCount(1, $emitted);
        $this->assertSame('rule_match: bash curl *', $emitted[0]->toArray()['reason']);
    }

    public function test_granted_denied_classifier_and_update_pass_through(): void
    {
        $emitted = [];
        $inner = new StubCallback();

        $wrapped = new WireProjectingPermissionCallback(
            $inner,
            static function (WireEvent $e) use (&$emitted) { $emitted[] = $e; },
        );

        $decision = PermissionDecision::allow();
        $wrapped->onPermissionGranted('Read', [], $decision);
        $wrapped->onPermissionDenied('Write', [], $decision);
        $wrapped->runAutoClassifier('classify this');
        $wrapped->selectPermissionUpdate([]);

        $this->assertSame(1, $inner->grantedCalls);
        $this->assertSame(1, $inner->deniedCalls);
        $this->assertSame(1, $inner->classifierCalls);
        $this->assertSame(1, $inner->updateCalls);

        // None of the pass-through methods should project onto the wire.
        $this->assertSame([], $emitted);
    }

    public function test_tool_use_id_threaded_through_when_provided_via_input_key(): void
    {
        $emitted = [];
        $wrapped = new WireProjectingPermissionCallback(
            new StubCallback(),
            static function (WireEvent $e) use (&$emitted) { $emitted[] = $e; },
        );

        $wrapped->askUserPermission('Write', [
            'file_path' => '/tmp/x',
            '_tool_use_id' => 'toolu_abc',   // convention: engine carries the id here
        ], PermissionDecision::ask());

        $this->assertSame('toolu_abc', $emitted[0]->toArray()['tool_use_id']);
    }
}

/** Minimal stub — counts calls, returns configured behaviour. */
final class StubCallback implements PermissionCallbackInterface
{
    public int $askCalls = 0;
    public int $grantedCalls = 0;
    public int $deniedCalls = 0;
    public int $classifierCalls = 0;
    public int $updateCalls = 0;

    public function __construct(
        private PermissionBehavior $behavior = PermissionBehavior::ALLOW,
    ) {
    }

    public function askUserPermission(string $toolName, array $input, PermissionDecision $decision): PermissionBehavior
    {
        $this->askCalls++;
        return $this->behavior;
    }

    public function runAutoClassifier(string $prompt): bool
    {
        $this->classifierCalls++;
        return true;
    }

    public function onPermissionGranted(string $toolName, array $input, PermissionDecision $decision): void
    {
        $this->grantedCalls++;
    }

    public function onPermissionDenied(string $toolName, array $input, PermissionDecision $decision): void
    {
        $this->deniedCalls++;
    }

    public function selectPermissionUpdate(array $suggestions): ?PermissionUpdate
    {
        $this->updateCalls++;
        return null;
    }
}
