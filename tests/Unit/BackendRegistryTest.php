<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\BackendRegistry;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\Backends\TmuxBackend;
use SuperAgent\Swarm\Backends\ITermBackend;

class BackendRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BackendRegistry::resetInstance();
    }

    protected function tearDown(): void
    {
        BackendRegistry::resetInstance();
        parent::tearDown();
    }

    // ── Singleton ────────────────────────────────────────────────

    public function testGetInstanceReturnsSingleton(): void
    {
        $a = BackendRegistry::getInstance();
        $b = BackendRegistry::getInstance();
        $this->assertSame($a, $b);
    }

    public function testResetInstanceCreatesNewInstance(): void
    {
        $a = BackendRegistry::getInstance();
        BackendRegistry::resetInstance();
        $b = BackendRegistry::getInstance();
        $this->assertNotSame($a, $b);
    }

    // ── Detect ───────────────────────────────────────────────────

    public function testDetectReturnsBackendType(): void
    {
        $registry = BackendRegistry::getInstance();
        $type = $registry->detect();
        $this->assertInstanceOf(BackendType::class, $type);
    }

    public function testDetectCachesResult(): void
    {
        $registry = BackendRegistry::getInstance();
        $first = $registry->detect();
        $second = $registry->detect();
        $this->assertSame($first, $second);
    }

    public function testDetectFallsBackToProcess(): void
    {
        // In a test env (no tmux, no iTerm), should get PROCESS or lower
        $registry = BackendRegistry::getInstance();
        $type = $registry->detect();
        // Should not get DOCKER or REMOTE in a local test env
        $this->assertContains($type, [
            BackendType::PROCESS,
            BackendType::TMUX,
            BackendType::ITERM2,
            BackendType::IN_PROCESS,
        ]);
    }

    // ── Get by type ──────────────────────────────────────────────

    public function testGetInProcessBackend(): void
    {
        $registry = BackendRegistry::getInstance();
        $backend = $registry->get(BackendType::IN_PROCESS);
        $this->assertInstanceOf(BackendInterface::class, $backend);
        $this->assertInstanceOf(InProcessBackend::class, $backend);
    }

    public function testGetProcessBackend(): void
    {
        $registry = BackendRegistry::getInstance();
        $backend = $registry->get(BackendType::PROCESS);
        $this->assertInstanceOf(BackendInterface::class, $backend);
        $this->assertInstanceOf(ProcessBackend::class, $backend);
    }

    public function testGetTmuxBackend(): void
    {
        $registry = BackendRegistry::getInstance();
        $backend = $registry->get(BackendType::TMUX);
        $this->assertInstanceOf(BackendInterface::class, $backend);
        $this->assertInstanceOf(TmuxBackend::class, $backend);
    }

    public function testGetITermBackend(): void
    {
        $registry = BackendRegistry::getInstance();
        $backend = $registry->get(BackendType::ITERM2);
        $this->assertInstanceOf(BackendInterface::class, $backend);
        $this->assertInstanceOf(ITermBackend::class, $backend);
    }

    public function testGetCachesBackendInstances(): void
    {
        $registry = BackendRegistry::getInstance();
        $first = $registry->get(BackendType::PROCESS);
        $second = $registry->get(BackendType::PROCESS);
        $this->assertSame($first, $second);
    }

    public function testGetDockerFallsBackToProcessBackend(): void
    {
        $registry = BackendRegistry::getInstance();
        $backend = $registry->get(BackendType::DOCKER);
        $this->assertInstanceOf(ProcessBackend::class, $backend);
    }

    public function testGetRemoteFallsBackToProcessBackend(): void
    {
        $registry = BackendRegistry::getInstance();
        $backend = $registry->get(BackendType::REMOTE);
        $this->assertInstanceOf(ProcessBackend::class, $backend);
    }

    // ── getDetected ──────────────────────────────────────────────

    public function testGetDetectedReturnsBackendInterface(): void
    {
        $registry = BackendRegistry::getInstance();
        $backend = $registry->getDetected();
        $this->assertInstanceOf(BackendInterface::class, $backend);
    }

    public function testGetDetectedMatchesDetectedType(): void
    {
        $registry = BackendRegistry::getInstance();
        $type = $registry->detect();
        $backend = $registry->getDetected();
        $this->assertEquals($type, $backend->getType());
    }

    // ── healthCheck ──────────────────────────────────────────────

    public function testHealthCheckReturnsAllBackendTypes(): void
    {
        $registry = BackendRegistry::getInstance();
        $results = $registry->healthCheck();

        foreach (BackendType::cases() as $type) {
            $this->assertArrayHasKey($type->value, $results);
        }
    }

    public function testHealthCheckResultsHaveAvailableKey(): void
    {
        $registry = BackendRegistry::getInstance();
        $results = $registry->healthCheck();

        foreach ($results as $key => $result) {
            $this->assertArrayHasKey('available', $result, "Missing 'available' for {$key}");
            $this->assertIsBool($result['available']);
        }
    }

    public function testHealthCheckInProcessAlwaysAvailable(): void
    {
        $registry = BackendRegistry::getInstance();
        $results = $registry->healthCheck();
        $this->assertTrue($results['in-process']['available']);
    }

    // ── getAvailableTypes ────────────────────────────────────────

    public function testGetAvailableTypesReturnsArray(): void
    {
        $registry = BackendRegistry::getInstance();
        $types = $registry->getAvailableTypes();
        $this->assertIsArray($types);
    }

    public function testGetAvailableTypesContainsInProcess(): void
    {
        $registry = BackendRegistry::getInstance();
        $types = $registry->getAvailableTypes();
        $this->assertContains(BackendType::IN_PROCESS, $types);
    }

    public function testGetAvailableTypesOnlyContainsBackendTypes(): void
    {
        $registry = BackendRegistry::getInstance();
        $types = $registry->getAvailableTypes();
        foreach ($types as $type) {
            $this->assertInstanceOf(BackendType::class, $type);
        }
    }

    // ── Reset ────────────────────────────────────────────────────

    public function testResetClearsDetectionCache(): void
    {
        $registry = BackendRegistry::getInstance();
        $first = $registry->detect();
        $registry->reset();
        // After reset, detect runs again (should get same result in same env, but cache was cleared)
        $second = $registry->detect();
        $this->assertEquals($first, $second);
    }

    public function testResetClearsBackendCache(): void
    {
        $registry = BackendRegistry::getInstance();
        $first = $registry->get(BackendType::PROCESS);
        $registry->reset();
        $second = $registry->get(BackendType::PROCESS);
        // After reset, new instances are created
        $this->assertNotSame($first, $second);
    }
}
