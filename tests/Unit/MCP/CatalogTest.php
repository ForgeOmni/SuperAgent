<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\MCP;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\Catalog;

class CatalogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/catalog-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_loads_basic_catalog(): void
    {
        $this->write([
            'mcpServers' => [
                'sqlite' => ['command' => 'uvx', 'args' => ['mcp-server-sqlite']],
                'brave'  => ['command' => 'npx', 'args' => ['@brave/mcp'], 'env' => ['BRAVE_API_KEY' => 'k']],
            ],
        ]);

        $c = new Catalog($this->path);
        $this->assertSame(['sqlite', 'brave'], $c->names());
        $this->assertTrue($c->has('sqlite'));
        $this->assertFalse($c->has('missing'));

        $sqlite = $c->get('sqlite');
        $this->assertSame('stdio', $sqlite['type']);
        $this->assertSame('uvx', $sqlite['command']);
        $this->assertSame(['mcp-server-sqlite'], $sqlite['args']);
        $this->assertSame([], $sqlite['env']);

        $brave = $c->get('brave');
        $this->assertSame(['BRAVE_API_KEY' => 'k'], $brave['env']);
    }

    public function test_defaults_stdio_type(): void
    {
        $this->write([
            'mcpServers' => [
                'x' => ['command' => 'uvx'],
            ],
        ]);
        $c = new Catalog($this->path);
        $this->assertSame('stdio', $c->get('x')['type']);
    }

    public function test_http_type_preserved(): void
    {
        $this->write([
            'mcpServers' => [
                'api' => ['type' => 'http', 'command' => 'https://example.com/mcp'],
            ],
        ]);
        $c = new Catalog($this->path);
        $this->assertSame('http', $c->get('api')['type']);
    }

    public function test_missing_file_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/catalog not found/');
        new Catalog('/nonexistent/path.json');
    }

    public function test_malformed_json_throws(): void
    {
        file_put_contents($this->path, '{not json');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/malformed/');
        new Catalog($this->path);
    }

    public function test_missing_mcpservers_key_throws(): void
    {
        $this->write(['other' => 'shape']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing mcpServers/');
        new Catalog($this->path);
    }

    public function test_subset_preserves_input_order(): void
    {
        $this->write([
            'mcpServers' => [
                'a' => ['command' => 'x'],
                'b' => ['command' => 'y'],
                'c' => ['command' => 'z'],
            ],
        ]);
        $c = new Catalog($this->path);
        $out = $c->subset(['c', 'a']);
        $this->assertSame(['c', 'a'], array_keys($out));
    }

    public function test_subset_with_unknown_name_throws(): void
    {
        $this->write(['mcpServers' => ['a' => ['command' => 'x']]]);
        $c = new Catalog($this->path);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown MCP server: missing/');
        $c->subset(['a', 'missing']);
    }

    public function test_domain_lookup(): void
    {
        $this->write([
            'mcpServers' => [
                'a' => ['command' => 'x'],
                'b' => ['command' => 'y'],
            ],
            'domains' => [
                'baseline' => ['a'],
                'all'      => ['a', 'b'],
            ],
        ]);
        $c = new Catalog($this->path);
        $this->assertSame(['a'], $c->domain('baseline'));
        $this->assertSame(['a', 'b'], $c->domain('all'));
        $this->assertSame([], $c->domain('unknown'));

        $this->assertSame(['a', 'b'], array_keys($c->domainServers('all')));
    }

    private function write(array $data): void
    {
        file_put_contents($this->path, json_encode($data));
    }
}
