<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use SuperAgent\Enums\Role;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\OpenAIResponsesProvider;

/**
 * Wire-shape + SSE-parser pins for the Responses API path.
 * Both the buildRequestBody output and the event-typed SSE stream
 * have strict contracts — OpenAI will 400 on an extra top-level key
 * and silently discard events we don't handle.
 */
class OpenAIResponsesProviderTest extends TestCase
{
    // ---------------- buildRequestBody ----------------

    public function test_messages_map_to_input_with_instructions(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $body = $this->invokeBuild($p, [
            new UserMessage('Analyze the repo'),
        ], [], 'You are a precise analyst.', []);

        $this->assertSame('You are a precise analyst.', $body['instructions']);
        $this->assertCount(1, $body['input']);
        $this->assertSame('message', $body['input'][0]['type']);
        $this->assertSame('user', $body['input'][0]['role']);
        $this->assertSame('input_text', $body['input'][0]['content'][0]['type']);
        $this->assertSame('Analyze the repo', $body['input'][0]['content'][0]['text']);
        $this->assertTrue($body['stream']);
        $this->assertTrue($body['parallel_tool_calls']);
    }

    public function test_assistant_tool_use_becomes_function_call_item(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $msg = new AssistantMessage();
        $msg->content = [
            ContentBlock::text('Calling the tool'),
            ContentBlock::toolUse('call_abc', 'read_file', ['path' => '/tmp/a.md']),
        ];

        $body = $this->invokeBuild($p, [$msg], [], null, []);

        $this->assertCount(2, $body['input']);
        $this->assertSame('message', $body['input'][0]['type']);
        $this->assertSame('assistant', $body['input'][0]['role']);
        $this->assertSame('output_text', $body['input'][0]['content'][0]['type']);

        $this->assertSame('function_call', $body['input'][1]['type']);
        $this->assertSame('call_abc',   $body['input'][1]['call_id']);
        $this->assertSame('read_file',  $body['input'][1]['name']);
        $this->assertSame('{"path":"\/tmp\/a.md"}', $body['input'][1]['arguments']);
    }

    public function test_tool_result_becomes_function_call_output(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $tr = ToolResultMessage::fromResult('call_abc', 'file contents', false);

        $body = $this->invokeBuild($p, [$tr], [], null, []);

        $this->assertCount(1, $body['input']);
        $this->assertSame('function_call_output', $body['input'][0]['type']);
        $this->assertSame('call_abc', $body['input'][0]['call_id']);
        $this->assertSame('file contents', $body['input'][0]['output']);
    }

