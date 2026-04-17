<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Plugins\LoadedPlugin;
use SuperAgent\Plugins\PluginLoader;
use SuperAgent\Plugins\PluginManifest;

class PluginLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/superagent_plugin_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    // ── PluginManifest ───────────────────────────────────────────

    public function testManifestFromArrayValid(): void
    {
        $manifest = PluginManifest::fromArray([
            'name' => 'test-plugin',
            'version' => '1.0.0',
            'description' => 'A test plugin',
        ]);

        $this->assertSame('test-plugin', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('A test plugin', $manifest->description);
        $this->assertFalse($manifest->enabledByDefault);
        $this->assertSame('skills', $manifest->skillsDir);
        $this->assertSame('hooks.json', $manifest->hooksFile);
        $this->assertSame('mcp.json', $manifest->mcpFile);
    }

    public function testManifestFromArrayMissingNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginManifest::fromArray(['version' => '1.0.0']);
    }

    public function testManifestFromArrayMissingVersionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginManifest::fromArray(['name' => 'x']);
    }

    public function testManifestFromArrayCustomFields(): void
    {
        $manifest = PluginManifest::fromArray([
            'name' => 'custom',
            'version' => '2.0.0',
            'enabled_by_default' => true,
            'skills_dir' => 'my-skills',
            'hooks_file' => 'my-hooks.json',
            'mcp_file' => 'my-mcp.json',
        ]);

        $this->assertTrue($manifest->enabledByDefault);
        $this->assertSame('my-skills', $manifest->skillsDir);
        $this->assertSame('my-hooks.json', $manifest->hooksFile);
        $this->assertSame('my-mcp.json', $manifest->mcpFile);
    }

    public function testManifestFromJsonFile(): void
    {
        $path = $this->tmpDir . '/plugin.json';
        file_put_contents($path, json_encode([
            'name' => 'json-plugin',
            'version' => '0.1.0',
            'description' => 'From file',
        ]));

        $manifest = PluginManifest::fromJsonFile($path);

        $this->assertSame('json-plugin', $manifest->name);
        $this->assertSame('0.1.0', $manifest->version);
    }

    public function testManifestFromJsonFileMissingThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginManifest::fromJsonFile($this->tmpDir . '/nonexistent.json');
    }

    public function testManifestFromJsonFileInvalidJsonThrows(): void
    {
        $path = $this->tmpDir . '/bad.json';
        file_put_contents($path, 'not json');

        $this->expectException(\RuntimeException::class);
        PluginManifest::fromJsonFile($path);
    }

    // ── Discovery & Loading ──────────────────────────────────────

    public function testDiscoverFindsPlugins(): void
    {
        $this->createPluginDir('alpha', ['name' => 'alpha', 'version' => '1.0.0']);
        $this->createPluginDir('beta', ['name' => 'beta', 'version' => '2.0.0']);

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);

        $plugins = $loader->getPlugins();

        $this->assertCount(2, $plugins);
        $this->assertArrayHasKey('alpha', $plugins);
        $this->assertArrayHasKey('beta', $plugins);
    }

    public function testDiscoverSkipsInvalidDirs(): void
    {
        // Dir without plugin.json
        mkdir($this->tmpDir . '/no-manifest', 0755, true);
        $this->createPluginDir('valid', ['name' => 'valid', 'version' => '1.0.0']);

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);

        $this->assertCount(1, $loader->getPlugins());
    }

    public function testDiscoverSkipsNonexistentPaths(): void
    {
        $loader = new PluginLoader();
        $loader->discover(['/nonexistent/path/12345']);

        $this->assertCount(0, $loader->getPlugins());
    }

    public function testLoadPluginReturnsNullWithoutManifest(): void
    {
        $dir = $this->tmpDir . '/empty';
        mkdir($dir, 0755, true);

        $loader = new PluginLoader();
        $this->assertNull($loader->loadPlugin($dir));
    }

    public function testLoadPluginLoadsSkills(): void
    {
        $this->createPluginDir('with-skills', [
            'name' => 'with-skills',
            'version' => '1.0.0',
        ]);
        $skillsDir = $this->tmpDir . '/with-skills/skills';
        mkdir($skillsDir, 0755, true);
        file_put_contents($skillsDir . '/deploy.md', '# Deploy Skill');
        file_put_contents($skillsDir . '/lint.md', '# Lint Skill');

        $loader = new PluginLoader();
        $plugin = $loader->loadPlugin($this->tmpDir . '/with-skills');

        $this->assertNotNull($plugin);
        $this->assertCount(2, $plugin->skills);
        $names = array_column($plugin->skills, 'name');
        $this->assertContains('deploy', $names);
        $this->assertContains('lint', $names);
    }

    public function testLoadPluginLoadsHooks(): void
    {
        $this->createPluginDir('with-hooks', [
            'name' => 'with-hooks',
            'version' => '1.0.0',
        ]);
        $hooks = [
            ['event' => 'PreToolCall', 'matcher' => 'Bash', 'action' => 'log'],
        ];
        file_put_contents(
            $this->tmpDir . '/with-hooks/hooks.json',
            json_encode(['hooks' => $hooks]),
        );

        $loader = new PluginLoader();
        $plugin = $loader->loadPlugin($this->tmpDir . '/with-hooks');

        $this->assertNotNull($plugin);
        $this->assertCount(1, $plugin->hooks);
        $this->assertSame('PreToolCall', $plugin->hooks[0]['event']);
    }

    public function testLoadPluginLoadsMcpConfigs(): void
    {
        $this->createPluginDir('with-mcp', [
            'name' => 'with-mcp',
            'version' => '1.0.0',
        ]);
        file_put_contents(
            $this->tmpDir . '/with-mcp/mcp.json',
            json_encode(['mcpServers' => ['my-server' => ['command' => 'node', 'args' => ['index.js']]]]),
        );

        $loader = new PluginLoader();
        $plugin = $loader->loadPlugin($this->tmpDir . '/with-mcp');

        $this->assertNotNull($plugin);
        $this->assertArrayHasKey('my-server', $plugin->mcpServers);
    }

    // ── Enable / Disable ─────────────────────────────────────────

    public function testEnabledByDefaultRespected(): void
    {
        $this->createPluginDir('on-by-default', [
            'name' => 'on-by-default',
            'version' => '1.0.0',
            'enabled_by_default' => true,
        ]);

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);

        $enabled = $loader->getEnabledPlugins();
        $this->assertArrayHasKey('on-by-default', $enabled);
    }

    public function testExplicitOverrideDisablesPlugin(): void
    {
        $this->createPluginDir('forced-off', [
            'name' => 'forced-off',
            'version' => '1.0.0',
            'enabled_by_default' => true,
        ]);

        $loader = new PluginLoader(['forced-off' => false]);
        $loader->discover([$this->tmpDir]);

        $this->assertCount(0, $loader->getEnabledPlugins());
    }

    public function testSetEnabledTogglesPlugin(): void
    {
        $this->createPluginDir('toggled', [
            'name' => 'toggled',
            'version' => '1.0.0',
        ]);

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);

        $this->assertCount(0, $loader->getEnabledPlugins());

        $loader->setEnabled('toggled', true);
        $this->assertCount(1, $loader->getEnabledPlugins());

        $loader->setEnabled('toggled', false);
        $this->assertCount(0, $loader->getEnabledPlugins());
    }

    // ── Install / Uninstall ──────────────────────────────────────

    public function testInstallCopiesPluginToTarget(): void
    {
        // Create source plugin
        $source = $this->tmpDir . '/source-plugin';
        mkdir($source, 0755, true);
        file_put_contents($source . '/plugin.json', json_encode([
            'name' => 'installed',
            'version' => '1.0.0',
        ]));
        $skillsDir = $source . '/skills';
        mkdir($skillsDir, 0755, true);
        file_put_contents($skillsDir . '/hello.md', '# Hello');

        $target = $this->tmpDir . '/installed-plugins';

        $loader = new PluginLoader();
        $result = $loader->install($source, $target);

        $this->assertTrue($result);
        $this->assertArrayHasKey('installed', $loader->getPlugins());
        $this->assertFileExists($target . '/installed/plugin.json');
        $this->assertFileExists($target . '/installed/skills/hello.md');
    }

    public function testInstallFailsWithoutManifest(): void
    {
        $source = $this->tmpDir . '/bad-source';
        mkdir($source, 0755, true);

        $loader = new PluginLoader();
        $this->assertFalse($loader->install($source, $this->tmpDir . '/target'));
    }

    public function testUninstallRemovesPlugin(): void
    {
        $this->createPluginDir('removable', [
            'name' => 'removable',
            'version' => '1.0.0',
        ]);

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);
        $this->assertArrayHasKey('removable', $loader->getPlugins());

        $result = $loader->uninstall('removable', $this->tmpDir);
        $this->assertTrue($result);
        $this->assertArrayNotHasKey('removable', $loader->getPlugins());
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/removable');
    }

    public function testUninstallReturnsFalseForMissing(): void
    {
        $loader = new PluginLoader();
        $this->assertFalse($loader->uninstall('nonexistent', $this->tmpDir));
    }

    // ── Collection ───────────────────────────────────────────────

    public function testCollectSkillsFromEnabledPlugins(): void
    {
        $this->createPluginDir('p1', ['name' => 'p1', 'version' => '1.0.0', 'enabled_by_default' => true]);
        mkdir($this->tmpDir . '/p1/skills', 0755, true);
        file_put_contents($this->tmpDir . '/p1/skills/a.md', 'Skill A');

        $this->createPluginDir('p2', ['name' => 'p2', 'version' => '1.0.0']); // disabled
        mkdir($this->tmpDir . '/p2/skills', 0755, true);
        file_put_contents($this->tmpDir . '/p2/skills/b.md', 'Skill B');

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);

        $skills = $loader->collectSkills();
        $this->assertCount(1, $skills);
        $this->assertSame('a', $skills[0]['name']);
        $this->assertSame('p1', $skills[0]['plugin']);
    }

    public function testCollectHooksFromEnabledPlugins(): void
    {
        $this->createPluginDir('hooked', ['name' => 'hooked', 'version' => '1.0.0', 'enabled_by_default' => true]);
        file_put_contents(
            $this->tmpDir . '/hooked/hooks.json',
            json_encode(['hooks' => [['event' => 'PostToolCall', 'action' => 'notify']]]),
        );

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);

        $hooks = $loader->collectHooks();
        $this->assertCount(1, $hooks);
        $this->assertSame('PostToolCall', $hooks[0]['event']);
        $this->assertSame('hooked', $hooks[0]['plugin']);
    }

    public function testCollectMcpConfigsFromEnabledPlugins(): void
    {
        $this->createPluginDir('mcp-plugin', ['name' => 'mcp-plugin', 'version' => '1.0.0', 'enabled_by_default' => true]);
        file_put_contents(
            $this->tmpDir . '/mcp-plugin/mcp.json',
            json_encode(['mcpServers' => ['srv' => ['command' => 'python', 'args' => ['serve.py']]]]),
        );

        $loader = new PluginLoader();
        $loader->discover([$this->tmpDir]);

        $configs = $loader->collectMcpConfigs();
        $this->assertArrayHasKey('mcp-plugin/srv', $configs);
        $this->assertSame('python', $configs['mcp-plugin/srv']['command']);
    }

    // ── LoadedPlugin ─────────────────────────────────────────────

    public function testLoadedPluginWithEnabledReturnsNewInstance(): void
    {
        $manifest = PluginManifest::fromArray(['name' => 'x', 'version' => '1.0.0']);
        $plugin = new LoadedPlugin($manifest, '/tmp/x', false);

        $enabled = $plugin->withEnabled(true);

        $this->assertFalse($plugin->enabled);
        $this->assertTrue($enabled->enabled);
        $this->assertNotSame($plugin, $enabled);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createPluginDir(string $name, array $manifest): void
    {
        $dir = $this->tmpDir . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/plugin.json', json_encode($manifest));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
