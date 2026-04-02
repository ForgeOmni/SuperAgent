<?php

namespace SuperAgent\Tests\Unit\Bridge\Enhancers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\Enhancers\ContextCompactionEnhancer;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

class ContextCompactionEnhancerTest extends TestCase
{
    public function test_short_conversation_not_compacted(): void
    {
        $enhancer = new ContextCompactionEnhancer(keepRecent: 5, minMessages: 10);

        $messages = [
            new UserMessage('Hi'),
            new UserMessage('Hello'),
        ];

        $tools = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $this->assertCount(2, $messages);
    }

    public function test_old_large_tool_results_truncated(): void
    {
        $enhancer = new ContextCompactionEnhancer(
            keepRecent: 2,
            maxToolResultChars: 50,
            minMessages: 3,
        );

        $largeContent = str_repeat('x', 200);
        $messages = [
            ToolResultMessage::fromResult('call_1', $largeContent),
            new UserMessage('msg2'),
            new UserMessage('msg3'),
            new UserMessage('msg4 (recent)'),
            new UserMessage('msg5 (recent)'),
        ];

        $tools = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        // First message (old tool result) should be truncated
        $this->assertInstanceOf(ToolResultMessage::class, $messages[0]);
        $content = $messages[0]->content[0]->content;
        $this->assertStringContainsString('truncated by bridge compaction', $content);
        $this->assertLessThan(200, strlen($content));
    }

    public function test_recent_messages_preserved(): void
    {
        $enhancer = new ContextCompactionEnhancer(
            keepRecent: 2,
            maxToolResultChars: 50,
            minMessages: 3,
        );

        $largeContent = str_repeat('y', 200);
        $messages = [
            new UserMessage('old'),
            new UserMessage('old2'),
            new UserMessage('old3'),
            ToolResultMessage::fromResult('call_recent', $largeContent), // recent
            new UserMessage('last (recent)'),
        ];

        $tools = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        // The recent tool result (index 3) should NOT be truncated
        $this->assertInstanceOf(ToolResultMessage::class, $messages[3]);
        $this->assertSame($largeContent, $messages[3]->content[0]->content);
    }
}
