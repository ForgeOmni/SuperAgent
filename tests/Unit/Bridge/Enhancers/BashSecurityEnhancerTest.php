<?php

namespace SuperAgent\Tests\Unit\Bridge\Enhancers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\Enhancers\BashSecurityEnhancer;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

class BashSecurityEnhancerTest extends TestCase
{
    private BashSecurityEnhancer $enhancer;

    protected function setUp(): void
    {
        $this->enhancer = new BashSecurityEnhancer();
    }

    public function test_safe_command_passes_through(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::toolUse('call_1', 'bash', ['command' => 'ls -la']),
        ];
        $msg->stopReason = StopReason::ToolUse;

        $result = $this->enhancer->enhanceResponse($msg);

        $this->assertCount(1, $result->content);
        $this->assertSame('tool_use', $result->content[0]->type);
    }

    public function test_non_bash_tool_passes_through(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::toolUse('call_1', 'read_file', ['path' => '/etc/passwd']),
        ];
        $msg->stopReason = StopReason::ToolUse;

        $result = $this->enhancer->enhanceResponse($msg);

        $this->assertSame('tool_use', $result->content[0]->type);
    }

    public function test_text_only_message_passes_through(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hello')];
        $msg->stopReason = StopReason::EndTurn;

        $result = $this->enhancer->enhanceResponse($msg);

        $this->assertSame('Hello', $result->text());
    }

    public function test_command_substitution_blocked(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::toolUse('call_1', 'bash', ['command' => 'echo $(cat /etc/shadow)']),
        ];
        $msg->stopReason = StopReason::ToolUse;

        $result = $this->enhancer->enhanceResponse($msg);

        // Should be replaced with a text warning
        $this->assertSame('text', $result->content[0]->type);
        $this->assertStringContainsString('Bridge Security', $result->content[0]->text);
    }

    public function test_request_enhancement_is_noop(): void
    {
        $messages = [];
        $tools = [];
        $prompt = 'test';
        $options = [];

        $this->enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $this->assertSame('test', $prompt);
    }
}
