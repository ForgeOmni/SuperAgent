<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

class ToolTest extends TestCase
{
    public function test_tool_result_success(): void
    {
        $result = ToolResult::success('done');

        $this->assertSame('done', $result->content);
        $this->assertFalse($result->isError);
        $this->assertSame('done', $result->contentAsString());
    }

    public function test_tool_result_error(): void
    {
        $result = ToolResult::error('something failed');

        $this->assertSame('something failed', $result->content);
        $this->assertTrue($result->isError);
    }

    public function test_tool_result_array_content(): void
    {
        $result = ToolResult::success(['key' => 'value']);

        $this->assertIsArray($result->content);
        $this->assertStringContainsString('"key"', $result->contentAsString());
    }

    public function test_closure_tool(): void
    {
        $tool = new ClosureTool(
            toolName: 'greet',
            toolDescription: 'Greet someone',
            toolInputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
                'required' => ['name'],
            ],
            handler: fn (array $input) => ToolResult::success("Hello, {$input['name']}!"),
            readOnly: true,
        );

        $this->assertSame('greet', $tool->name());
        $this->assertSame('Greet someone', $tool->description());
        $this->assertTrue($tool->isReadOnly());
        $this->assertArrayHasKey('properties', $tool->inputSchema());

        $result = $tool->execute(['name' => 'Xiyang']);
        $this->assertSame('Hello, Xiyang!', $result->content);
        $this->assertFalse($result->isError);
    }

    public function test_closure_tool_definition(): void
    {
        $tool = new ClosureTool(
            toolName: 'ping',
            toolDescription: 'Ping',
            toolInputSchema: ['type' => 'object', 'properties' => []],
            handler: fn (array $input) => ToolResult::success('pong'),
        );

        $def = $tool->toDefinition();
        $this->assertSame('ping', $def['name']);
        $this->assertSame('Ping', $def['description']);
        $this->assertArrayHasKey('type', $def['input_schema']);
    }
}
