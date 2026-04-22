<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\MiniMax;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\MiniMaxProvider;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxVideoTool;

class MiniMaxVideoToolTest extends TestCase
{
    public function test_rejects_missing_prompt(): void
    {
        $this->assertTrue($this->make([])->execute([])->isError);
    }

    public function test_submitted_mode_skips_polling(): void
    {
        $history = [];
        $tool = $this->makeWithHistory(
            [new Response(200, [], json_encode(['task_id' => 'vid_task_1']))],
            $history,
        );
        $result = $tool->execute(['prompt' => 'a sunset', 'wait' => false]);
        $this->assertFalse($result->isError);
        $this->assertSame('vid_task_1', $result->content['task_id']);
        $this->assertSame('submitted', $result->content['status']);
        $this->assertCount(1, $history);  // no poll
    }

    public function test_sync_wait_returns_video_url(): void
    {
        $tool = $this->make([
            new Response(200, [], json_encode(['task_id' => 'vid_task_2'])),
            new Response(200, [], json_encode([
                'status' => 'success',
                'file' => ['url' => 'https://cdn/video/out.mp4'],
            ])),
        ]);
        $result = $tool->execute(['prompt' => 'a river']);
        $this->assertFalse($result->isError);
        $this->assertSame('https://cdn/video/out.mp4', $result->content['video']);
        $this->assertSame('url', $result->content['encoding']);
        $this->assertSame('vid_task_2', $result->content['task_id']);
    }

    public function test_i2v_mode_attaches_first_frame_url(): void
    {
        $history = [];
        $tool = $this->makeWithHistory(
            [new Response(200, [], json_encode(['task_id' => 't']))],
            $history,
        );
        $tool->execute([
            'prompt' => 'animate',
            'first_frame_url' => 'https://cdn/frame.jpg',
            'wait' => false,
        ]);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['url' => 'https://cdn/frame.jpg'], $body['first_frame_image']);
    }

    public function test_submit_failure_returns_error(): void
    {
        $tool = $this->make([
            new Response(200, [], json_encode(['status' => 'weird'])),
        ]);
        $this->assertTrue($tool->execute(['prompt' => 'x'])->isError);
    }

    private function make(array $responses): MiniMaxVideoTool
    {
        $h = [];
        return $this->makeWithHistory($responses, $h);
    }

    private function makeWithHistory(array $responses, array &$history): MiniMaxVideoTool
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
        return new MiniMaxVideoTool($provider);
    }
}
