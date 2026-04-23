<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Harness;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\PermissionRequestEvent;
use SuperAgent\Harness\Wire\ApprovalRuntime;
use SuperAgent\Harness\Wire\WireEvent;

class ApprovalRuntimeTest extends TestCase
{
    public function test_permission_request_event_is_wire_compliant(): void
    {
        $e = new PermissionRequestEvent('Bash', 'toolu_1', ['command' => 'ls'], 'new tool', 'ask');
        $this->assertInstanceOf(WireEvent::class, $e);
        $arr = $e->toArray();
        $this->assertSame(1, $arr['wire_version']);
        $this->assertSame('permission_request', $arr['type']);
        $this->assertSame('Bash', $arr['tool_name']);
        $this->assertSame('toolu_1', $arr['tool_use_id']);
        $this->assertSame(['command' => 'ls'], $arr['tool_input']);
        $this->assertSame('new tool', $arr['reason']);
        $this->assertSame('ask', $arr['default_action']);
    }

    public function test_approval_runtime_emits_request_then_awaits_decision(): void
    {
        $emitted = [];
        $runtime = new ApprovalRuntime(
            emit: static function (WireEvent $e) use (&$emitted) {
                $emitted[] = $e->toArray();
            },
            awaitChannel: static function (string $id, string $default): string {
                // Pretend the IDE said "allow" for toolu_1.
                return $id === 'toolu_1' ? 'allow' : $default;
            },
        );

        $decision = $runtime->request('Bash', 'toolu_1', ['command' => 'ls'], 'bash tool', 'ask');
        $this->assertSame('allow', $decision);
        $this->assertCount(1, $emitted);
        $this->assertSame('permission_request', $emitted[0]['type']);
    }

    public function test_default_action_bubbles_through_when_channel_has_no_opinion(): void
    {
        $runtime = new ApprovalRuntime(
            emit: static fn (WireEvent $e) => null,
            // Channel that always returns the default — simulates a
            // UI that doesn't collect a decision (e.g. stream-json
            // mode with no interactive back-channel).
            awaitChannel: static fn (string $id, string $default): string => $default,
        );

        $this->assertSame('deny', $runtime->request('Write', 'id-a', [], null, 'deny'));
        $this->assertSame('allow', $runtime->request('Read', 'id-b', [], null, 'allow'));
        $this->assertSame('ask', $runtime->request('Bash', 'id-c', [], null, 'ask'));
    }
}
