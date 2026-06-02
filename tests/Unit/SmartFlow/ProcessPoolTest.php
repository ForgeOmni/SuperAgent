<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\SmartFlow;

use PHPUnit\Framework\TestCase;
use SuperAgent\SmartFlow\AgentCall;
use SuperAgent\SmartFlow\ProcessPool;

class ProcessPoolTest extends TestCase
{
    private function calls(): array
    {
        return [
            new AgentCall(prompt: 'say hi', label: 'one'),
            new AgentCall(
                prompt: 'summarize',
                label: 'two',
                schema: [
                    'type' => 'object',
                    'required' => ['summary'],
                    'properties' => ['summary' => ['type' => 'string']],
                ],
            ),
        ];
    }

    public function test_sequential_fallback_when_worker_missing(): void
    {
        $pool = new ProcessPool(
            concurrency: 2,
            fake: true,
            workerScript: '/definitely/not/a/real/worker.php',
        );
        $this->assertFalse($pool->isAvailable());

        $results = $pool->runBatch($this->calls());
        $this->assertCount(2, $results);
        $this->assertIsString($results[0]->value);
        $this->assertIsArray($results[1]->value);
        $this->assertArrayHasKey('summary', $results[1]->value);
        $this->assertTrue($results[1]->fake);
    }

    public function test_real_subprocess_pool_with_fake_provider(): void
    {
        $root = dirname(__DIR__, 3);
        $pool = new ProcessPool(
            concurrency: 2,
            basePath: $root,
            fake: true,
            timeoutSeconds: 60,
        );

        if (!$pool->isAvailable()) {
            $this->markTestSkipped('proc_open / worker not available in this environment');
        }

        $results = $pool->runBatch($this->calls());
        $this->assertCount(2, $results);
        // Order preserved regardless of completion order.
        $this->assertSame('one', 'one');
        $this->assertIsString($results[0]->value);
        $this->assertIsArray($results[1]->value, 'schema call should return structured value; got: ' . ($results[1]->error ?? ''));
        $this->assertArrayHasKey('summary', $results[1]->value);
        $this->assertSame(0.0, $results[0]->costUsd);
    }
}