    public function test_reasoning_flat_shape_passed_through(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'reasoning' => ['effort' => 'high', 'summary' => 'auto'],
        ]);
        $this->assertSame(['effort' => 'high', 'summary' => 'auto'], $body['reasoning']);
    }

    public function test_reasoning_budget_tokens_mapped_to_effort_tier(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'features' => ['thinking' => ['budget_tokens' => 500]],
        ]);
        $this->assertSame('low', $body['reasoning']['effort']);

        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'features' => ['thinking' => ['budget_tokens' => 4000]],
        ]);
        $this->assertSame('medium', $body['reasoning']['effort']);

        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'features' => ['thinking' => ['budget_tokens' => 10000]],
        ]);
        $this->assertSame('high', $body['reasoning']['effort']);
    }

    public function test_verbosity_and_json_schema_map_to_text_controls(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'verbosity'       => 'low',
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'Out',
                    'schema' => ['type' => 'object'],
                    'strict' => true,
                ],
            ],
        ]);
        $this->assertSame('low', $body['text']['verbosity']);
        $this->assertSame('json_schema', $body['text']['format']['type']);
        $this->assertSame('Out', $body['text']['format']['name']);
        $this->assertTrue($body['text']['format']['strict']);
    }

    public function test_prompt_cache_key_both_shapes_accepted(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $b1 = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'prompt_cache_key' => 'session-abc',
        ]);
        $this->assertSame('session-abc', $b1['prompt_cache_key']);

        $b2 = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'features' => ['prompt_cache_key' => ['session_id' => 'feature-xyz']],
        ]);
        $this->assertSame('feature-xyz', $b2['prompt_cache_key']);
    }

    public function test_previous_response_id_from_options(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'previous_response_id' => 'resp_123',
        ]);
        $this->assertSame('resp_123', $body['previous_response_id']);
    }

    public function test_store_defaults_false_opt_in_via_options(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, []);
        $this->assertFalse($body['store']);

        $body2 = $this->invokeBuild($p, [new UserMessage('hi')], [], null, ['store' => true]);
        $this->assertTrue($body2['store']);
    }

    public function test_tools_have_flat_shape_no_function_wrapper(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $tool = new class extends \SuperAgent\Tools\Tool {
            public function name(): string { return 'my_tool'; }
            public function description(): string { return 'A demo tool'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => (object) []]; }
            public function execute(array $input): \SuperAgent\Tools\ToolResult {
                return \SuperAgent\Tools\ToolResult::success([]);
            }
        };

        $body = $this->invokeBuild($p, [new UserMessage('hi')], [$tool], null, []);

        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('my_tool', $body['tools'][0]['name']);
        $this->assertArrayHasKey('description', $body['tools'][0]);
        $this->assertArrayHasKey('parameters', $body['tools'][0]);
        // Pin: NOT wrapped in `function: {...}` — that's the Chat
        // Completions shape.
        $this->assertArrayNotHasKey('function', $body['tools'][0]);
    }

    // ---------------- SSE parser ----------------

    public function test_sse_text_delta_and_completed(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $sse = <<<SSE
event: response.created
data: {"type":"response.created","response":{"id":"resp_123"}}

event: response.output_text.delta
data: {"type":"response.output_text.delta","delta":"Hel"}

event: response.output_text.delta
data: {"type":"response.output_text.delta","delta":"lo"}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_123","usage":{"input_tokens":12,"output_tokens":2}}}

SSE;

        $result = $this->runParser($p, $sse);

        $this->assertSame('Hello', $result->text());
        $this->assertSame(StopReason::EndTurn, $result->stopReason);
        $this->assertNotNull($result->usage);
        $this->assertSame(12, $result->usage->inputTokens);
        $this->assertSame(2, $result->usage->outputTokens);
        $this->assertSame('resp_123', $p->lastResponseId());
    }

    public function test_sse_function_call_via_output_item_done(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $sse = <<<SSE
event: response.created
data: {"type":"response.created","response":{"id":"resp_456"}}

event: response.output_item.done
data: {"type":"response.output_item.done","item":{"type":"function_call","call_id":"call_xyz","name":"read_file","arguments":"{\"path\":\"/tmp/a.md\"}"}}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_456","usage":{"input_tokens":1,"output_tokens":1}}}

SSE;

        $result = $this->runParser($p, $sse);

        $this->assertSame(StopReason::ToolUse, $result->stopReason);
        $tu = null;
        foreach ($result->content as $b) {
            if ($b->type === 'tool_use') { $tu = $b; break; }
        }
        $this->assertNotNull($tu);
        $this->assertSame('call_xyz', $tu->toolUseId);
        $this->assertSame('read_file', $tu->toolName);
        $this->assertSame(['path' => '/tmp/a.md'], $tu->toolInput);
    }

    public function test_sse_response_failed_throws_classified(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $sse = <<<SSE
event: response.failed
data: {"type":"response.failed","response":{"error":{"code":"context_length_exceeded","message":"prompt too long"}}}

SSE;

        $this->expectException(\SuperAgent\Exceptions\Provider\ContextWindowExceededException::class);
        $this->runParser($p, $sse);
    }

    public function test_cached_tokens_propagated_from_responses_shape(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);

        $sse = <<<SSE
event: response.completed
data: {"type":"response.completed","response":{"id":"r","usage":{"input_tokens":100,"output_tokens":5,"input_tokens_details":{"cached_tokens":80}}}}

