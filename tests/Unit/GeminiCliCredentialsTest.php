<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\GeminiCliCredentials;

class GeminiCliCredentialsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/gemini_creds_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);

        putenv('GEMINI_API_KEY');
        putenv('GOOGLE_API_KEY');
    }

    public function test_reads_oauth_tokens_from_snake_case_keys(): void
    {
        $path = $this->tmpDir . '/oauth_creds.json';
        file_put_contents($path, json_encode([
            'access_token' => 'ya29.a0XYZ',
            'refresh_token' => '1//0-refresh',
            'expires_at' => time() + 3600, // seconds → should be converted to ms
        ]));

        $reader = new GeminiCliCredentials($path);
        $this->assertTrue($reader->exists());
        $creds = $reader->read();
        $this->assertNotNull($creds);
        $this->assertSame('oauth', $creds['mode']);
        $this->assertSame('ya29.a0XYZ', $creds['access_token']);
        $this->assertSame('1//0-refresh', $creds['refresh_token']);
        $this->assertGreaterThan(1_000_000_000_000, $creds['expires_at']); // ms
        $this->assertFalse($reader->isExpired($creds));
    }

    public function test_reads_oauth_tokens_from_camel_case_keys(): void
    {
        $path = $this->tmpDir . '/creds.json';
        file_put_contents($path, json_encode([
            'accessToken' => 'ya29.CAMEL',
            'refreshToken' => 'refresh-camel',
            'expiresAt' => (time() + 60) * 1000, // already ms
        ]));

        $reader = new GeminiCliCredentials($path);
        $creds = $reader->read();
        $this->assertSame('oauth', $creds['mode']);
        $this->assertSame('ya29.CAMEL', $creds['access_token']);
        $this->assertSame('refresh-camel', $creds['refresh_token']);
    }

    public function test_detects_expired_oauth_token(): void
    {
        $path = $this->tmpDir . '/oauth_creds.json';
        file_put_contents($path, json_encode([
            'access_token' => 'stale',
            'expires_at' => time() - 3600, // in the past
        ]));

        $reader = new GeminiCliCredentials($path);
        $creds = $reader->read();
        $this->assertTrue($reader->isExpired($creds));
    }

    public function test_reads_api_key_from_settings_json(): void
    {
        $path = $this->tmpDir . '/settings.json';
        file_put_contents($path, json_encode(['apiKey' => 'AIzaSy-XYZ']));

        $reader = new GeminiCliCredentials($path);
        $creds = $reader->read();
        $this->assertSame('api_key', $creds['mode']);
        $this->assertSame('AIzaSy-XYZ', $creds['api_key']);
        $this->assertFalse($reader->isExpired($creds));
    }

    public function test_falls_back_to_gemini_env_var(): void
    {
        putenv('GEMINI_API_KEY=AIzaSy-ENV');
        $reader = new GeminiCliCredentials($this->tmpDir . '/nonexistent.json');
        $this->assertTrue($reader->exists());
        $creds = $reader->read();
        $this->assertSame('api_key', $creds['mode']);
        $this->assertSame('AIzaSy-ENV', $creds['api_key']);
        $this->assertSame('env', $creds['source']);
    }

    public function test_falls_back_to_google_env_var(): void
    {
        putenv('GOOGLE_API_KEY=AIzaSy-GOOG');
        $reader = new GeminiCliCredentials($this->tmpDir . '/nonexistent.json');
        $creds = $reader->read();
        $this->assertSame('AIzaSy-GOOG', $creds['api_key']);
    }

    public function test_returns_null_when_no_sources(): void
    {
        $reader = new GeminiCliCredentials($this->tmpDir . '/none.json');
        $this->assertFalse($reader->exists());
        $this->assertNull($reader->read());
    }

    public function test_first_candidate_wins(): void
    {
        $oauth = $this->tmpDir . '/oauth_creds.json';
        $settings = $this->tmpDir . '/settings.json';
        file_put_contents($oauth, json_encode(['access_token' => 'OAUTH-WIN']));
        file_put_contents($settings, json_encode(['apiKey' => 'KEY-LOSE']));

        $reader = new GeminiCliCredentials($oauth, $settings);
        $creds = $reader->read();
        $this->assertSame('oauth', $creds['mode']);
        $this->assertSame('OAUTH-WIN', $creds['access_token']);
    }
}
