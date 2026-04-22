<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\MCP;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\Client;
use SuperAgent\MCP\MCPTool;
use SuperAgent\MCP\Types\ServerConfig;
use SuperAgent\MCP\Types\Tool as McpToolType;
use SuperAgent\Providers\ChatCompletionsProvider;
use SuperAgent\Providers\GlmProvider;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\MiniMaxProvider;
use SuperAgent\Providers\QwenProvider;

/**
 * Verifies the Phase 5 acceptance criterion: an `MCPTool` instance must
 * translate through every native-chat provider's `formatTools()` pipeline
 * identically to a plain local Tool. This is what makes the mixed-
 * invocation design work — any main brain can invoke any MCP server
 * because the MCP wrapper is just another Tool to the providers.
 */
class CrossProviderToolFormatTest extends TestCase
{
    public function test_mcp_tool_implements_the_standard_tool_contract(): void
    {
        $tool = $this->makeMcpTool();
        $this->assertInstanceOf(\SuperAgent\Tools\Tool::class, $tool);
        $this->assertSame('mcp_fs_read_file', $tool->name());
        $this->assertIsArray($tool->inputSchema());
    }

    /**
     * @dataProvider chatCompletionsProviderNames
     */
    public function test_chat_completions_providers_format_mcp_tool_as_function(string $class): void
    {
        /** @var ChatCompletionsProvider $provider */
        $provider = new $class(['api_key' => 'k']);
        $tool = $this->makeMcpTool();

        $formatted = $provider->formatTools([$tool]);
        $this->assertCount(1, $formatted);
        $this->assertSame('function', $formatted[0]['type']);
        $this->assertSame('mcp_fs_read_file', $formatted[0]['function']['name']);
        $this->assertIsArray($formatted[0]['function']['parameters']);
    }

    public static function chatCompletionsProviderNames(): array
    {
        return [
            'Kimi'    => [KimiProvider::class],
            'GLM'     => [GlmProvider::class],
            'MiniMax' => [MiniMaxProvider::class],
        ];
    }

    public function test_qwen_native_provider_formats_mcp_tool_too(): void
    {
        $provider = new QwenProvider(['api_key' => 'k']);
        $formatted = $provider->formatTools([$this->makeMcpTool()]);
        $this->assertCount(1, $formatted);
        $this->assertSame('function', $formatted[0]['type']);
        $this->assertSame('mcp_fs_read_file', $formatted[0]['function']['name']);
    }

    private function makeMcpTool(): MCPTool
    {
        $mcpType = new McpToolType(
            name: 'read_file',
            description: 'Read a file from the local filesystem',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                ],
                'required' => ['path'],
            ],
        );

        $client = $this->makeDummyClient();
        return new MCPTool($client, 'fs', $mcpType);
    }

    private function makeDummyClient(): Client
    {
        $config = ServerConfig::stdio('fs', '/bin/true', []);
        // Transport is never driven in these tests — all we exercise is
        // the Tool interface surface (name / schema / format translation).
        $transport = new class implements \SuperAgent\MCP\Contracts\Transport {
            public function connect(): void {}
            public function disconnect(): void {}
            public function isConnected(): bool { return false; }
            public function send(array $message): void {}
            public function receive(): ?array { return null; }
            public function onMessage(callable $callback): void {}
            public function onError(callable $callback): void {}
            public function onClose(callable $callback): void {}
        };
        return new Client($config, $transport);
    }
}
