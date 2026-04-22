<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Kimi;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Tools\Providers\Kimi\KimiFileExtractTool;

class KimiFileExtractToolTest extends TestCase
{
    public function test_attributes_declare_network_cost_sensitive(): void
    {
        $tool = $this->makeTool([]);
        $attrs = $tool->attributes();
        $this->assertContains('network', $attrs);
        $this->assertContains('cost', $attrs);
        $this->assertContains('sensitive', $attrs);
    }

    public function test_is_not_read_only_because_upload_mutates_vendor_storage(): void
    {
        $tool = $this->makeTool([]);
        $this->assertFalse($tool->isReadOnly());
    }

    public function test_rejects_missing_file_path(): void
    {
        $tool = $this->makeTool([]);
        $result = $tool->execute([]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('file_path', $result->contentAsString());
    }

    public function test_rejects_unreadable_path(): void
    {
        $tool = $this->makeTool([]);
        $result = $tool->execute(['file_path' => '/does/not/exist.pdf']);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not readable', $result->contentAsString());
    }

    public function test_happy_path_uploads_and_returns_extracted_text(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kft_') . '.txt';
        file_put_contents($tmp, 'hello world');
        try {
            $history = [];
            $tool = $this->makeToolWithHistory([
                new Response(200, [], json_encode(['id' => 'file_abc123'])),
                new Response(200, [], 'The quick brown fox jumps over the lazy dog.'),
            ], $history);

            $result = $tool->execute(['file_path' => $tmp]);

            $this->assertFalse($result->isError);
            $data = $result->content;
            $this->assertSame('file_abc123', $data['file_id']);
            $this->assertSame('The quick brown fox jumps over the lazy dog.', $data['text']);
            $this->assertSame(44, $data['bytes']);

            // Request capture — two calls in order.
            $this->assertCount(2, $history);
            $uploadReq = $history[0]['request'];
            $fetchReq = $history[1]['request'];

            $this->assertSame('POST', $uploadReq->getMethod());
            $this->assertStringEndsWith('/v1/files', $uploadReq->getUri()->getPath());
            $this->assertStringStartsWith(
                'multipart/form-data',
                $uploadReq->getHeaderLine('Content-Type'),
            );

            $this->assertSame('GET', $fetchReq->getMethod());
            $this->assertStringEndsWith('/v1/files/file_abc123/content', $fetchReq->getUri()->getPath());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_accepts_content_wrapped_response(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kft_') . '.txt';
        file_put_contents($tmp, 'x');
        try {
            $history = [];
            $tool = $this->makeToolWithHistory([
                new Response(200, [], json_encode(['id' => 'file_abc'])),
                new Response(200, [], json_encode(['content' => 'wrapped text'])),
            ], $history);
            $result = $tool->execute(['file_path' => $tmp]);
            $this->assertSame('wrapped text', $result->content['text']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_upload_response_without_id_errors_out(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kft_') . '.txt';
        file_put_contents($tmp, 'x');
        try {
            $history = [];
            $tool = $this->makeToolWithHistory([
                new Response(200, [], json_encode(['status' => 'weird'])),
            ], $history);
            $result = $tool->execute(['file_path' => $tmp]);
            $this->assertTrue($result->isError);
            $this->assertStringContainsString('file id', $result->contentAsString());
        } finally {
            @unlink($tmp);
        }
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeTool(array $responses): KimiFileExtractTool
    {
        $history = [];
        return $this->makeToolWithHistory($responses, $history);
    }

    /**
     * @param array<int, Response>                  $responses
     * @param array<int, array<string, mixed>>      $history   captured by reference
     */
    private function makeToolWithHistory(array $responses, array &$history): KimiFileExtractTool
    {
        $provider = new KimiProvider(['api_key' => 'sk-test']);

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.moonshot.ai/',
        ]);

        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return new KimiFileExtractTool($provider);
    }
}
