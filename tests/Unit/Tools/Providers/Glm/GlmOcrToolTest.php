<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Glm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\GlmProvider;
use SuperAgent\Tools\Providers\Glm\GlmOcrTool;

class GlmOcrToolTest extends TestCase
{
    public function test_rejects_missing_inputs(): void
    {
        $this->assertTrue($this->make([])->execute([])->isError);
    }

    public function test_url_mode_sends_input_url(): void
    {
        $history = [];
        $tool = $this->makeWithHistory(
            [new Response(200, [], json_encode(['text' => 'extracted']))],
            $history,
        );
        $result = $tool->execute(['url' => 'https://example.com/doc.pdf']);
        $this->assertFalse($result->isError);
        $this->assertSame('extracted', $result->content['text']);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('https://example.com/doc.pdf', $body['input']['url']);
        $this->assertSame('glm-ocr', $body['model']);
    }

    public function test_base64_mode_sends_input_base64(): void
    {
        $history = [];
        $tool = $this->makeWithHistory(
            [new Response(200, [], json_encode(['data' => ['text' => 'b64-text']]))],
            $history,
        );
        $result = $tool->execute(['base64' => 'aGVsbG8=']);
        $this->assertSame('b64-text', $result->content['text']);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('aGVsbG8=', $body['input']['base64']);
    }

    public function test_blocks_extracted_and_joined_when_text_missing(): void
    {
        $tool = $this->make([
            new Response(200, [], json_encode([
                'blocks' => [
                    ['text' => 'line1', 'type' => 'paragraph'],
                    ['text' => 'line2', 'type' => 'paragraph'],
                ],
            ])),
        ]);
        $result = $tool->execute(['url' => 'https://example.com/x']);
        $this->assertSame("line1\nline2", $result->content['text']);
        $this->assertCount(2, $result->content['blocks']);
    }

    private function make(array $responses): GlmOcrTool
    {
        $h = [];
        return $this->makeWithHistory($responses, $h);
    }

    private function makeWithHistory(array $responses, array &$history): GlmOcrTool
    {
        $provider = new GlmProvider(['api_key' => 'k']);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.z.ai/api/paas/v4/']);
        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) $ref = $ref->getParentClass();
        $p = $ref->getProperty('client');
        $p->setAccessible(true);
        $p->setValue($provider, $client);
        return new GlmOcrTool($provider);
    }
}
