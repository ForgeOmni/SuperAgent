<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\MiniMax;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\MiniMaxProvider;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxMusicTool;

class MiniMaxMusicToolTest extends TestCase
{
    public function test_rejects_missing_prompt(): void
    {
        $this->assertTrue($this->make([])->execute([])->isError);
        $this->assertTrue($this->make([])->execute(['prompt' => '   '])->isError);
    }

    public function test_inline_audio_short_circuits_polling(): void
    {
        $history = [];
        $tool = $this->makeWithHistory([
            new Response(200, [], json_encode([
                'audio_url' => 'https://cdn.minimax/music/1.mp3',
                'trace_id' => 't1',
            ])),
        ], $history);

        $result = $tool->execute([
            'prompt' => 'upbeat electronic',
            'lyrics' => 'la la la',
            'instrumental' => false,
        ]);

        $this->assertFalse($result->isError);
        $this->assertSame('https://cdn.minimax/music/1.mp3', $result->content['audio']);
        $this->assertSame('url', $result->content['encoding']);
        $this->assertSame('t1', $result->content['trace_id']);
        $this->assertCount(1, $history);  // no poll call

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('upbeat electronic', $body['prompt']);
        $this->assertSame('la la la', $body['lyrics']);
        $this->assertFalse($body['instrumental']);
    }

    public function test_async_task_id_is_polled_and_returns_audio(): void
    {
        $tool = $this->make([
            // Submit response — no audio, only task_id.
            new Response(200, [], json_encode(['task_id' => 'task_xyz'])),
            // First probe — audio present via query endpoint.
            new Response(200, [], json_encode([
                'status' => 'success',
                'data' => ['audio_url' => 'https://cdn/music/final.mp3'],
            ])),
        ]);
        $result = $tool->execute(['prompt' => 'calm piano']);
        $this->assertFalse($result->isError);
        $this->assertSame('https://cdn/music/final.mp3', $result->content['audio']);
        $this->assertSame('task_xyz', $result->content['task_id']);
    }

    public function test_submit_without_audio_or_task_id_errors(): void
    {
        $tool = $this->make([
            new Response(200, [], json_encode(['weird' => true])),
        ]);
        $this->assertTrue($tool->execute(['prompt' => 'x'])->isError);
    }

    private function make(array $responses): MiniMaxMusicTool
    {
        $h = [];
        return $this->makeWithHistory($responses, $h);
    }

    private function makeWithHistory(array $responses, array &$history): MiniMaxMusicTool
    {
        $provider = new MiniMaxProvider(['api_key' => 'k']);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.minimax.io/']);
        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) $ref = $ref->getParentClass();
        $p = $ref->getProperty('client');
        $p->setAccessible(true);
        $p->setValue($provider, $client);
        return new MiniMaxMusicTool($provider);
    }
}
