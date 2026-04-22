<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Qwen;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\QwenProvider;
use SuperAgent\Tools\Providers\Qwen\QwenLongFileTool;

class QwenLongFileToolTest extends TestCase
{
    public function test_attributes_include_sensitive_and_is_not_readonly(): void
    {
        $tool = $this->make([]);
        $this->assertContains('sensitive', $tool->attributes());
        $this->assertFalse($tool->isReadOnly());
    }

    public function test_rejects_missing_file(): void
    {
        $this->assertTrue($this->make([])->execute([])->isError);
        $this->assertTrue($this->make([])->execute(['file_path' => '/nope'])->isError);
    }

    public function test_happy_path_returns_fileid_reference(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qwlong_') . '.pdf';
        file_put_contents($tmp, 'pdf-bytes');
        try {
            $history = [];
            $tool = $this->makeWithHistory(
                [new Response(200, [], json_encode(['id' => 'file-qw-abc']))],
                $history,
            );
            $result = $tool->execute(['file_path' => $tmp]);

            $this->assertFalse($result->isError);
            $this->assertSame('file-qw-abc', $result->content['file_id']);
            $this->assertSame('fileid://file-qw-abc', $result->content['reference']);
            $this->assertStringEndsWith('.pdf', $result->content['filename']);

            $req = $history[0]['request'];
            $this->assertSame('POST', $req->getMethod());
            $this->assertStringEndsWith('api/v1/files', $req->getUri()->getPath());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_accepts_nested_data_id_response_shape(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qwlong_') . '.txt';
        file_put_contents($tmp, 'x');
        try {
            $tool = $this->make(
                [new Response(200, [], json_encode(['data' => ['id' => 'file-nested']]))],
            );
            $result = $tool->execute(['file_path' => $tmp]);
            $this->assertSame('file-nested', $result->content['file_id']);
        } finally {
            @unlink($tmp);
        }
    }

    private function make(array $responses): QwenLongFileTool
    {
        $h = [];
        return $this->makeWithHistory($responses, $h);
    }

    private function makeWithHistory(array $responses, array &$history): QwenLongFileTool
    {
        $provider = new QwenProvider(['api_key' => 'k', 'region' => 'cn']);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://dashscope.aliyuncs.com/']);
        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) $ref = $ref->getParentClass();
        $p = $ref->getProperty('client');
        $p->setAccessible(true);
        $p->setValue($provider, $client);
        return new QwenLongFileTool($provider);
    }
}
