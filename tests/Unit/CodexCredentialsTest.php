<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\CodexCredentials;

/**
 * Unit tests for the Codex credential reader covering both shapes of
 * ~/.codex/auth.json (OAuth via ChatGPT subscription vs. plain API key).
 */
class CodexCredentialsTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/codex_creds_' . getmypid() . '_' . mt_rand() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function test_exists_false_when_file_missing(): void
    {
        $c = new CodexCredentials('/nonexistent/' . mt_rand() . '.json');
        $this->assertFalse($c->exists());
        $this->assertNull($c->read());
    }

    public function test_read_oauth_mode(): void
    {
        $accessJwt = $this->fakeJwt(['exp' => time() + 3600]);
        file_put_contents($this->tmpFile, json_encode([
            'OPENAI_API_KEY' => null,
            'tokens' => [
                'id_token' => 'eyJ.ID.TOKEN',
                'access_token' => $accessJwt,
                'refresh_token' => 'refresh-xyz',
                'account_id' => 'acct_123',
            ],
            'last_refresh' => '2026-04-14T00:00:00Z',
        ]));

        $creds = (new CodexCredentials($this->tmpFile))->read();
        $this->assertSame('oauth', $creds['mode']);
        $this->assertSame($accessJwt, $creds['access_token']);
        $this->assertSame('refresh-xyz', $creds['refresh_token']);
        $this->assertSame('eyJ.ID.TOKEN', $creds['id_token']);
        $this->assertSame('acct_123', $creds['account_id']);
        $this->assertNull($creds['api_key']);
        $this->assertSame('2026-04-14T00:00:00Z', $creds['last_refresh']);
    }

    public function test_read_api_key_mode(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'OPENAI_API_KEY' => 'sk-plain-key',
            'tokens' => null,
        ]));

        $creds = (new CodexCredentials($this->tmpFile))->read();
        $this->assertSame('api_key', $creds['mode']);
        $this->assertSame('sk-plain-key', $creds['api_key']);
        $this->assertNull($creds['access_token']);
        $this->assertNull($creds['refresh_token']);
    }

    public function test_read_prefers_oauth_when_both_present(): void
    {
        $accessJwt = $this->fakeJwt(['exp' => time() + 3600]);
        file_put_contents($this->tmpFile, json_encode([
            'OPENAI_API_KEY' => 'sk-plain',
            'tokens' => ['access_token' => $accessJwt],
        ]));

        $creds = (new CodexCredentials($this->tmpFile))->read();
        $this->assertSame('oauth', $creds['mode']);
    }

    public function test_read_returns_null_when_both_empty(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'OPENAI_API_KEY' => null,
            'tokens' => null,
        ]));
        $this->assertNull((new CodexCredentials($this->tmpFile))->read());
    }

    public function test_read_returns_null_when_invalid_json(): void
    {
        file_put_contents($this->tmpFile, '{not:json');
        $this->assertNull((new CodexCredentials($this->tmpFile))->read());
    }

    public function test_is_expired_api_key_mode_always_false(): void
    {
        $c = new CodexCredentials($this->tmpFile);
        $this->assertFalse($c->isExpired(['mode' => 'api_key', 'api_key' => 'sk-x']));
    }

    public function test_is_expired_true_when_jwt_exp_past(): void
    {
        $c = new CodexCredentials($this->tmpFile);
        $expired = $this->fakeJwt(['exp' => time() - 300]);
        $this->assertTrue($c->isExpired(['mode' => 'oauth', 'access_token' => $expired]));
    }

    public function test_is_expired_true_within_skew(): void
    {
        $c = new CodexCredentials($this->tmpFile);
        $nearExp = $this->fakeJwt(['exp' => time() + 10]);
        $this->assertTrue($c->isExpired(['mode' => 'oauth', 'access_token' => $nearExp], skewSeconds: 60));
    }

    public function test_is_expired_false_when_jwt_valid(): void
    {
        $c = new CodexCredentials($this->tmpFile);
        $fresh = $this->fakeJwt(['exp' => time() + 3600]);
        $this->assertFalse($c->isExpired(['mode' => 'oauth', 'access_token' => $fresh]));
    }

    public function test_is_expired_false_when_no_exp_claim(): void
    {
        $c = new CodexCredentials($this->tmpFile);
        $noExp = $this->fakeJwt(['sub' => 'user-123']);
        $this->assertFalse($c->isExpired(['mode' => 'oauth', 'access_token' => $noExp]));
    }

    public function test_is_expired_true_when_access_token_empty(): void
    {
        $c = new CodexCredentials($this->tmpFile);
        $this->assertTrue($c->isExpired(['mode' => 'oauth', 'access_token' => null]));
    }

    public function test_is_expired_false_for_malformed_jwt(): void
    {
        $c = new CodexCredentials($this->tmpFile);
        $this->assertFalse($c->isExpired(['mode' => 'oauth', 'access_token' => 'not.a.jwt.at.all']));
    }

    /**
     * Build an unsigned JWT (sig section empty) with the given payload.
     * CodexCredentials never verifies signatures — it only parses claims.
     */
    private function fakeJwt(array $claims): string
    {
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = $b64(json_encode($claims));
        return "{$header}.{$payload}.";
    }
}
