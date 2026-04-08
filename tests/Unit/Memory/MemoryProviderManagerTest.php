<?php

namespace SuperAgent\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use SuperAgent\Memory\Contracts\MemoryProviderInterface;
use SuperAgent\Memory\MemoryProviderManager;

class MemoryProviderManagerTest extends TestCase
{
    public function test_builtin_only_returns_context(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');
        $builtin->method('onTurnStart')->willReturn('Memory: user prefers short answers');

        $manager = new MemoryProviderManager($builtin);
        $context = $manager->onTurnStart('hello', []);

        $this->assertNotNull($context);
        $this->assertStringContainsString('recalled-memory', $context);
        $this->assertStringContainsString('user prefers short answers', $context);
    }

    public function test_no_context_returns_null(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');
        $builtin->method('onTurnStart')->willReturn(null);

        $manager = new MemoryProviderManager($builtin);
        $this->assertNull($manager->onTurnStart('hello', []));
    }

    public function test_external_provider_combined_with_builtin(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');
        $builtin->method('onTurnStart')->willReturn('Builtin context');

        $external = $this->createMock(MemoryProviderInterface::class);
        $external->method('getName')->willReturn('vector');
        $external->method('onTurnStart')->willReturn('Vector context');

        $manager = new MemoryProviderManager($builtin);
        $manager->setExternalProvider($external);

        $context = $manager->onTurnStart('query', []);
        $this->assertStringContainsString('Builtin context', $context);
        $this->assertStringContainsString('Vector context', $context);
    }

    public function test_setting_external_shuts_down_previous(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');

        $first = $this->createMock(MemoryProviderInterface::class);
        $first->method('getName')->willReturn('first');
        $first->expects($this->once())->method('shutdown');

        $second = $this->createMock(MemoryProviderInterface::class);
        $second->method('getName')->willReturn('second');

        $manager = new MemoryProviderManager($builtin);
        $manager->setExternalProvider($first);
        $manager->setExternalProvider($second);

        $this->assertEquals('second', $manager->getExternalProvider()->getName());
    }

    public function test_search_combines_results(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');
        $builtin->method('search')->willReturn([
            ['content' => 'builtin result', 'relevance' => 0.8, 'source' => 'memory.md'],
        ]);

        $external = $this->createMock(MemoryProviderInterface::class);
        $external->method('getName')->willReturn('vector');
        $external->method('search')->willReturn([
            ['content' => 'vector result', 'relevance' => 0.9, 'source' => 'embedding'],
        ]);

        $manager = new MemoryProviderManager($builtin);
        $manager->setExternalProvider($external);

        $results = $manager->search('query', 5);
        $this->assertCount(2, $results);

        // Sorted by relevance — vector (0.9) should be first
        $this->assertEquals('vector', $results[0]['provider']);
    }

    public function test_lifecycle_dispatched_to_all_providers(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');
        $builtin->expects($this->once())->method('onTurnEnd');
        $builtin->expects($this->once())->method('onPreCompress');
        $builtin->expects($this->once())->method('onSessionEnd');

        $external = $this->createMock(MemoryProviderInterface::class);
        $external->method('getName')->willReturn('external');
        $external->expects($this->once())->method('onTurnEnd');
        $external->expects($this->once())->method('onPreCompress');
        $external->expects($this->once())->method('onSessionEnd');

        $manager = new MemoryProviderManager($builtin);
        $manager->setExternalProvider($external);

        $manager->onTurnEnd([], []);
        $manager->onPreCompress([]);
        $manager->onSessionEnd([]);
    }

    public function test_external_provider_error_does_not_crash(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');
        $builtin->method('onTurnStart')->willReturn('safe context');

        $external = $this->createMock(MemoryProviderInterface::class);
        $external->method('getName')->willReturn('broken');
        $external->method('onTurnStart')->willThrowException(new \RuntimeException('connection failed'));

        $manager = new MemoryProviderManager($builtin);
        $manager->setExternalProvider($external);

        // Should not throw, builtin context still returned
        $context = $manager->onTurnStart('query', []);
        $this->assertStringContainsString('safe context', $context);
    }

    public function test_shutdown_dispatched_to_all(): void
    {
        $builtin = $this->createMock(MemoryProviderInterface::class);
        $builtin->method('getName')->willReturn('builtin');
        $builtin->expects($this->once())->method('shutdown');

        $external = $this->createMock(MemoryProviderInterface::class);
        $external->method('getName')->willReturn('external');
        $external->expects($this->once())->method('shutdown');

        $manager = new MemoryProviderManager($builtin);
        $manager->setExternalProvider($external);
        $manager->shutdown();
    }
}
