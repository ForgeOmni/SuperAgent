<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Glm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\GlmProvider;
use SuperAgent\Tools\Providers\Glm\GlmAsrTool;

class GlmAsrToolTest extends TestCase
{
    public function test_rejects_missing_file(): void
    {
        $this->assertTrue($this->make([])->execute([])->isError);
        $this->assertTrue($this->make([])->execute(['file_path' => '/nope.mp3'])->isError);
    }

    public function test_verbose_json_parsed(): void
    {
        $audio = $this->writeDummyAudio();
        try {
            $history = [];
            $tool = $this->makeWithHistory([
                new Response(200, [], json_encode([
                    'text' => 'hello world',
                    'language' => 'en',
                    'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'hello world']],
                ])),
            ], $history);

            $result = $tool->execute(['file_path' => $audio, 'language' => 'en']);
            $this->assertFalse($result->isError);
            $this->assertSame('hello world', $result->content['text']);
            $this->assertSame('en', $result->content['language']);
            $this->assertCount(1, $result->content['segments']);

            $req = $history[0]['request'];
            $this->assertStringEndsWith('audio/transcriptions', $req->getUri()->getPath());
            $this->assertStringStartsWith('multipart/form-data', $req->getHeaderLine('Content-Type'));
        } finally {
            @unlink($audio);
        }
    }

    public function test_srt_format_passes_through_raw_body(): void
    {
        $audio = $this->writeDummyAudio();
        try {
            $tool = $this->make([
                new Response(200, [], "1\n00:00:00,000 --> 00:00:02,000\nHello\n"),
            ]);
            $result = $tool->execute(['file_path' => $audio, 'response_format' => 'srt']);
            $this->assertStringContainsString('-->', $result->content['text']);
            $this->assertSame('srt', $result->content['format']);
            $this->assertSame([], $result->content['segments']);
        } finally {
            @unlink($audio);
        }
    }

    private function writeDummyAudio(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'glm_asr_') . '.mp3';
        file_put_contents($tmp, 'binary-audio-bytes');
        return $tmp;
    }

    private function make(array $responses): GlmAsrTool
    {
        $h = [];
        return $this->makeWithHistory($responses, $h);
    }

    private function makeWithHistory(array $responses, array &$history): GlmAsrTool
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
        return new GlmAsrTool($provider);
    }
}
