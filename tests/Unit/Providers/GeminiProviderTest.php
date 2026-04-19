<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\GeminiProvider;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Tool;

class GeminiProviderTest extends TestCase
{
    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/API key/');
        new GeminiProvider([]);
    }

    public function test_sets_x_goog_api_key_header(): void
    {
        $p = new GeminiProvider(['api_key' => 'AIzaSyTEST']);
        $headers = $this->clientHeaders($p);
        $this->assertSame('AIzaSyTEST', $headers['x-goog-api-key']);
    }

    public function test_default_model_is_gemini_flash(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $this->assertSame('gemini-2.0-flash', $p->getModel());
    }

    public function test_set_model_roundtrip(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $p->setModel('gemini-1.5-pro');
        $this->assertSame('gemini-1.5-pro', $p->getModel());
    }

    public function test_name_is_gemini(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $this->assertSame('gemini', $p->name());
    }

    public function test_format_tools_wraps_in_function_declarations(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $tool = new class extends Tool {
            public function name(): string { return 'search'; }
            public function description(): string { return 'Search the web'; }
            public function inputSchema(): array {
                return [
                    'type' => 'object',
                    'properties' => [
                        'q' => ['type' => 'string', 'description' => 'query'],
                    ],
                    'required' => ['q'],
                ];
            }
            public function execute(array $input): \SuperAgent\Tools\ToolResult
            {
                return new \SuperAgent\Tools\ToolResult('');
            }
        };

        $formatted = $p->formatTools([$tool]);
        $this->assertCount(1, $formatted);
        $this->assertArrayHasKey('functionDeclarations', $formatted[0]);
        $decls = $formatted[0]['functionDeclarations'];
        $this->assertSame('search', $decls[0]['name']);
        $this->assertSame('Search the web', $decls[0]['description']);
        $this->assertSame('object', $decls[0]['parameters']['type']);
        $this->assertSame('string', $decls[0]['parameters']['properties']['q']['type']);
        $this->assertSame(['q'], $decls[0]['parameters']['required']);
    }

    public function test_format_tools_strips_unsupported_schema_keywords(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $tool = new class extends Tool {
            public function name(): string { return 'x'; }
            public function description(): string { return 'd'; }
            public function inputSchema(): array {
                return [
                    '$schema' => 'http://json-schema.org/draft-07/schema#',
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'a' => [
                            'type' => 'string',
                            'examples' => ['ignored'],
                            'default' => 'x',
                        ],
                    ],
                ];
            }
            public function execute(array $input): \SuperAgent\Tools\ToolResult
            {
                return new \SuperAgent\Tools\ToolResult('');
            }
        };

        $params = $p->formatTools([$tool])[0]['functionDeclarations'][0]['parameters'];
        $this->assertArrayNotHasKey('$schema', $params);
        $this->assertArrayNotHasKey('additionalProperties', $params);
        $this->assertArrayNotHasKey('examples', $params['properties']['a']);
        $this->assertArrayNotHasKey('default', $params['properties']['a']);
    }

    public function test_format_tools_empty_properties_becomes_object_literal(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $tool = new class extends Tool {
            public function name(): string { return 'ping'; }
            public function description(): string { return 'p'; }
            public function inputSchema(): array {
                return ['type' => 'object', 'properties' => []];
            }
            public function execute(array $input): \SuperAgent\Tools\ToolResult
            {
                return new \SuperAgent\Tools\ToolResult('');
            }
        };

        $params = $p->formatTools([$tool])[0]['functionDeclarations'][0]['parameters'];
        // Empty properties must serialize as {} not [] so Gemini's parser accepts it
        $this->assertSame('{"type":"object","properties":{}}', json_encode($params));
    }

    public function test_format_tools_recurses_into_nested_schemas(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $tool = new class extends Tool {
            public function name(): string { return 'x'; }
            public function description(): string { return 'd'; }
            public function inputSchema(): array {
                return [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                '$schema' => 'drop-me',
                                'properties' => [
                                    'n' => ['type' => 'integer', 'minimum' => 0],
                                ],
                            ],
                        ],
                    ],
                ];
            }
            public function execute(array $input): \SuperAgent\Tools\ToolResult
            {
                return new \SuperAgent\Tools\ToolResult('');
            }
        };

        $params = $p->formatTools([$tool])[0]['functionDeclarations'][0]['parameters'];
        $itemsSchema = $params['properties']['items']['items'];
        $this->assertArrayNotHasKey('$schema', $itemsSchema);
        $this->assertSame(0, $itemsSchema['properties']['n']['minimum']);
    }

    public function test_format_messages_maps_roles_user_and_model(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $assistant = new AssistantMessage();
        $assistant->content[] = ContentBlock::text('Hi there');

        $contents = $p->formatMessages([
            new UserMessage('hello'),
            $assistant,
        ]);

        $this->assertSame('user', $contents[0]['role']);
        $this->assertSame('hello', $contents[0]['parts'][0]['text']);
        $this->assertSame('model', $contents[1]['role']);
        $this->assertSame('Hi there', $contents[1]['parts'][0]['text']);
    }

    public function test_format_messages_converts_tool_use_to_function_call(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $assistant = new AssistantMessage();
        $assistant->content[] = ContentBlock::toolUse('call_1', 'search', ['q' => 'cats']);

        $contents = $p->formatMessages([$assistant]);
        $this->assertSame('model', $contents[0]['role']);
        $call = $contents[0]['parts'][0]['functionCall'];
        $this->assertSame('search', $call['name']);
        $this->assertSame(['q' => 'cats'], $call['args']);
    }

    public function test_format_messages_resolves_tool_name_in_function_response(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $assistant = new AssistantMessage();
        $assistant->content[] = ContentBlock::toolUse('call_42', 'weather', ['city' => 'Tokyo']);
        $toolResult = ToolResultMessage::fromResult('call_42', '{"temp": 15}');

        $contents = $p->formatMessages([$assistant, $toolResult]);
        $response = $contents[1]['parts'][0]['functionResponse'];
        $this->assertSame('weather', $response['name'], 'name should be resolved from prior tool_use by id');
        $this->assertSame(15, $response['response']['temp']);
    }

    public function test_format_messages_wraps_non_json_tool_result_under_content_key(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $assistant = new AssistantMessage();
        $assistant->content[] = ContentBlock::toolUse('c1', 'echo', []);
        $toolResult = ToolResultMessage::fromResult('c1', 'plain text output');

        $response = $p->formatMessages([$assistant, $toolResult])[1]['parts'][0]['functionResponse']['response'];
        $this->assertSame('plain text output', $response['content']);
    }

    public function test_format_messages_marks_error_tool_results(): void
    {
        $p = new GeminiProvider(['api_key' => 'k']);
        $assistant = new AssistantMessage();
        $assistant->content[] = ContentBlock::toolUse('c1', 'fail', []);
        $toolResult = ToolResultMessage::fromResult('c1', 'boom', isError: true);

        $response = $p->formatMessages([$assistant, $toolResult])[1]['parts'][0]['functionResponse']['response'];
        $this->assertTrue($response['error']);
    }

    public function test_registry_has_gemini(): void
    {
        $this->assertTrue(ProviderRegistry::hasProvider('gemini'));
        $this->assertContains('gemini', ProviderRegistry::getProviders());

        $config = ProviderRegistry::getDefaultConfig('gemini');
        $this->assertArrayHasKey('model', $config);
        $this->assertArrayHasKey('max_tokens', $config);

        $caps = ProviderRegistry::getCapabilities('gemini');
        $this->assertTrue($caps['streaming']);
        $this->assertTrue($caps['tools']);
        $this->assertTrue($caps['vision']);
    }

    public function test_registry_create_requires_api_key(): void
    {
        $this->expectException(ProviderException::class);
        ProviderRegistry::create('gemini', []);
    }

    public function test_registry_create_returns_gemini_provider(): void
    {
        $p = ProviderRegistry::create('gemini', ['api_key' => 'k']);
        $this->assertInstanceOf(GeminiProvider::class, $p);
        $this->assertSame('gemini', $p->name());
    }

    private function clientHeaders(GeminiProvider $p): array
    {
        $r = new \ReflectionObject($p);
        while ($r && ! $r->hasProperty('client')) {
            $r = $r->getParentClass();
        }
        $prop = $r->getProperty('client');
        $prop->setAccessible(true);
        $client = $prop->getValue($p);

        $opts = method_exists($client, 'getConfig')
            ? $client->getConfig()
            : (function () use ($client) {
                $ro = new \ReflectionObject($client);
                $pp = $ro->getProperty('config');
                $pp->setAccessible(true);
                return $pp->getValue($client);
            })();
        $headers = $opts['headers'] ?? [];
        return array_change_key_case($headers, CASE_LOWER);
    }
}
