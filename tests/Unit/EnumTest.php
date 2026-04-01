<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Enums\Provider;
use SuperAgent\Enums\Role;
use SuperAgent\Enums\StopReason;

class EnumTest extends TestCase
{
    public function test_role_values(): void
    {
        $this->assertSame('user', Role::User->value);
        $this->assertSame('assistant', Role::Assistant->value);
        $this->assertSame('system', Role::System->value);
    }

    public function test_stop_reason_values(): void
    {
        $this->assertSame('end_turn', StopReason::EndTurn->value);
        $this->assertSame('tool_use', StopReason::ToolUse->value);
        $this->assertSame('max_tokens', StopReason::MaxTokens->value);
        $this->assertSame('stop_sequence', StopReason::StopSequence->value);
    }

    public function test_stop_reason_from_string(): void
    {
        $this->assertSame(StopReason::EndTurn, StopReason::from('end_turn'));
        $this->assertSame(StopReason::ToolUse, StopReason::from('tool_use'));
        $this->assertNull(StopReason::tryFrom('nonexistent'));
    }

    public function test_provider_values(): void
    {
        $this->assertSame('anthropic', Provider::Anthropic->value);
    }
}
