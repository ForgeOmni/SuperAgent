<?php

namespace SuperAgent\Tests\Unit\Sandbox;

use PHPUnit\Framework\TestCase;
use SuperAgent\Sandbox\BubblewrapSandbox;
use SuperAgent\Sandbox\NullSandbox;
use SuperAgent\Sandbox\SandboxInterface;
use SuperAgent\Sandbox\SandboxManager;
use SuperAgent\Sandbox\SandboxSpec;
use SuperAgent\Sandbox\SandboxUnavailableException;
use SuperAgent\Sandbox\SeatbeltSandbox;
use SuperAgent\Tools\Builtin\BashTool;

class SandboxTest extends TestCase
{
    // ── SandboxSpec ───────────────────────────────────────────────

    public function testSpecResolvesAndDedupesPaths(): void
    {
        $spec = new SandboxSpec(
            workspaceRoot: sys_get_temp_dir(),
            writablePaths: [sys_get_temp_dir(), '/nonexistent-path-xyz'],
        );

        $paths = $spec->resolvedWritablePaths();
        $this->assertContains(realpath(sys_get_temp_dir()), $paths);
        $this->assertContains('/nonexistent-path-xyz', $paths);
        // tmp dir deduped
        $this->assertCount(2, $paths);
    }

    // ── Seatbelt profile generation ───────────────────────────────

    public function testSeatbeltProfileDeniesWritesByDefault(): void
    {
        $sandbox = new SeatbeltSandbox();
        $profile = $sandbox->buildProfile(new SandboxSpec('/work/space', allowNetwork: true));

        $this->assertStringContainsString('(deny file-write*)', $profile);
        $this->assertStringContainsString('(subpath "/work/space")', $profile);
        $this->assertStringContainsString('(subpath "/dev")', $profile);
        $this->assertStringNotContainsString('(deny network*)', $profile);
    }

    public function testSeatbeltProfileDeniesNetworkWhenAsked(): void
    {
        $sandbox = new SeatbeltSandbox();
        $profile = $sandbox->buildProfile(new SandboxSpec('/w', allowNetwork: false));

        $this->assertStringContainsString('(deny network*)', $profile);
    }

    public function testSeatbeltProfileEscapesQuotesInPaths(): void
    {
        $sandbox = new SeatbeltSandbox();
        $profile = $sandbox->buildProfile(new SandboxSpec('/path/with"quote'));

        $this->assertStringContainsString('(subpath "/path/with\\"quote")', $profile);
    }

    public function testSeatbeltWrapProducesSandboxExecCommand(): void
    {
        if (!(new SeatbeltSandbox())->isAvailable()) {
            $this->markTestSkipped('sandbox-exec not available');
        }

        $wrapped = (new SeatbeltSandbox())->wrapCommand(
            'echo "hello world"',
            new SandboxSpec(sys_get_temp_dir()),
        );

        $this->assertStringStartsWith('/usr/bin/sandbox-exec -f ', $wrapped);
        $this->assertStringContainsString('/bin/sh -c', $wrapped);
        $this->assertStringContainsString('hello world', $wrapped);
    }

    // ── Bubblewrap command generation ─────────────────────────────

    public function testBubblewrapUnavailableOffLinux(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $this->markTestSkipped('Linux host — availability depends on bwrap');
        }