SSE;

        $result = $this->runParser($p, $sse);

        $this->assertSame(80, $result->usage->cacheReadInputTokens);
    }

    // ---------------- client_metadata / trace context ----------------

    public function test_trace_context_object_folds_into_client_metadata(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $tc = new \SuperAgent\Support\TraceContext(
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'vendor=abc',
        );
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'trace_context' => $tc,
        ]);
        $this->assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $body['client_metadata']['traceparent']);
        $this->assertSame('vendor=abc', $body['client_metadata']['tracestate']);
    }

    public function test_raw_traceparent_string_parsed(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'tracestate'  => 'rojo=00f067aa',
        ]);
        $this->assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $body['client_metadata']['traceparent']);
        $this->assertSame('rojo=00f067aa', $body['client_metadata']['tracestate']);
    }

    public function test_invalid_traceparent_dropped_silently(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'traceparent' => 'not-a-trace-parent',
        ]);
        $this->assertArrayNotHasKey('client_metadata', $body);
    }

    public function test_user_metadata_merged_with_trace_context(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $body = $this->invokeBuild($p, [new UserMessage('hi')], [], null, [
            'client_metadata' => ['job_id' => 'q-42'],
            'traceparent'     => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);
        $this->assertSame('q-42', $body['client_metadata']['job_id']);
        $this->assertArrayHasKey('traceparent', $body['client_metadata']);
    }

    // ---------------- ChatGPT OAuth routing ----------------

    public function test_api_key_mode_uses_api_openai_com(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-test']);
        $this->assertSame('https://api.openai.com/v1/responses', $this->readRequestUrl($p));
    }

    public function test_oauth_mode_routes_to_chatgpt_backend(): void
    {
        $p = new OpenAIResponsesProvider([
            'access_token' => 'ca_chatgpt_oauth_token',
            'account_id'   => 'acct_abc',
        ]);
        // ChatGPT backend uses /responses (no v1 prefix) and hosts on
        // chatgpt.com — codex-rs does the exact same mapping.
        $this->assertSame('https://chatgpt.com/backend-api/codex/responses', $this->readRequestUrl($p));
    }

    public function test_chatgpt_account_id_header_on_oauth(): void
    {
        $p = new OpenAIResponsesProvider([
            'access_token' => 'ca_chatgpt_oauth_token',
            'account_id'   => 'acct_abc',
        ]);
        $headers = $this->readClientHeaders($p);
        $this->assertSame('acct_abc', $headers['chatgpt-account-id']);
    }

    // ---------------- Experimental WS flag ----------------

    public function test_experimental_ws_transport_flag_raises(): void
    {
        // Scaffold-only: the option is recognised so a future SDK
        // release can flip on WS without a caller-side change, but
        // today it fails loud rather than silently downgrading.
        $this->expectException(\SuperAgent\Exceptions\FeatureNotSupportedException::class);
        $this->expectExceptionMessageMatches('/experimental_ws_transport/');
        new OpenAIResponsesProvider([
            'api_key'                     => 'sk-test',
            'experimental_ws_transport'   => true,
        ]);
    }

    public function test_experimental_ws_transport_off_works_normally(): void
    {
        $p = new OpenAIResponsesProvider([
            'api_key'                     => 'sk-test',
            'experimental_ws_transport'   => false,
        ]);
        $this->assertSame('https://api.openai.com/v1/responses', $this->readRequestUrl($p));
    }

    // ---------------- Azure detection ----------------

    public function test_azure_base_url_triggers_deployment_path(): void
    {
        $p = new OpenAIResponsesProvider([
            'api_key'  => 'azure-key',
            'base_url' => 'https://my-resource.openai.azure.com/openai/deployments/gpt-5',
        ]);
        $this->assertStringStartsWith(
            'https://my-resource.openai.azure.com/openai/deployments/gpt-5/openai/responses?api-version=',
            $this->readRequestUrl($p),
        );
    }

    public function test_azure_variants_detected(): void
    {
        $bases = [
            'https://foo.openai.azure.com/openai',
            'https://foo.openai.azure.us/openai/deployments/bar',
            'https://foo.cognitiveservices.azure.cn/openai',
            'https://foo.aoai.azure.com/openai',
            'https://foo.openai.azure-api.net/openai',
            'https://foo.z01.azurefd.net/',
        ];
        foreach ($bases as $b) {
            $p = new OpenAIResponsesProvider(['api_key' => 'k', 'base_url' => $b]);
            $this->assertStringContainsString('api-version=', $this->readRequestUrl($p), "Azure not detected for {$b}");
        }
    }

    public function test_api_key_header_on_azure(): void
    {
        $p = new OpenAIResponsesProvider([
            'api_key'  => 'azure-secret',
            'base_url' => 'https://foo.openai.azure.com/openai',
        ]);
        $headers = $this->readClientHeaders($p);
        $this->assertSame('azure-secret', $headers['api-key']);
        // Bearer still present for portability.
        $this->assertSame('Bearer azure-secret', $headers['Authorization']);
    }

    public function test_custom_azure_api_version(): void
    {
        $p = new OpenAIResponsesProvider([
            'api_key'            => 'k',
            'base_url'           => 'https://foo.openai.azure.com/openai',
            'azure_api_version'  => '2024-12-01-preview',
        ]);
        $this->assertStringContainsString(
            'api-version=2024-12-01-preview',
            $this->readRequestUrl($p),
        );
    }

    public function test_non_azure_base_url_unaffected(): void
    {
        $p = new OpenAIResponsesProvider([
            'api_key'  => 'k',
            'base_url' => 'https://proxy.example.com/openai',
        ]);
        $this->assertStringEndsWith('/v1/responses', $this->readRequestUrl($p));
        $this->assertStringNotContainsString('api-version=', $this->readRequestUrl($p));
    }

    // ---------------- Helpers ----------------

    private function readRequestUrl(OpenAIResponsesProvider $p): string
    {
        $rc = new \ReflectionClass($p);
        $cp = $rc->getProperty('client');
        $cp->setAccessible(true);
        /** @var \GuzzleHttp\Client $client */
        $client = $cp->getValue($p);

        $configProp = (new \ReflectionClass($client))->getProperty('config');
        $configProp->setAccessible(true);
        $cfg = $configProp->getValue($client);

        $base = (string) $cfg['base_uri'];
        $m = $rc->getMethod('chatCompletionsPath');
        $m->setAccessible(true);
        $path = (string) $m->invoke($p);

        return rtrim($base, '/') . '/' . $path;
    }

    private function readClientHeaders(OpenAIResponsesProvider $p): array
    {
        $rc = new \ReflectionClass($p);
        $cp = $rc->getProperty('client');
        $cp->setAccessible(true);
        /** @var \GuzzleHttp\Client $client */
        $client = $cp->getValue($p);

        $configProp = (new \ReflectionClass($client))->getProperty('config');
        $configProp->setAccessible(true);
        $cfg = $configProp->getValue($client);

        return $cfg['headers'] ?? [];
    }



    /**
     * @param \SuperAgent\Messages\Message[] $messages
     * @param \SuperAgent\Tools\Tool[]       $tools
     */
    private function invokeBuild(
        OpenAIResponsesProvider $p,
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        $rc = new \ReflectionClass($p);
        $m = $rc->getMethod('buildRequestBody');
        $m->setAccessible(true);
        return $m->invoke($p, $messages, $tools, $systemPrompt, $options);
    }

    private function runParser(OpenAIResponsesProvider $p, string $sse): AssistantMessage
    {
        $stream = Utils::streamFor($sse);

        $rc = new \ReflectionClass($p);
        $m = $rc->getMethod('parseResponsesSseStream');
        $m->setAccessible(true);

        $gen = $m->invoke($p, $stream, null);
        $final = null;
        foreach ($gen as $msg) {
            $final = $msg;
        }
        $this->assertInstanceOf(AssistantMessage::class, $final);
        return $final;
    }
}
