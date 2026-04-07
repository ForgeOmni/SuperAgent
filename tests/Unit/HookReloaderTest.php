<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Hooks\HookReloader;
use SuperAgent\Hooks\HookRegistry;

class HookReloaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/superagent_hook_reloader_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        foreach ($files ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testInitialLoadCreatesRegistry(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);
        $registry = $reloader->currentRegistry();

        $this->assertInstanceOf(HookRegistry::class, $registry);
    }

    public function testCurrentRegistryReturnsCachedWhenUnchanged(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);
        $first = $reloader->currentRegistry();
        $second = $reloader->currentRegistry();

        $this->assertSame($first, $second);
    }

    public function testReloadOnMtimeChange(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);
        $first = $reloader->currentRegistry();

        // Touch the file to change mtime
        sleep(1);
        touch($configPath, time() + 10);
        clearstatcache();

        $second = $reloader->currentRegistry();

        $this->assertNotSame($first, $second);
    }

    public function testNoReloadWhenMtimeUnchanged(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);
        $first = $reloader->currentRegistry();

        // Don't touch the file
        $second = $reloader->currentRegistry();

        $this->assertSame($first, $second);
    }

    public function testForceReloadAlwaysCreatesNewRegistry(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);
        $first = $reloader->currentRegistry();
        $forced = $reloader->forceReload();

        $this->assertNotSame($first, $forced);
        $this->assertInstanceOf(HookRegistry::class, $forced);
    }

    public function testHasChangedReturnsFalseInitially(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);

        // Before any load, lastMtime is null, and file exists, so they differ
        $this->assertTrue($reloader->hasChanged());
    }

    public function testHasChangedReturnsFalseAfterLoad(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);
        $reloader->currentRegistry(); // triggers load

        $this->assertFalse($reloader->hasChanged());
    }

    public function testHasChangedReturnsTrueAfterFileModified(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, json_encode([]));

        $reloader = new HookReloader($configPath);
        $reloader->currentRegistry();

        touch($configPath, time() + 10);
        clearstatcache();

        $this->assertTrue($reloader->hasChanged());
    }

    public function testGetWatchedPathsWithMainConfigOnly(): void
    {
        $configPath = $this->tempDir . '/hooks.json';

        $reloader = new HookReloader($configPath);
        $paths = $reloader->getWatchedPaths();

        $this->assertCount(1, $paths);
        $this->assertEquals($configPath, $paths[0]);
    }

    public function testGetWatchedPathsWithPluginConfig(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        $pluginPath = $this->tempDir . '/plugin_hooks.json';

        $reloader = new HookReloader($configPath, $pluginPath);
        $paths = $reloader->getWatchedPaths();

        $this->assertCount(2, $paths);
        $this->assertEquals($configPath, $paths[0]);
        $this->assertEquals($pluginPath, $paths[1]);
    }

    public function testFromDefaultsReturnsInstance(): void
    {
        $reloader = HookReloader::fromDefaults();

        $this->assertInstanceOf(HookReloader::class, $reloader);
        $this->assertNotEmpty($reloader->getWatchedPaths());
    }

    public function testHandlesMissingConfigGracefully(): void
    {
        $configPath = $this->tempDir . '/nonexistent.json';

        $reloader = new HookReloader($configPath);
        $registry = $reloader->currentRegistry();

        $this->assertInstanceOf(HookRegistry::class, $registry);
    }

    public function testHandlesInvalidJsonGracefully(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        file_put_contents($configPath, '{invalid json!!!}');

        $reloader = new HookReloader($configPath);
        $registry = $reloader->currentRegistry();

        $this->assertInstanceOf(HookRegistry::class, $registry);
    }

    public function testLoadsHooksFromJsonConfig(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        $config = [
            'PreToolUse' => [
                [
                    'matcher' => 'Bash',
                    'hooks' => [
                        [
                            'type' => 'command',
                            'command' => 'echo test',
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($configPath, json_encode($config));

        $reloader = new HookReloader($configPath);
        $registry = $reloader->currentRegistry();

        $stats = $registry->getStatistics();
        $this->assertArrayHasKey('PreToolUse', $stats['events']);
        $this->assertEquals(1, $stats['events']['PreToolUse']['hook_count']);
    }

    public function testPluginConfigMergesIntoRegistry(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        $pluginPath = $this->tempDir . '/plugin_hooks.json';

        $mainConfig = [
            'PreToolUse' => [
                [
                    'matcher' => 'Bash',
                    'hooks' => [
                        ['type' => 'command', 'command' => 'echo main'],
                    ],
                ],
            ],
        ];

        $pluginConfig = [
            'PostToolUse' => [
                [
                    'matcher' => 'Read',
                    'hooks' => [
                        ['type' => 'command', 'command' => 'echo plugin'],
                    ],
                ],
            ],
        ];

        file_put_contents($configPath, json_encode($mainConfig));
        file_put_contents($pluginPath, json_encode($pluginConfig));

        $reloader = new HookReloader($configPath, $pluginPath);
        $registry = $reloader->currentRegistry();

        $stats = $registry->getStatistics();
        $this->assertArrayHasKey('PreToolUse', $stats['events']);
        $this->assertArrayHasKey('PostToolUse', $stats['events']);
    }

    public function testPluginConfigMtimeTriggersReload(): void
    {
        $configPath = $this->tempDir . '/hooks.json';
        $pluginPath = $this->tempDir . '/plugin_hooks.json';

        file_put_contents($configPath, json_encode([]));
        file_put_contents($pluginPath, json_encode([]));

        $reloader = new HookReloader($configPath, $pluginPath);
        $first = $reloader->currentRegistry();

        // Touch plugin config only
        touch($pluginPath, time() + 10);
        clearstatcache();

        $second = $reloader->currentRegistry();
        $this->assertNotSame($first, $second);
    }
}
