<?php

namespace SuperAgent\Tests\Unit\Bridge\Enhancers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\Enhancers\ToolSummaryEnhancer;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

class ToolSummaryEnhancerTest extends TestCase
{
    public function test_short_results_not_truncated(): void
    {
        $enhancer = new ToolSummaryEnhancer(keepRecent: 2, maxChars: 100);

        $messages = [
            ToolResultMessage::fromResult('call_1', 'short result'),
            new UserMessage('msg2'),
            new UserMessage('msg3'),
            new UserMessage('recent1'),
            new UserMessage('recent2'),
        ];

        $tools = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $this->assertSame('short result', $messages[0]->content[0]->content);
    }

    public function test_old_large_results_truncated(): void
    {
        $enhancer = new ToolSummaryEnhancer(keepRecent: 2, maxChars: 50, keepLines: 3);

        $longContent = "line1\nline2\nline3\nline4\nline5\nline6\n" . str_repeat('x', 200);
        $messages = [
            ToolResultMessage::fromResult('call_1', $longContent),
            new UserMessage('msg2'),
            new UserMessage('msg3'),
            new UserMessage('recent1'),
            new UserMessage('recent2'),
        ];

        $tools = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $content = $messages[0]->content[0]->content;
        $this->assertStringContainsString('line1', $content);
        $this->assertStringContainsString('line2', $content);
        $this->assertStringContainsString('line3', $content);
        $this->assertStringContainsString('truncated by bridge', $content);
        $this->assertStringNotContainsString('line6', $content);
    }

    public function test_recent_results_preserved(): void
    {
        $enhancer = new ToolSummaryEnhancer(keepRecent: 1, maxChars: 50);

        $longContent = str_repeat('z', 200);
        $messages = [
            new UserMessage('old'),
            new UserMessage('old2'),
            ToolResultMessage::fromResult('call_recent', $longContent), // recent
        ];

        $tools = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        // Recent tool result should not be truncated
        $this->assertSame($longContent, $messages[2]->content[0]->content);
    }
}
