<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Providers\Kimi;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Tools\Providers\Kimi\KimiBatchTool;

class KimiBatchToolTest extends TestCase
{
    public function test_attributes_include_sensitive(): void
    {
        $tool = $this->make([]);
        $this->assertContains('sensitive', $tool->attributes());
        $this->assertFalse($tool->isReadOnly());
    }

    public function test_rejects_missing_or_unreadable_jsonl(): void
    {
        $tool = $this->make([]);
        $this->assertTrue($tool->execute([])->isError);
        $this->assertTrue($tool->execute(['jsonl_path' => '/no/such/file.jsonl'])->isError);
    }

    public function test_submitted_mode_skips_polling(): void
    {
        $tmp = $this->writeJsonl();
        try {
            $history = [];
            $tool = $this->makeWithHistory([
                new Response(200, [], json_encode(['id' => 'file_in_1'])),
                new Response(200, [], json_encode(['id' => 'batch_abc'])),
            ], $history);

            $result = $tool->execute([
                'jsonl_path' => $tmp,
                'wait' => false,
            ]);

            $this->assertFalse($result->isError);
            $this->assertSame('batch_abc', $result->content['batch_id']);
            $this->assertSame('submitted', $result->content['status']);
            // Exactly 2 HTTP calls — no polling.
            $this->assertCount(2, $history);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_happy_path_completes_on_first_poll(): void
    {
        $tmp = $this->writeJsonl();
        try {
            $tool = $this->make([
                new Response(200, [], json_encode(['id' => 'file_in'])),
                new Response(200, [], json_encode(['id' => 'batch_1'])),
                new Response(200, [], json_encode([
                    'status' => 'completed',
                    'output_file_id' => 'file_out',
                    'request_counts' => ['total' => 3, 'completed' => 3, 'failed' => 0],
                ])),
                new Response(200, [], "{\"a\":1}\n{\"a\":2}\n"),
            ]);

            $result = $tool->execute(['jsonl_path' => $tmp]);

            $this->assertFalse($result->isError);
            $this->assertSame('completed', $result->content['status']);
            $this->assertSame('file_out', $result->content['output_file_id']);
            $this->assertStringContainsString('"a":1', $result->content['output']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_failure_status_returned_as_error(): void
    {
        $tmp = $this->writeJsonl();
        try {
            $tool = $this->make([
                new Response(200, [], json_encode(['id' => 'file_in'])),
                new Response(200, [], json_encode(['id' => 'batch_1'])),
                new Response(200, [], json_encode([
                    'status' => 'failed',
                    'errors' => ['data' => [['message' => 'bad input']]],
                ])),
            ]);
            $result = $tool->execute(['jsonl_path' => $tmp]);
            $this->assertTrue($result->isError);
            $this->assertStringContainsString('bad input', $result->contentAsString());
        } finally {
            @unlink($tmp);
        }
    }

    private function writeJsonl(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kimibatch_') . '.jsonl';
        file_put_contents($tmp, "{\"custom_id\":\"1\",\"method\":\"POST\",\"url\":\"/v1/chat/completions\",\"body\":{}}\n");
        return $tmp;
    }

    private function make(array $responses): KimiBatchTool
    {
        $history = [];
        return $this->makeWithHistory($responses, $history);
    }

    private function makeWithHistory(array $responses, array &$history): KimiBatchTool
    {
        $provider = new KimiProvider(['api_key' => 'sk-test']);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.moonshot.ai/']);

        $ref = new \ReflectionObject($provider);
        while ($ref && ! $ref->hasProperty('client')) {
            $ref = $ref->getParentClass();
        }
        $p = $ref->getProperty('client');
        $p->setAccessible(true);
        $p->setValue($provider, $client);

        return new KimiBatchTool($provider);
    }
}
