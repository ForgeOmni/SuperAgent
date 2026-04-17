<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;

/**
 * Tests for ProcessBackend — true OS-process-level agent execution.
 */
class ProcessBackendTest extends TestCase
{
    private ProcessBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backend = new ProcessBackend();
    }

    public function testBackendType(): void
    {
        $this->assertSame(BackendType::PROCESS, $this->backend->getType());
    }

    public function testIsAvailable(): void
    {
        // proc_open should be available in standard PHP
        $this->assertTrue($this->backend->isAvailable());
    }

    /**
     * Spawn a trivial PHP script to verify the process lifecycle works.
     */
    public function testSpawnAndCollectResult(): void
    {
        // Create a minimal test script that reads stdin JSON, writes stdout JSON
        $scriptPath = sys_get_temp_dir() . '/superagent_test_runner_' . uniqid() . '.php';
        file_put_contents($scriptPath, <<<'PHP'
<?php
$input = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 65536);
    if ($chunk === false) break;
    $input .= $chunk;
}
$config = json_decode(trim($input), true);
$result = [
    'success' => true,
    'agent_id' => $config['agent_id'] ?? 'test',
    'text' => 'Hello from sub-agent: ' . ($config['prompt'] ?? ''),
    'turns' => 1,
    'cost_usd' => 0.001,
    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
    'responses' => [],
];
echo json_encode($result) . "\n";
exit(0);
PHP
        );

        try {
            $backend = new ProcessBackend($scriptPath);

            $config = new AgentSpawnConfig(
                name: 'test_agent',
                prompt: 'Say hello',
                providerConfig: ['provider' => 'test'],
            );

            $spawnResult = $backend->spawn($config);
            $this->assertTrue($spawnResult->success);
            $this->assertNotEmpty($spawnResult->agentId);
            $this->assertNotNull($spawnResult->pid);

            // Wait for process to complete
            $results = $backend->waitAll(10);

            $this->assertArrayHasKey($spawnResult->agentId, $results);
            $result = $results[$spawnResult->agentId];
            $this->assertTrue($result['success']);
            $this->assertStringContains('Hello from sub-agent: Say hello', $result['text']);
            $this->assertEquals(100, $result['usage']['input_tokens']);
            $this->assertEquals(50, $result['usage']['output_tokens']);

            // Status should be COMPLETED
            $this->assertSame(AgentStatus::COMPLETED, $backend->getStatus($spawnResult->agentId));

        } finally {
            @unlink($scriptPath);
        }
    }

    /**
     * Verify multiple agents run truly in parallel (not sequentially).
     */
    public function testParallelExecution(): void
    {
        // Script that sleeps for 1 second then returns
        $scriptPath = sys_get_temp_dir() . '/superagent_test_parallel_' . uniqid() . '.php';
        file_put_contents($scriptPath, <<<'PHP'
<?php
$input = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 65536);
    if ($chunk === false) break;
    $input .= $chunk;
}
$config = json_decode(trim($input), true);
$sleepMs = (int)($config['agent_config']['sleep_ms'] ?? 1000);
usleep($sleepMs * 1000);
echo json_encode([
    'success' => true,
    'agent_id' => $config['agent_id'] ?? 'test',
    'text' => 'Done after ' . $sleepMs . 'ms',
    'turns' => 1,
    'cost_usd' => 0.0,
    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    'responses' => [],
]) . "\n";
PHP
        );

        try {
            $backend = new ProcessBackend($scriptPath);
            $agentCount = 3;
            $sleepMs = 500; // Each agent "works" for 500ms

            $startTime = microtime(true);

            // Spawn all agents
            $agentIds = [];
            for ($i = 0; $i < $agentCount; $i++) {
                $config = new AgentSpawnConfig(
                    name: "parallel_agent_{$i}",
                    prompt: "Task {$i}",
                    providerConfig: ['sleep_ms' => $sleepMs],
                );
                $result = $backend->spawn($config);
                $this->assertTrue($result->success, "Failed to spawn agent {$i}");
                $agentIds[] = $result->agentId;
            }

            // Wait for all to complete
            $results = $backend->waitAll(10);
            $elapsed = (microtime(true) - $startTime) * 1000;

            // All should have completed
            $this->assertCount($agentCount, $results);
            foreach ($agentIds as $id) {
                $this->assertArrayHasKey($id, $results);
                $this->assertTrue($results[$id]['success']);
            }

            // If truly parallel, total time should be ~500ms, not ~1500ms (3 × 500ms).
            // Allow generous margin for process startup overhead, but it should be
            // well under 3× sequential time.
            $sequentialTime = $agentCount * $sleepMs;
            $this->assertLessThan(
                $sequentialTime * 0.8, // Must be less than 80% of sequential time
                $elapsed,
                "Agents appear to have run sequentially ({$elapsed}ms >= " . ($sequentialTime * 0.8) . "ms). " .
                "Expected parallel execution to complete in ~{$sleepMs}ms + overhead."
            );

            // Sanity check: it shouldn't be instantaneous either
            $this->assertGreaterThan(
                $sleepMs * 0.5,
                $elapsed,
                "Agents completed too fast — sleep may not have worked"
            );

        } finally {
            @unlink($scriptPath);
        }
    }

    /**
     * Test that a failing child process is detected.
     */
    public function testFailedProcess(): void
    {
        $scriptPath = sys_get_temp_dir() . '/superagent_test_fail_' . uniqid() . '.php';
        file_put_contents($scriptPath, <<<'PHP'
<?php
$input = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 65536);
    if ($chunk === false) break;
    $input .= $chunk;
}
$config = json_decode(trim($input), true);
echo json_encode([
    'success' => false,
    'agent_id' => $config['agent_id'] ?? 'test',
    'error' => 'Simulated failure',
]) . "\n";
exit(1);
PHP
        );

        try {
            $backend = new ProcessBackend($scriptPath);

            $config = new AgentSpawnConfig(
                name: 'fail_agent',
                prompt: 'This should fail',
            );

            $spawnResult = $backend->spawn($config);
            $this->assertTrue($spawnResult->success);

            $results = $backend->waitAll(10);
            $result = $results[$spawnResult->agentId];

            $this->assertFalse($result['success']);
            $this->assertEquals('Simulated failure', $result['error']);
            $this->assertSame(AgentStatus::FAILED, $backend->getStatus($spawnResult->agentId));

        } finally {
            @unlink($scriptPath);
        }
    }

    /**
     * Test kill of a running process.
     */
    public function testKillAgent(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Process kill behavior differs on Windows.');
        }

        // Script that sleeps forever
        $scriptPath = sys_get_temp_dir() . '/superagent_test_kill_' . uniqid() . '.php';
        file_put_contents($scriptPath, <<<'PHP'
<?php
fread(STDIN, 1); // read stdin to unblock
sleep(300); // Sleep for 5 minutes — will be killed before this
PHP
        );

        try {
            $backend = new ProcessBackend($scriptPath);

            $config = new AgentSpawnConfig(
                name: 'kill_target',
                prompt: 'Run forever',
            );

            $spawnResult = $backend->spawn($config);
            $this->assertTrue($spawnResult->success);

            // Give it a moment to start
            usleep(100_000);
            $this->assertTrue($backend->isRunning($spawnResult->agentId));

            // Kill it — kill() calls cleanup() which removes the agent entry
            $backend->kill($spawnResult->agentId);

            // After kill + cleanup, the agent is no longer tracked
            $this->assertNull($backend->getStatus($spawnResult->agentId));
            $this->assertFalse($backend->isRunning($spawnResult->agentId));

        } finally {
            @unlink($scriptPath);
        }
    }

    /**
     * Helper: PHPUnit 9-compatible string contains assertion.
     */
    private static function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        static::assertStringContainsString($needle, $haystack, $message);
    }
}
