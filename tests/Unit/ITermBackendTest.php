<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\Backends\ITermBackend;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;

class ITermBackendTest extends TestCase
{
    // ── Type ──────────────────────────────────────────────────────

    public function testGetType(): void
    {
        $backend = new ITermBackend();
        $this->assertEquals(BackendType::ITERM2, $backend->getType());
    }

    // ── Availability detection ────────────────────────────────────

    public function testIsAvailableOutsideITerm(): void
    {
        if (getenv('ITERM_SESSION_ID')) {
            $this->markTestSkipped('Running inside iTerm2 — skip outside-iTerm test');
        }

        $backend = new ITermBackend();
        $this->assertFalse($backend->isAvailable());
    }

    public function testDetectOutsideITerm(): void
    {
        if (getenv('ITERM_SESSION_ID')) {
            $this->markTestSkipped('Running inside iTerm2');
        }

        $this->assertFalse(ITermBackend::detect());
    }

    public function testDetectReturnsTrueWhenEnvSet(): void
    {
        $original = getenv('ITERM_SESSION_ID');
        putenv('ITERM_SESSION_ID=w0t0p0:12345678-ABCD-1234-5678-ABCDEF012345');

        try {
            $this->assertTrue(ITermBackend::detect());
        } finally {
            if ($original === false) {
                putenv('ITERM_SESSION_ID');
            } else {
                putenv("ITERM_SESSION_ID={$original}");
            }
        }
    }

    // ── Spawn fails gracefully outside iTerm2 ─────────────────────

    public function testSpawnFailsWhenNotAvailable(): void
    {
        if (getenv('ITERM_SESSION_ID')) {
            $this->markTestSkipped('Running inside iTerm2');
        }

        $backend = new ITermBackend();
        $config = new AgentSpawnConfig(
            name: 'test-agent',
            prompt: 'Hello',
        );

        $result = $backend->spawn($config);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->error);
        $this->assertStringContainsString('not available', $result->error);
    }

    public function testSpawnFailsWithCorrectErrorMessage(): void
    {
        if (getenv('ITERM_SESSION_ID')) {
            $this->markTestSkipped('Running inside iTerm2');
        }

        $backend = new ITermBackend();
        $config = new AgentSpawnConfig(
            name: 'my-agent',
            prompt: 'Do something',
        );

        $result = $backend->spawn($config);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('iTerm2', $result->error);
        $this->assertEmpty($result->agentId);
    }

    // ── Status management ─────────────────────────────────────────

    public function testGetStatusReturnsNullForUnknown(): void
    {
        $backend = new ITermBackend();
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    public function testIsRunningReturnsFalseForUnknown(): void
    {
        $backend = new ITermBackend();
        $this->assertFalse($backend->isRunning('nonexistent'));
    }

    // ── Cleanup ───────────────────────────────────────────────────

    public function testCleanupNonexistentAgent(): void
    {
        $backend = new ITermBackend();
        // Should not throw
        $backend->cleanup('nonexistent');
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    // ── Kill nonexistent ──────────────────────────────────────────

    public function testKillNonexistentAgent(): void
    {
        $backend = new ITermBackend();
        // Should not throw
        $backend->kill('nonexistent');
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    // ── Shutdown nonexistent ──────────────────────────────────────

    public function testRequestShutdownNonexistent(): void
    {
        $backend = new ITermBackend();
        // Should not throw
        $backend->requestShutdown('nonexistent');
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    // ── Send message (no-op) ──────────────────────────────────────

    public function testSendMessageIsNoOp(): void
    {
        $backend = new ITermBackend();
        // Should not throw — just logs a warning
        $backend->sendMessage('nonexistent', new \SuperAgent\Swarm\AgentMessage(
            from: 'parent',
            to: 'nonexistent',
            content: 'hello',
        ));
        $this->assertTrue(true); // no exception
    }

    // ── GetAgents ─────────────────────────────────────────────────

    public function testGetAgentsEmpty(): void
    {
        $backend = new ITermBackend();
        $this->assertEmpty($backend->getAgents());
    }

    public function testGetAgentsReturnsArray(): void
    {
        $backend = new ITermBackend();
        $agents = $backend->getAgents();
        $this->assertIsArray($agents);
        $this->assertCount(0, $agents);
    }

    // ── BackendType enum ──────────────────────────────────────────

    public function testBackendTypeHasITerm2(): void
    {
        $this->assertEquals('iterm2', BackendType::ITERM2->value);
    }

    public function testBackendTypeFromString(): void
    {
        $type = BackendType::from('iterm2');
        $this->assertEquals(BackendType::ITERM2, $type);
    }

    // ── Constructor ───────────────────────────────────────────────

    public function testConstructorWithCustomScript(): void
    {
        $backend = new ITermBackend(agentScript: '/custom/path/agent-runner.php');
        $this->assertEquals(BackendType::ITERM2, $backend->getType());
    }

    public function testConstructorWithLogger(): void
    {
        $logger = new \Psr\Log\NullLogger();
        $backend = new ITermBackend(logger: $logger);
        $this->assertInstanceOf(ITermBackend::class, $backend);
    }

    // ── Implements BackendInterface ───────────────────────────────

    public function testImplementsBackendInterface(): void
    {
        $backend = new ITermBackend();
        $this->assertInstanceOf(\SuperAgent\Swarm\Backends\BackendInterface::class, $backend);
    }
}
