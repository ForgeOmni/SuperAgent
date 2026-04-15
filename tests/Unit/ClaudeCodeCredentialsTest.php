<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\ClaudeCodeCredentials;

/**
 * Unit tests for the Claude Code credential reader. Only filesystem parsing
 * and expiry arithmetic are tested here — token refresh involves a real HTTP
 * round-trip to Anthropic's OAuth endpoint and is covered by integration tests.
 */
class ClaudeCodeCredentialsTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/claude_creds_' . getmypid() . '_' . mt_rand() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function test_exists_false_when_file_missing(): void
    {
        $c = new ClaudeCodeCredentials('/nonexistent/path/' . mt_rand() . '.json');
        $this->assertFalse($c->exists());
        $this->assertNull($c->read());
    }

    public function test_read_returns_all_fields(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'claudeAiOauth' => [
                'accessToken' => 'sk-ant-oat01-abc',
                'refreshToken' => 'sk-ant-ort01-xyz',
                'expiresAt' => 1761100000000,
                'scopes' => ['user:inference', 'user:profile'],
                'subscriptionType' => 'max',
            ],
        ]));

        $creds = (new ClaudeCodeCredentials($this->tmpFile))->read();
        $this->assertIsArray($creds);
        $this->assertSame('sk-ant-oat01-abc', $creds['access_token']);
        $this->assertSame('sk-ant-ort01-xyz', $creds['refresh_token']);
        $this->assertSame(1761100000000, $creds['expires_at']);
        $this->assertSame(['user:inference', 'user:profile'], $creds['scopes']);
        $this->assertSame('max', $creds['subscription']);
    }

    public function test_read_handles_missing_optional_fields(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'claudeAiOauth' => ['accessToken' => 'only-access'],
        ]));

        $creds = (new ClaudeCodeCredentials($this->tmpFile))->read();
        $this->assertSame('only-access', $creds['access_token']);
        $this->assertNull($creds['refresh_token']);
        $this->assertNull($creds['expires_at']);
        $this->assertSame([], $creds['scopes']);
        $this->assertNull($creds['subscription']);
    }

    public function test_read_returns_null_when_access_token_missing(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'claudeAiOauth' => ['refreshToken' => 'only-refresh'],
        ]));

        $this->assertNull((new ClaudeCodeCredentials($this->tmpFile))->read());
    }

    public function test_read_returns_null_when_block_missing(): void
    {
        file_put_contents($this->tmpFile, json_encode(['someOtherKey' => 'value']));
        $this->assertNull((new ClaudeCodeCredentials($this->tmpFile))->read());
    }

    public function test_read_returns_null_when_json_invalid(): void
    {
        file_put_contents($this->tmpFile, 'this is not JSON');
        $this->assertNull((new ClaudeCodeCredentials($this->tmpFile))->read());
    }

    public function test_is_expired_true_when_past(): void
    {
        $past = (time() - 60) * 1000;
        $c = new ClaudeCodeCredentials($this->tmpFile);
        $this->assertTrue($c->isExpired(['expires_at' => $past]));
    }

    public function test_is_expired_true_within_skew_window(): void
    {
        $future = (time() + 30) * 1000;
        $c = new ClaudeCodeCredentials($this->tmpFile);
        $this->assertTrue($c->isExpired(['expires_at' => $future], skewSeconds: 60));
    }

    public function test_is_expired_false_well_in_future(): void
    {
        $future = (time() + 3600) * 1000;
        $c = new ClaudeCodeCredentials($this->tmpFile);
        $this->assertFalse($c->isExpired(['expires_at' => $future]));
    }

    public function test_is_expired_false_when_expires_at_missing(): void
    {
        $c = new ClaudeCodeCredentials($this->tmpFile);
        $this->assertFalse($c->isExpired(['access_token' => 'x']));
    }

    public function test_path_accessor_returns_given_path(): void
    {
        $c = new ClaudeCodeCredentials('/custom/path.json');
        $this->assertSame('/custom/path.json', $c->path());
    }
}
