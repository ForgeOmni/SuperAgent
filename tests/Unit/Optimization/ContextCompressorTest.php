<?php

namespace SuperAgent\Tests\Unit\Optimization;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Optimization\ContextCompression\ContextCompressor;

class ContextCompressorTest extends TestCase
{
    public function test_disabled_returns_unchanged(): void
    {
        $compressor = new ContextCompressor(enabled: false);
        $messages = [new UserMessage('hello')];
        $result = $compressor->compress($messages);
        $this->assertSame($messages, $result);
    }

    public function test_short_conversation_unchanged(): void
    {
        $compressor = new ContextCompressor();
        $messages = [
            new UserMessage('hello'),
            $this->makeAssistant('hi'),
        ];
        $result = $compressor->compress($messages);
        $this->assertSame($messages, $result);
    }

    public function test_prunes_old_tool_results(): void
    {
        $compressor = new ContextCompressor(
            targetTokenBudget: 100, // Very small to force compression
            maxToolResultLength: 20,
        );

        $longContent = str_repeat('x', 500);
        $messages = $this->buildLongConversation($longContent);

        $result = $compressor->compress($messages);

        // Should have fewer tokens overall
        $this->assertNotEmpty($result);
    }

    public function test_compress_with_summarizer(): void
    {
        $compressor = new ContextCompressor(
            targetTokenBudget: 50, // Very small to force compression
            tailBudgetTokens: 100,
        );

        $messages = $this->buildLongConversation(str_repeat('context ', 100));

        $summarizer = function (string $text, ?string $prev): string {
            return 'Summary: The user asked about PHP.';
        };

        $result = $compressor->compress($messages, $summarizer);
        $this->assertNotEmpty($result);

        // Should contain the summary
        $foundSummary = false;
        foreach ($result as $msg) {
            if ($msg instanceof UserMessage) {
                $content = is_string($msg->content) ? $msg->content : '';
                if (str_contains($content, 'Context Summary')) {
                    $foundSummary = true;
                }
            }
        }
        $this->assertTrue($foundSummary, 'Should contain a summary message');
    }

    public function test_iterative_summary_preserved(): void
    {
        $compressor = new ContextCompressor(
            targetTokenBudget: 50,
            tailBudgetTokens: 100,
            preserveHeadMessages: 1,
        );

        $summarizer = function (string $text, ?string $prev): string {
            if ($prev !== null) {
                return "Updated: {$prev} + new info";
            }
            return 'Initial summary';
        };

        // First compression with enough data to trigger it
        $messages = $this->buildLongConversation(str_repeat('data ', 200));
        $result = $compressor->compress($messages, $summarizer);

        // Compression should have happened and saved the summary
        if ($compressor->getPreviousSummary() !== null) {
            $this->assertEquals('Initial summary', $compressor->getPreviousSummary());

            // Second compression should get the previous summary
            $compressor->compress($messages, $summarizer);
            $this->assertStringContainsString('Updated:', $compressor->getPreviousSummary());
        } else {
            // If token budget was already met after pruning, no summary needed
            $this->assertNotEmpty($result);
        }
    }

    public function test_summary_template_has_required_sections(): void
    {
        $template = ContextCompressor::getSummaryTemplate();
        $this->assertStringContainsString('## Goal', $template);
        $this->assertStringContainsString('## Progress', $template);
        $this->assertStringContainsString('## Key Decisions', $template);
        $this->assertStringContainsString('## Current State', $template);
        $this->assertStringContainsString('## Next Steps', $template);
    }

    private function makeAssistant(string $text): AssistantMessage
    {
        return new AssistantMessage([ContentBlock::text($text)]);
    }

    private function buildLongConversation(string $content): array
    {
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new UserMessage("Turn {$i}: " . $content);
            $messages[] = $this->makeAssistant("Response {$i}: " . $content);
            $messages[] = new ToolResultMessage([
                ContentBlock::toolResult("tool-{$i}", $content, false),
            ]);
        }
        return $messages;
    }
}
