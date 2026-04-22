<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Capabilities;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\AsyncCapable;

/**
 * Structural contract tests for the capability interface family.
 *
 * These don't verify any provider implementation — they exist to catch
 * accidental renames / inheritance changes that would silently break the
 * Phase 3+ Capability router. If you're editing a capability interface
 * and a test here turns red, pause and think: do you really want callers
 * that relied on the old shape to quietly change behaviour?
 */
class CapabilityInterfaceContractTest extends TestCase
{
    /**
     * @dataProvider syncCapabilityProvider
     */
    public function test_sync_capability_interfaces_exist_and_have_expected_methods(
        string $interface,
        array $expectedMethods,
    ): void {
        $this->assertTrue(interface_exists($interface), "Missing interface: {$interface}");
        $ref = new \ReflectionClass($interface);
        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Interface {$interface} is missing method {$method}()",
            );
        }
    }

    /**
     * @return array<string, array{0: class-string, 1: array<int, string>}>
     */
    public static function syncCapabilityProvider(): array
    {
        return [
            'Thinking' => [
                \SuperAgent\Providers\Capabilities\SupportsThinking::class,
                ['thinkingRequestFragment'],
            ],
            'ContextCaching' => [
                \SuperAgent\Providers\Capabilities\SupportsContextCaching::class,
                ['cacheBreakpoint'],
            ],
            'FileExtract' => [
                \SuperAgent\Providers\Capabilities\SupportsFileExtract::class,
                ['uploadForExtract', 'fileReferenceFragment'],
            ],
            'WebSearch' => [
                \SuperAgent\Providers\Capabilities\SupportsWebSearch::class,
                ['webSearch'],
            ],
            'CodeInterpreter' => [
                \SuperAgent\Providers\Capabilities\SupportsCodeInterpreter::class,
                ['codeInterpreterRequestFragment'],
            ],
            'OCR' => [
                \SuperAgent\Providers\Capabilities\SupportsOCR::class,
                ['ocr'],
            ],
            'Skills' => [
                \SuperAgent\Providers\Capabilities\SupportsSkills::class,
                ['registerSkill', 'applySkillFragment', 'listSkills'],
            ],
        ];
    }

    /**
     * @dataProvider asyncCapabilityProvider
     */
    public function test_async_capability_interfaces_extend_async_capable(
        string $interface,
        array $expectedSubmitMethods,
    ): void {
        $this->assertTrue(interface_exists($interface), "Missing interface: {$interface}");
        $this->assertTrue(
            is_subclass_of($interface, AsyncCapable::class),
            "{$interface} must extend AsyncCapable",
        );

        $ref = new \ReflectionClass($interface);
        foreach ($expectedSubmitMethods as $method) {
            $this->assertTrue(
                $ref->hasMethod($method),
                "Async interface {$interface} is missing method {$method}()",
            );
        }
    }

    /**
     * @return array<string, array{0: class-string, 1: array<int, string>}>
     */
    public static function asyncCapabilityProvider(): array
    {
        return [
            'Swarm' => [\SuperAgent\Providers\Capabilities\SupportsSwarm::class, ['submitSwarm']],
            'Batch' => [\SuperAgent\Providers\Capabilities\SupportsBatch::class, ['submitBatch']],
            'TTS' => [\SuperAgent\Providers\Capabilities\SupportsTTS::class, ['submitTTS']],
            'Music' => [\SuperAgent\Providers\Capabilities\SupportsMusic::class, ['submitMusic']],
            'Video' => [\SuperAgent\Providers\Capabilities\SupportsVideo::class, ['submitVideo']],
            'Image' => [\SuperAgent\Providers\Capabilities\SupportsImage::class, ['submitImage']],
        ];
    }

    public function test_async_capable_has_exactly_poll_fetch_cancel(): void
    {
        $ref = new \ReflectionClass(AsyncCapable::class);
        $methods = array_map(
            static fn (\ReflectionMethod $m) => $m->getName(),
            $ref->getMethods(),
        );
        sort($methods);
        $this->assertSame(['cancel', 'fetch', 'poll'], $methods);
    }

    public function test_capability_interfaces_live_in_capabilities_namespace(): void
    {
        // Prevents accidental inheritance from non-capability classes and
        // keeps the `instanceof` checks in CapabilityRouter unambiguous.
        $dir = dirname(__DIR__, 4) . '/src/Providers/Capabilities';
        $this->assertTrue(is_dir($dir));

        foreach (glob($dir . '/*.php') as $file) {
            $base = basename($file, '.php');
            $fqn = 'SuperAgent\\Providers\\Capabilities\\' . $base;
            $this->assertTrue(
                interface_exists($fqn),
                "File {$base}.php must declare interface {$fqn}",
            );
        }
    }
}
