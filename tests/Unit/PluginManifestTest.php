<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Plugins\MarketplaceManifest;
use SuperAgent\Plugins\PluginManifest;

/**
 * Verifies the unified PluginManifest + MarketplaceManifest schema reads
 * BOTH SuperAgent's legacy shape AND ruflo / Claude Code's spec shape.
 */
final class PluginManifestTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sa-pluginmanifest-' . bin2hex(random_bytes(3));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function test_legacy_superagent_manifest_loads_unchanged(): void
    {
        $f = $this->tmp . '/plugin.json';
        file_put_contents($f, json_encode([
            'name'               => 'my-plugin',
            'version'            => '1.2.3',
            'description'        => 'desc',
            'enabled_by_default' => true,
            'skills_dir'         => 'capabilities',
            'hooks_file'         => 'wire.json',
            'mcp_file'           => 'servers.json',
        ]));

        $m = PluginManifest::fromJsonFile($f);
        $this->assertSame('my-plugin', $m->name);
        $this->assertSame('1.2.3', $m->version);
        $this->assertTrue($m->enabledByDefault);
        $this->assertSame('capabilities', $m->skillsDir);
        $this->assertSame('wire.json', $m->hooksFile);
        $this->assertSame('servers.json', $m->mcpFile);
        $this->assertSame([], $m->keywords);
        $this->assertNull($m->author);
    }

    public function test_ruflo_shape_manifest_loads_with_author_object(): void
    {
        $f = $this->tmp . '/plugin.json';
        file_put_contents($f, json_encode([
            'name'        => 'ruflo-sparc',
            'version'     => '0.1.0',
            'description' => 'SPARC methodology — phases with gate checks',
            'author'      => ['name' => 'ruvnet', 'url' => 'https://github.com/ruvnet'],
            'homepage'    => 'https://github.com/ruvnet/ruflo',
            'license'     => 'MIT',
            'keywords'    => ['ruflo', 'sparc', 'methodology'],
        ]));

        $m = PluginManifest::fromJsonFile($f);
        $this->assertSame('ruflo-sparc', $m->name);
        $this->assertSame('0.1.0', $m->version);
        $this->assertSame('ruvnet', $m->author);
        $this->assertSame('https://github.com/ruvnet', $m->authorUrl);
        $this->assertSame('https://github.com/ruvnet/ruflo', $m->homepage);
        $this->assertSame('MIT', $m->license);
        $this->assertSame(['ruflo', 'sparc', 'methodology'], $m->keywords);
        // Defaults preserved for legacy fields:
        $this->assertSame('skills', $m->skillsDir);
        $this->assertSame('agents', $m->agentsDir);
        $this->assertSame('commands', $m->commandsDir);
    }

    public function test_string_author_normalizes(): void
    {
        $m = PluginManifest::fromArray([
            'name'    => 'p',
            'version' => '1.0.0',
            'author'  => 'Alice',
        ]);
        $this->assertSame('Alice', $m->author);
        $this->assertNull($m->authorUrl);
    }

    public function test_missing_version_throws(): void
    {
        // SuperAgent's legacy contract — version is required. Every ruflo
        // plugin we've inspected declares one, so this isn't an interop
        // blocker; we keep the strict check to preserve back-compat.
        $this->expectException(\InvalidArgumentException::class);
        PluginManifest::fromArray(['name' => 'no-version']);
    }

    public function test_discover_prefers_claude_plugin_subdir(): void
    {
        $root = $this->tmp . '/myplugin';
        @mkdir($root . '/.claude-plugin', 0755, true);
        file_put_contents($root . '/.claude-plugin/plugin.json', json_encode(['name' => 'a', 'version' => '1']));
        file_put_contents($root . '/plugin.json', json_encode(['name' => 'b', 'version' => '2']));

        $found = PluginManifest::discoverManifestPath($root);
        $this->assertNotNull($found);
        $this->assertStringEndsWith('.claude-plugin' . DIRECTORY_SEPARATOR . 'plugin.json', $found);

        $m = PluginManifest::fromJsonFile($found);
        $this->assertSame('a', $m->name);
    }

    public function test_discover_falls_back_to_root(): void
    {
        $root = $this->tmp . '/legacy';
        @mkdir($root, 0755, true);
        file_put_contents($root . '/plugin.json', json_encode(['name' => 'legacy', 'version' => '0.1']));

        $found = PluginManifest::discoverManifestPath($root);
        $this->assertSame(realpath($root . '/plugin.json'), realpath($found));
    }

    public function test_discover_returns_null_when_neither_exists(): void
    {
        $root = $this->tmp . '/empty';
        @mkdir($root, 0755, true);
        $this->assertNull(PluginManifest::discoverManifestPath($root));
    }

    public function test_real_ruflo_plugin_loads(): void
    {
        $rufloSparc = 'C:/Users/mlizp/ruflo/plugins/ruflo-sparc';
        if (!is_dir($rufloSparc)) {
            $this->markTestSkipped('ruflo not present at expected path; environment-specific.');
        }
        $found = PluginManifest::discoverManifestPath($rufloSparc);
        $this->assertNotNull($found, 'should discover .claude-plugin/plugin.json');

        $m = PluginManifest::fromJsonFile($found);
        $this->assertSame('ruflo-sparc', $m->name);
        $this->assertNotEmpty($m->keywords);
    }

    public function test_real_ruflo_marketplace_loads(): void
    {
        $market = 'C:/Users/mlizp/ruflo/.claude-plugin/marketplace.json';
        if (!is_file($market)) {
            $this->markTestSkipped('ruflo marketplace not present.');
        }

        $mm = MarketplaceManifest::fromJsonFile($market);
        $this->assertSame('ruflo', $mm->name);
        $this->assertSame('ruvnet', $mm->ownerName);
        $this->assertGreaterThan(20, count($mm->plugins));

        // Every entry should resolve to a real directory inside the ruflo monorepo.
        foreach ($mm->plugins as $entry) {
            $resolved = $entry->resolvedPath();
            $this->assertDirectoryExists($resolved, "plugin {$entry->name} source should resolve to a directory");
        }
    }

    public function test_marketplace_synthetic(): void
    {
        $marketDir = $this->tmp . '/mkt';
        @mkdir($marketDir . '/plugins/p1', 0755, true);
        @mkdir($marketDir . '/plugins/p2', 0755, true);
        $market = $marketDir . '/marketplace.json';
        file_put_contents($market, json_encode([
            'name'        => 'mymkt',
            'description' => 'test mkt',
            'owner'       => ['name' => 'me', 'url' => 'https://example.com'],
            'plugins'     => [
                ['name' => 'p1', 'source' => './plugins/p1', 'description' => 'one'],
                ['name' => 'p2', 'source' => './plugins/p2'],
                ['source' => './plugins/p3'], // missing name — should be skipped
            ],
        ]));

        $mm = MarketplaceManifest::fromJsonFile($market);
        $this->assertSame('mymkt', $mm->name);
        $this->assertSame('me', $mm->ownerName);
        $this->assertCount(2, $mm->plugins);
        $this->assertSame('p1', $mm->plugins[0]->name);
        $this->assertSame(realpath($marketDir . '/plugins/p1'), realpath($mm->plugins[0]->resolvedPath()));
    }

    private function rrm(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($p) ? $this->rrm($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
