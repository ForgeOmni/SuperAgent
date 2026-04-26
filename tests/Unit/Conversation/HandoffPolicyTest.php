<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Conversation;

use PHPUnit\Framework\TestCase;
use SuperAgent\Conversation\HandoffPolicy;

class HandoffPolicyTest extends TestCase
{
    public function test_default_keeps_tool_history_drops_thinking_inserts_marker(): void
    {
        $p = HandoffPolicy::default();

        $this->assertTrue($p->keepToolHistory);
        $this->assertTrue($p->dropThinking);
        $this->assertSame('drop', $p->imageStrategy);
        $this->assertTrue($p->insertHandoffMarker);
        $this->assertTrue($p->resetContinuationIds);
    }

    public function test_preserve_all_keeps_thinking_and_continuation(): void
    {
        $p = HandoffPolicy::preserveAll();

        $this->assertFalse($p->dropThinking);
        $this->assertFalse($p->insertHandoffMarker);
        $this->assertFalse($p->resetContinuationIds);
        $this->assertTrue($p->keepToolHistory);
    }

    public function test_fresh_start_drops_tool_history(): void
    {
        $p = HandoffPolicy::freshStart();

        $this->assertFalse($p->keepToolHistory);
        $this->assertTrue($p->dropThinking);
        $this->assertTrue($p->insertHandoffMarker);
    }
}
