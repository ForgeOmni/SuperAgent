<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\ContentBlock;

class ContentBlockTest extends TestCase
{
    public function test_text_block(): void
    {
        $block = ContentBlock::text('hello');

        $this->assertSame('text', $block->type);
        $this->assertSame('hello', $block->text);
        $this->assertSame(['type' => 'text', 'text' => 'hello'], $block->toArray());
    }

    public function test_tool_use_block(): void
    {
        $block = ContentBlock::toolUse('id_1', 'get_weather', ['city' => 'Tokyo']);

        $this->assertSame('tool_use', $block->type);
        $this->assertSame('id_1', $block->toolUseId);
        $this->assertSame('get_weather', $block->toolName);
        $this->assertSame(['city' => 'Tokyo'], $block->toolInput);
        $this->assertSame([
            'type' => 'tool_use',
            'id' => 'id_1',
            'name' => 'get_weather',
            'input' => ['city' => 'Tokyo'],
        ], $block->toArray());
    }

    public function test_tool_result_block(): void
    {
        $block = ContentBlock::toolResult('id_1', 'sunny', false);

        $this->assertSame('tool_result', $block->type);
        $this->assertSame('id_1', $block->toolUseId);
        $this->assertSame('sunny', $block->content);
        $arr = $block->toArray();
        $this->assertSame('tool_result', $arr['type']);
        $this->assertSame('id_1', $arr['tool_use_id']);
        $this->assertSame('sunny', $arr['content']);
    }

    public function test_tool_result_error_block(): void
    {
        $block = ContentBlock::toolResult('id_2', 'fail', true);

        $arr = $block->toArray();
        $this->assertTrue($arr['is_error']);
    }

    public function test_thinking_block(): void
    {
        $block = ContentBlock::thinking('let me think...');

        $this->assertSame('thinking', $block->type);
        $this->assertSame('let me think...', $block->thinking);
        $this->assertSame(['type' => 'thinking', 'thinking' => 'let me think...'], $block->toArray());
    }
}
