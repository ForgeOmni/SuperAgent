<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\MiniMax;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\MiniMaxProvider;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxTtsTool;

class MiniMaxTtsToolTest extends TestCase
{
    public function test_attributes_are_network_and_cost(): void
    {
        $tool = $this->makeTool([]);
        $this->assertContains('network', $tool->attributes());
        $this->assertContains('cost', $tool->attributes());
    }

    public function test_rejects_missing_text_and_voice(): void
    {
        $tool = $this->makeTool([]);
        $this->assertTrue($tool->execute([])->isError);
        $this->assertTrue($tool->execute(['text' => 'hi'])->isError);
        $this->assertTrue($tool->execute(['voice_id' => 'v'])->isError);
    }

    public function test_rejects_overlong_text(): void
    {
        $tool = $this->makeTool([]);
        $result = $tool->execute([
            'text' => str_repeat('a', 10_001),
            'voice_id' => 'male-qn-qingse',
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('10 000', $result->contentAsString());
    }

    public function test_happy_path_url_response(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode([
                'data' => ['audio_url' => 'https://cdn.minimax/foo.mp3'],
                'trace_id' => 'trace_xyz',
            ])),
        ], $history);

        $result = $tool->execute([
            'text' => '你好世界',
            'voice_id' => 'male-qn-qingse',
            'speed' => 1.2,
        ]);

        $this->assertFalse($result->isError);
        $data = $result->content;
        $this->assertSame('https://cdn.minimax/foo.mp3', $data['audio']);
        $this->assertSame('url', $data['encoding']);
        $this->assertSame('mp3', $data['format']);
        $this->assertSame('trace_xyz', $data['trace_id']);

        // Request shape.
        $req = $history[0]['request'];
        $this->assertStringEndsWith('v1/t2a_v2', $req->getUri()->getPath());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('你好世界', $body['text']);
        $this->assertSame('male-qn-qingse', $body['voice_setting']['voice_id']);
        $this->assertSame(1.2, $body['voice_setting']['speed']);
        $this->assertSame('mp3', $body['audio_setting']['format']);
    }

    public function test_hex_audio_is_detected(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode([
                'data' => ['audio' => 'abcdef0123456789'],
            ])),
        ], $history);
        $result = $tool->execute(['text' => 'hi', 'voice_id' => 'v']);
        $this->assertSame('abcdef0123456789', $result->content['audio']);
        $this->assertSame('hex', $result->content['encoding']);
    }

    public function test_base64_audio_is_detected(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode([
                'data' => ['audio' => 'SGVsbG8gV29ybGQ='],  // not hex (has '=' and upper)
            ])),
        ], $history);
        $result = $tool->execute(['text' => 'hi', 'voice_id' => 'v']);
        $this->assertSame('base64', $result->content['encoding']);
    }

    public function test_missing_audio_payload_errors(): void
    {
        $history = [];
        $tool = $this->makeToolWithHistory([
            new Response(200, [], json_encode(['trace_id' => 'x'])),
        ], $history);
        $result = $tool->execute(['text' => 'hi', 'voice_id' => 'v']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('no audio', $result->contentAsString());
    }

    private function makeTool(array $responses): MiniMaxTtsTool
    {
        $history = [];
        return $this->makeToolWithHistory($responses, $history);
    }

    private function makeToolWithHistory(array $responses, array &$history): MiniMaxTtsTool
    {
        $provider = new MiniMaxProvider(['api_key' => 'sk-test']);

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.minimax.io/',
        ]);

        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return new MiniMaxTtsTool($provider);
    }
}
