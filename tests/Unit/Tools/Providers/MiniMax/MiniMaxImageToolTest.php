<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\MiniMax;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\MiniMaxProvider;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxImageTool;

class MiniMaxImageToolTest extends TestCase
{
    public function test_rejects_missing_prompt(): void
    {
        $this->assertTrue($this->make([])->execute([])->isError);
    }

    public function test_url_images_returned(): void
    {
        $tool = $this->make([
            new Response(200, [], json_encode([
                'data' => [
                    ['url' => 'https://cdn/img/1.png'],
                    ['url' => 'https://cdn/img/2.png'],
                ],
            ])),
        ]);
        $result = $tool->execute(['prompt' => 'a cat', 'n' => 2]);
        $this->assertFalse($result->isError);
        $this->assertCount(2, $result->content['images']);
        $this->assertSame('https://cdn/img/1.png', $result->content['images'][0]['url']);
    }

    public function test_base64_images_returned(): void
    {
        $tool = $this->make([
            new Response(200, [], json_encode([
                'images' => [['b64_json' => 'SGVsbG8=']],
            ])),
        ]);
        $result = $tool->execute(['prompt' => 'x']);
        $this->assertSame('SGVsbG8=', $result->content['images'][0]['base64']);
    }

    public function test_n_is_clamped(): void
    {
        $history = [];
        $tool = $this->makeWithHistory(
            [new Response(200, [], json_encode(['data' => [['url' => 'https://x/1']]]))],
            $history,
        );
        $tool->execute(['prompt' => 'x', 'n' => 99]);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(4, $body['n']);
    }

    public function test_empty_response_errors(): void
    {
        $tool = $this->make([
            new Response(200, [], json_encode(['data' => []])),
        ]);
        $this->assertTrue($tool->execute(['prompt' => 'x'])->isError);
    }

    private function make(array $responses): MiniMaxImageTool
    {
        $h = [];
        return $this->makeWithHistory($responses, $h);
    }

    private function makeWithHistory(array $responses, array &$history): MiniMaxImageTool
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
        return new MiniMaxImageTool($provider);
    }
}
