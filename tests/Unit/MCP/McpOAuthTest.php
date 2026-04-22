<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\MCP;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\McpOAuth;

/**
 * Storage-layer tests for `McpOAuth`. The wire-level `authenticate()`
 * method hits real OAuth endpoints and belongs in Integration — these
 * tests only verify the caching primitives that every call path uses.
 */
class McpOAuthTest extends TestCase
{
    private string $tmpHome;
    private ?string $origHome;
    private ?string $origProfile;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/superagent_mcpauth_' . bin2hex(random_bytes(4));
        @mkdir($this->tmpHome . '/.superagent', 0755, true);
        $this->origHome = getenv('HOME') ?: null;
        $this->origProfile = getenv('USERPROFILE') ?: null;
        putenv('HOME=' . $this->tmpHome);
        putenv('USERPROFILE=' . $this->tmpHome);
    }

    protected function tearDown(): void
    {
        $path = McpOAuth::tokenStorePath();
        if (is_file($path)) @unlink($path);
        if (is_file($path . '.tmp')) @unlink($path . '.tmp');
        @rmdir($this->tmpHome . '/.superagent');
        @rmdir($this->tmpHome);

        putenv('HOME' . ($this->origHome === null ? '' : '=' . $this->origHome));
        putenv('USERPROFILE' . ($this->origProfile === null ? '' : '=' . $this->origProfile));
    }

    public function test_token_store_path_is_under_superagent(): void
    {
        $path = McpOAuth::tokenStorePath();
        $this->assertStringContainsString('.superagent', str_replace('\\', '/', $path));
        $this->assertStringEndsWith('mcp-auth.json', $path);
    }

    public function test_cached_token_is_null_when_store_missing(): void
    {
        $this->assertNull(McpOAuth::cachedToken('server-a'));
    }

    public function test_store_then_retrieve_valid_token(): void
    {
        McpOAuth::storeToken('server-a', [
            'access_token' => 'abc123',
            'expires_at'   => time() + 3600,
            'refresh_token' => 'refresh-xyz',
        ]);

        $cached = McpOAuth::cachedToken('server-a');
        $this->assertNotNull($cached);
        $this->assertSame('abc123', $cached['access_token']);
        $this->assertGreaterThan(time(), $cached['expires_at']);
    }

    public function test_expired_token_returns_null(): void
    {
        McpOAuth::storeToken('server-b', [
            'access_token' => 'stale',
            'expires_at'   => time() - 100,
        ]);

        $this->assertNull(McpOAuth::cachedToken('server-b'));
    }

    public function test_clear_token_removes_entry(): void
    {
        McpOAuth::storeToken('server-c', [
            'access_token' => 'to-delete',
            'expires_at'   => time() + 3600,
        ]);
        $this->assertNotNull(McpOAuth::cachedToken('server-c'));

        McpOAuth::clearToken('server-c');
        $this->assertNull(McpOAuth::cachedToken('server-c'));
    }

    public function test_multi_server_tokens_coexist(): void
    {
        McpOAuth::storeToken('a', ['access_token' => 'tok-a', 'expires_at' => time() + 3600]);
        McpOAuth::storeToken('b', ['access_token' => 'tok-b', 'expires_at' => time() + 3600]);

        $this->assertSame('tok-a', McpOAuth::cachedToken('a')['access_token']);
        $this->assertSame('tok-b', McpOAuth::cachedToken('b')['access_token']);

        McpOAuth::clearToken('a');
        $this->assertNull(McpOAuth::cachedToken('a'));
        $this->assertSame('tok-b', McpOAuth::cachedToken('b')['access_token']);
    }

    public function test_authenticate_requires_client_id_and_endpoints(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/client_id/');
        McpOAuth::authenticate('server-d', [
            'device_endpoint' => 'https://example/device',
            'token_endpoint'  => 'https://example/token',
            // missing client_id
        ]);
    }
}