        $this->assertFalse((new BubblewrapSandbox())->isAvailable());
    }

    // ── SandboxManager modes ──────────────────────────────────────

    public function testModeOffIsInactivePassthrough(): void
    {
        $manager = new SandboxManager(mode: SandboxManager::MODE_OFF);

        $this->assertFalse($manager->isActive());
        $this->assertSame('ls -la', $manager->wrapCommand('ls -la'));
        $this->assertSame('sandbox off', $manager->describeEnforcement());
    }

    public function testModeAutoFallsThroughWithoutBackend(): void
    {
        $manager = new SandboxManager(
            mode: SandboxManager::MODE_AUTO,
            spec: new SandboxSpec(sys_get_temp_dir()),
            backends: [], // no backends available
        );

        $this->assertFalse($manager->isActive());
        $this->assertSame('ls', $manager->wrapCommand('ls'));
    }

    public function testModeRequireFailsClosedWithoutBackend(): void
    {
        $manager = new SandboxManager(
            mode: SandboxManager::MODE_REQUIRE,
            spec: new SandboxSpec(sys_get_temp_dir()),
            backends: [],
        );

        $this->assertTrue($manager->isActive());
        $this->expectException(SandboxUnavailableException::class);
        $manager->wrapCommand('ls');
    }

    public function testModeAutoUsesAvailableBackend(): void
    {
        $fake = new class implements SandboxInterface {
            public function name(): string
            {
                return 'fake';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function wrapCommand(string $command, SandboxSpec $spec): string
            {
                return 'WRAPPED[' . $command . ']';
            }

            public function describeEnforcement(SandboxSpec $spec): string
            {
                return 'fake enforcement';
            }
        };

        $manager = new SandboxManager(
            mode: SandboxManager::MODE_AUTO,
            spec: new SandboxSpec(sys_get_temp_dir()),
            backends: [$fake],
        );

        $this->assertTrue($manager->isActive());
        $this->assertSame('WRAPPED[ls]', $manager->wrapCommand('ls'));
        $this->assertSame('fake enforcement', $manager->describeEnforcement());
    }

    // ── BashTool integration ──────────────────────────────────────

    public function testBashToolFailsClosedInRequireModeWithoutBackend(): void
    {
        $tool = new BashTool(sandbox: new SandboxManager(
            mode: SandboxManager::MODE_REQUIRE,
            spec: new SandboxSpec(sys_get_temp_dir()),
            backends: [],
        ));

        $result = $tool->execute(['command' => 'echo should-not-run']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Sandbox required but unavailable', $result->contentAsString());
    }

    public function testBashToolDefaultModeOffRunsNormally(): void
    {
        $tool = new BashTool(sandbox: new SandboxManager(mode: SandboxManager::MODE_OFF));

        $result = $tool->execute(['command' => 'echo unconfined-ok']);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('unconfined-ok', $result->contentAsString());
    }

    // ── Real enforcement (macOS only, skipped elsewhere) ──────────

    public function testSeatbeltActuallyBlocksWritesOutsideWorkspace(): void
    {
        $seatbelt = new SeatbeltSandbox();
        if (!$seatbelt->isAvailable()) {
            $this->markTestSkipped('sandbox-exec not available on this host');
        }

        $workspace = sys_get_temp_dir() . '/sa-sandbox-ws-' . bin2hex(random_bytes(4));
        mkdir($workspace, 0700, true);
        $outside = sys_get_temp_dir() . '/sa-sandbox-outside-' . bin2hex(random_bytes(4)) . '.txt';

        try {
            // Workspace only — note the spec deliberately does NOT include
            // the enclosing temp dir as writable, only the workspace itself.
            $spec = new SandboxSpec($workspace, writablePaths: []);

            $inside = $seatbelt->wrapCommand("echo ok > {$workspace}/in.txt", $spec);
            exec($inside . ' 2>/dev/null', $o1, $codeInside);

            $escape = $seatbelt->wrapCommand("echo escaped > {$outside}", $spec);
            exec($escape . ' 2>/dev/null', $o2, $codeOutside);

            $this->assertSame(0, $codeInside, 'write inside workspace must succeed');
            $this->assertFileExists($workspace . '/in.txt');
            $this->assertNotSame(0, $codeOutside, 'write outside workspace must be denied');
            $this->assertFileDoesNotExist($outside);
        } finally {
            @unlink($workspace . '/in.txt');
            @rmdir($workspace);
            @unlink($outside);
        }
    }
}
