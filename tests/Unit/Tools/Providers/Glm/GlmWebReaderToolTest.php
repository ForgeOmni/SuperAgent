<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Glm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\GlmProvider;
use SuperAgent\Tools\Providers\Glm\GlmWebReaderTool;

class GlmWebReaderToolTest extends TestCase
{
    public function test_attributes_are_network_and_cost(): void
    {
        $tool = $this->makeTool([]);
        $this->assertContains('network', $tool->attributes());
        $this->assertContains('cost', $tool->attributes());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->makeTool([])->isReadOnly());
    }

    public function test_rejects_invalid_url(): void
    {
        $tool = $this->makeTool([]);
        $this->assertTrue($tool->execute(['url' => 'not-a-url'])->isError);
        $this->assertTrue($tool->execute([])->isError);
    }

    public function test_request_body_has_url_and_format(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode(['content' => '# Hello'])),
        ], $history);

        $result = $tool->execute([
            'url' => 'https://example.com/article',
            'format' => 'markdown',
        ]);

        $this->assertFalse($result->isError);
        $this->assertSame('# Hello', $result->content['content']);
        $this->assertSame('markdown', $result->content['format']);

        $req = $history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('tools/web_reader', $req->getUri()->getPath());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('https://example.com/article', $body['url']);
        $this->assertSame('markdown', $body['output_format']);
    }

    public function test_invalid_format_falls_back_to_markdown(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode(['content' => ''])),
        ], $history);
        $tool->execute(['url' => 'https://example.com', 'format' => 'json']);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('markdown', $body['output_format']);
    }

    public function test_content_extraction_handles_nested_shapes(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode(['data' => ['content' => 'nested body']])),
        ], $history);
        $result = $tool->execute(['url' => 'https://example.com']);
        $this->assertSame('nested body', $result->content['content']);
    }

    public function test_unknown_response_returns_empty_content(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode(['weird' => 'shape'])),
        ], $history);
        $result = $tool->execute(['url' => 'https://example.com']);
        $this->assertFalse($result->isError);
        $this->assertSame('', $result->content['content']);
    }

    private function makeTool(array $responses): GlmWebReaderTool
    {
        $history = [];
        return $this->makeToolWithHistory($responses, $history);
    }

    private function makeToolWithHistory(array $responses, array &$history): GlmWebReaderTool
    {
        $provider = new GlmProvider(['api_key' => 'sk-test']);

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.z.ai/api/paas/v4/',
        ]);

        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return new GlmWebReaderTool($provider);
    }
}
