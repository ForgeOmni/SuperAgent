<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\AgentResult;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;

class AgentResultTest extends TestCase
{
    public function test_text(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('hello world')];

        $result = new AgentResult(message: $msg, allResponses: [$msg]);

        $this->assertSame('hello world', $result->text());
    }

    public function test_turns(): void
    {
        $msg1 = new AssistantMessage();
        $msg2 = new AssistantMessage();

        $result = new AgentResult(message: $msg2, allResponses: [$msg1, $msg2]);

        $this->assertSame(2, $result->turns());
    }

    public function test_total_usage(): void
    {
        $msg1 = new AssistantMessage();
        $msg1->usage = new Usage(100, 20);
        $msg2 = new AssistantMessage();
        $msg2->usage = new Usage(150, 30);

        $result = new AgentResult(message: $msg2, allResponses: [$msg1, $msg2]);
        $usage = $result->totalUsage();

        $this->assertSame(250, $usage->inputTokens);
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(300, $usage->totalTokens());
    }

    public function test_null_message(): void
    {
        $result = new AgentResult(message: null);

        $this->assertSame('', $result->text());
        $this->assertSame(0, $result->turns());
    }

    public function test_idempotency_key_defaults_null(): void
    {
        $result = new AgentResult(message: null);

        $this->assertNull($result->idempotencyKey);
    }

    public function test_idempotency_key_passthrough(): void
    {
        $result = new AgentResult(message: null, idempotencyKey: 'job-123:turn-7');

        $this->assertSame('job-123:turn-7', $result->idempotencyKey);
    }
}
