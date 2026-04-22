<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Glm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\GlmProvider;
use SuperAgent\Tools\Providers\Glm\GlmWebSearchTool;

class GlmWebSearchToolTest extends TestCase
{
    public function test_attributes_are_network_and_cost(): void
    {
        $tool = $this->makeTool([]);
        $this->assertContains('network', $tool->attributes());
        $this->assertContains('cost', $tool->attributes());
        $this->assertNotContains('sensitive', $tool->attributes());
    }

    public function test_is_read_only(): void
    {
        $tool = $this->makeTool([]);
        $this->assertTrue($tool->isReadOnly());
    }

    public function test_rejects_empty_query(): void
    {
        $tool = $this->makeTool([]);
        $this->assertTrue($tool->execute(['query' => ''])->isError);
        $this->assertTrue($tool->execute([])->isError);
        $this->assertTrue($tool->execute(['query' => '   '])->isError);
    }

    public function test_parses_search_result_shape(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode([
                'search_result' => [
                    ['title' => 'A', 'url' => 'https://a.example', 'snippet' => 'alpha'],
                    ['title' => 'B', 'link' => 'https://b.example', 'content' => 'beta'],
                ],
            ])),
        ], $history);

        $result = $tool->execute(['query' => 'php 8.3 features', 'count' => 5, 'engine' => 'search_pro']);

        $this->assertFalse($result->isError);
        $data = $result->content;
        $this->assertSame('php 8.3 features', $data['query']);
        $this->assertCount(2, $data['hits']);
        $this->assertSame('A', $data['hits'][0]['title']);
        $this->assertSame('https://a.example', $data['hits'][0]['url']);
        $this->assertSame('alpha', $data['hits'][0]['snippet']);
        // `link` → `url` normalisation
        $this->assertSame('https://b.example', $data['hits'][1]['url']);
        // `content` → `snippet` normalisation
        $this->assertSame('beta', $data['hits'][1]['snippet']);

        // Verify request shape.
        $req = $history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('tools/web_search', $req->getUri()->getPath());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('php 8.3 features', $body['search_query']);
        $this->assertSame(5, $body['count']);
        $this->assertSame('search_pro', $body['search_engine']);
    }

    public function test_parses_hits_shape(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode([
                'hits' => [['title' => 'X', 'url' => 'https://x']],
            ])),
        ], $history);
        $result = $tool->execute(['query' => 'test']);
        $this->assertCount(1, $result->content['hits']);
    }

    public function test_unknown_response_shape_returns_empty_hits(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode(['weird' => 'shape'])),
        ], $history);
        $result = $tool->execute(['query' => 'test']);
        $this->assertFalse($result->isError);
        $this->assertSame([], $result->content['hits']);
    }

    public function test_count_is_clamped_to_valid_range(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode(['search_result' => []])),
        ], $history);
        $tool->execute(['query' => 'q', 'count' => 999]);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(50, $body['count']);
    }

    private function makeTool(array $responses): GlmWebSearchTool
    {
        $history = [];
        return $this->makeToolWithHistory($responses, $history);
    }

    /**
     * @param array<int, array<string, mixed>> $history captured by reference
     */
    private function makeToolWithHistory(array $responses, array &$history): GlmWebSearchTool
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

        return new GlmWebSearchTool($provider);
    }
}
