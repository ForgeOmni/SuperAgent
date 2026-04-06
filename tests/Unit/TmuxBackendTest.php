<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\Backends\TmuxBackend;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;

class TmuxBackendTest extends TestCase
{
    // ── Type ──────────────────────────────────────────────────────

    public function testGetType(): void
    {
        $backend = new TmuxBackend();
        $this->assertEquals(BackendType::TMUX, $backend->getType());
    }

    // ── Availability detection ────────────────────────────────────

    public function testIsAvailableOutsideTmux(): void
    {
        // In CI / normal test, $TMUX is not set
        if (getenv('TMUX')) {
            $this->markTestSkipped('Running inside tmux — skip outside-tmux test');
        }

        $backend = new TmuxBackend();
        $this->assertFalse($backend->isAvailable());
    }

    public function testDetectOutsideTmux(): void
    {
        if (getenv('TMUX')) {
            $this->markTestSkipped('Running inside tmux');
        }

        $this->assertFalse(TmuxBackend::detect());
    }

    // ── Spawn fails gracefully outside tmux ───────────────────────

    public function testSpawnFailsWhenNotAvailable(): void
    {
        if (getenv('TMUX')) {
            $this->markTestSkipped('Running inside tmux');
        }

        $backend = new TmuxBackend();
        $config = new AgentSpawnConfig(
            name: 'test-agent',
            prompt: 'Hello',
        );

        $result = $backend->spawn($config);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->error);
        $this->assertStringContainsString('not available', $result->error);
    }

    // ── Status management ─────────────────────────────────────────

    public function testGetStatusReturnsNullForUnknown(): void
    {
        $backend = new TmuxBackend();
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    public function testIsRunningReturnsFalseForUnknown(): void
    {
        $backend = new TmuxBackend();
        $this->assertFalse($backend->isRunning('nonexistent'));
    }

    // ── Cleanup ───────────────────────────────────────────────────

    public function testCleanupNonexistentAgent(): void
    {
        $backend = new TmuxBackend();
        // Should not throw
        $backend->cleanup('nonexistent');
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    // ── Kill nonexistent ──────────────────────────────────────────

    public function testKillNonexistentAgent(): void
    {
        $backend = new TmuxBackend();
        // Should not throw
        $backend->kill('nonexistent');
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    // ── Shutdown nonexistent ──────────────────────────────────────

    public function testRequestShutdownNonexistent(): void
    {
        $backend = new TmuxBackend();
        // Should not throw
        $backend->requestShutdown('nonexistent');
        $this->assertNull($backend->getStatus('nonexistent'));
    }

    // ── Send message (no-op) ──────────────────────────────────────

    public function testSendMessageIsNoOp(): void
    {
        $backend = new TmuxBackend();
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
        $backend = new TmuxBackend();
        $this->assertEmpty($backend->getAgents());
    }

    // ── BackendType enum ──────────────────────────────────────────

    public function testBackendTypeHasTmux(): void
    {
        $this->assertEquals('tmux', BackendType::TMUX->value);
    }

    public function testBackendTypeFromString(): void
    {
        $type = BackendType::from('tmux');
        $this->assertEquals(BackendType::TMUX, $type);
    }
}
