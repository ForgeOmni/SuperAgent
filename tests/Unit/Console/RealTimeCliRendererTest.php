<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use SuperAgent\Console\Output\RealTimeCliRenderer;
use SuperAgent\Harness\AgentCompleteEvent;
use SuperAgent\Harness\TextDeltaEvent;
use SuperAgent\Harness\ThinkingDeltaEvent;
use SuperAgent\Harness\ToolCompletedEvent;
use SuperAgent\Harness\ToolStartedEvent;
use SuperAgent\Harness\TurnCompleteEvent;
use SuperAgent\Messages\AssistantMessage;
use Symfony\Component\Console\Output\BufferedOutput;

class RealTimeCliRendererTest extends TestCase
{
    private function renderer(string $mode = RealTimeCliRenderer::THINKING_NORMAL): array
    {
        $buf = new BufferedOutput();
        $r = new RealTimeCliRenderer($buf, decorated: false, thinkingMode: $mode);

        return [$r, $buf];
    }

    public function test_thinking_normal_collapses_to_first_line_preview(): void
    {
        [$r, $buf] = $this->renderer(RealTimeCliRenderer::THINKING_NORMAL);

        $r->handle(new ThinkingDeltaEvent("User wants a refactor\nI should check the middleware first"));
        $r->handle(new TextDeltaEvent('OK — starting.'));

        $out = $buf->fetch();
        self::assertStringContainsString('✻ Thinking…', $out);
        self::assertStringContainsString('╰ User wants a refactor', $out);
        self::assertStringContainsString('OK — starting.', $out);
    }

    public function test_thinking_hidden_emits_nothing_for_thinking(): void
    {
        [$r, $buf] = $this->renderer(RealTimeCliRenderer::THINKING_HIDDEN);

        $r->handle(new ThinkingDeltaEvent('secret reasoning'));
        $r->handle(new TextDeltaEvent('visible reply'));

        $out = $buf->fetch();
        self::assertStringNotContainsString('Thinking', $out);
        self::assertStringNotContainsString('secret reasoning', $out);
        self::assertStringContainsString('visible reply', $out);
    }

    public function test_thinking_verbose_streams_every_delta(): void
    {
        [$r, $buf] = $this->renderer(RealTimeCliRenderer::THINKING_VERBOSE);

        $r->handle(new ThinkingDeltaEvent('alpha '));
        $r->handle(new ThinkingDeltaEvent('beta'));
        $r->handle(new TextDeltaEvent('done'));

        $out = $buf->fetch();
        self::assertStringContainsString('alpha beta', $out);
        self::assertStringContainsString('done', $out);
    }

    public function test_tool_start_and_complete_render_bullets_and_summary(): void
    {
        [$r, $buf] = $this->renderer();

        $r->handle(new ToolStartedEvent(
            toolName: 'Read',
            toolUseId: 'tu_1',
            toolInput: ['file_path' => '/src/Foo.php'],
        ));
        $r->handle(new ToolCompletedEvent(
            toolName: 'Read',
            toolUseId: 'tu_1',
            output: "line1\nline2\nline3",
        ));

        $out = $buf->fetch();
        self::assertStringContainsString('● Read(/src/Foo.php)', $out);
        self::assertStringContainsString('⎿', $out);
        self::assertStringContainsString('3 lines', $out);
    }

    public function test_tool_error_renders_with_cross(): void
    {
        [$r, $buf] = $this->renderer();

        $r->handle(new ToolStartedEvent('Bash', 'tu_2', ['command' => 'false']));
        $r->handle(new ToolCompletedEvent('Bash', 'tu_2', 'command failed', isError: true));

        $out = $buf->fetch();
        self::assertStringContainsString('● Bash(false)', $out);
        self::assertStringContainsString('✗', $out);
    }

    public function test_turn_complete_accumulates_tokens_into_footer(): void
    {
        [$r, $buf] = $this->renderer();

        $r->handle(new TurnCompleteEvent(
            message: new AssistantMessage(),
            turnNumber: 1,
            usage: ['input_tokens' => 1200, 'output_tokens' => 300],
        ));
        $r->handle(new TurnCompleteEvent(
            message: new AssistantMessage(),
            turnNumber: 2,
            usage: ['input_tokens' => 500, 'output_tokens' => 200],
        ));
        $r->handle(new AgentCompleteEvent(totalTurns: 2, totalCostUsd: 0.0123));

        $out = $buf->fetch();
        self::assertStringContainsString('2 turns', $out);
        self::assertStringContainsString('1.7K in', $out);
        self::assertStringContainsString('500 out', $out);
        self::assertStringContainsString('$0.0123', $out);
    }

    public function test_decorated_mode_adds_ansi_codes(): void
    {
        $buf = new BufferedOutput();
        $r = new RealTimeCliRenderer($buf, decorated: true);
        $r->handle(new ToolStartedEvent('Read', 'id1', ['file_path' => '/x']));

        self::assertStringContainsString("\033[", $buf->fetch());
    }
}
