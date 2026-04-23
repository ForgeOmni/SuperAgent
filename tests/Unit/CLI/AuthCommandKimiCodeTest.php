<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\AuthenticationException;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Auth\DeviceCodeFlow;
use SuperAgent\Auth\DeviceCodeResponse;
use SuperAgent\Auth\KimiCodeCredentials;
use SuperAgent\Auth\TokenResponse;
use SuperAgent\CLI\Commands\AuthCommand;
use SuperAgent\CLI\Terminal\Renderer;

/**
 * Locks in the Kimi Code login / logout path.
 *
 * We can't actually hit Moonshot's OAuth endpoints from a unit test,
 * so we inject a stub `DeviceCodeFlow` that returns a canned token.
 * The real wire semantics (RFC 8628 polling, slow_down handling,
 * expiry) are covered by `DeviceCodeFlow`'s own tests — this file
 * focuses on the glue: AuthCommand → flow → KimiCodeCredentials.
 */
class AuthCommandKimiCodeTest extends TestCase
{
    private string $tmpHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpHome = sys_get_temp_dir() . '/superagent-kc-login-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome, 0755, true);
        putenv('HOME=' . $this->tmpHome);
        // DeviceCodeFlow suppresses the browser launch under PHPUNIT_RUNNING
        // already, but pin it explicitly so this test doesn't depend on
        // the phpunit bootstrap ordering.
        putenv('SUPERAGENT_NO_BROWSER=1');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpHome);
        putenv('HOME');
        putenv('SUPERAGENT_NO_BROWSER');
        parent::tearDown();
    }

    public function test_login_stores_access_token_via_kimicodecredentials(): void
    {
        $flow = new FakeDeviceCodeFlow(new TokenResponse(
            accessToken: 'tok-fresh',
            tokenType: 'bearer',
            scope: 'openid profile',
            refreshToken: 'ref-abc',
            expiresIn: 3600,
        ));
        $store = $this->testStore();
        $creds = new KimiCodeCredentials($store);

        $cmd = new TestableAuthCommand(new Renderer(), $store);
        ob_start();
        $code = $cmd->callLoginKimiCode($creds, $flow);
        $output = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Logged in', $output);

        $loaded = $creds->load();
        $this->assertNotNull($loaded);
        $this->assertSame('tok-fresh', $loaded['access_token']);
        $this->assertSame('ref-abc', $loaded['refresh_token']);
        $this->assertSame(['openid', 'profile'], $loaded['scopes']);
        $this->assertNotNull($loaded['expires_at']);
        $this->assertGreaterThanOrEqual(time() + 3500, $loaded['expires_at']);
    }

    public function test_login_returns_1_when_device_flow_raises(): void
    {
        $flow = new FakeDeviceCodeFlow(null, new AuthenticationException('User denied authorization'));
        $store = $this->testStore();
        $creds = new KimiCodeCredentials($store);

        $cmd = new TestableAuthCommand(new Renderer(), $store);
        ob_start();
        $code = $cmd->callLoginKimiCode($creds, $flow);
        $out = ob_get_clean();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Login failed', $out);
        $this->assertStringContainsString('User denied', $out);
        $this->assertNull($creds->load());
    }

    public function test_login_handles_token_without_refresh_token(): void
    {
        // Some OAuth servers don't issue refresh tokens for device
        // flows — save() should still persist the access token.
        $flow = new FakeDeviceCodeFlow(new TokenResponse(
            accessToken: 'tok-no-refresh',
            tokenType: 'bearer',
            scope: '',
            refreshToken: null,
            expiresIn: 600,
        ));
        $store = $this->testStore();
        $creds = new KimiCodeCredentials($store);

        $cmd = new TestableAuthCommand(new Renderer(), $store);
        ob_start();
        $code = $cmd->callLoginKimiCode($creds, $flow);
        ob_end_clean();

        $this->assertSame(0, $code);
        $loaded = $creds->load();
        $this->assertSame('tok-no-refresh', $loaded['access_token']);
        $this->assertNull($loaded['refresh_token']);
        $this->assertSame([], $loaded['scopes']);
    }

    public function test_logout_deletes_kimi_code_credentials(): void
    {
        $store = $this->testStore();
        $creds = new KimiCodeCredentials($store);
        $creds->save(['access_token' => 't']);
        $this->assertNotNull($creds->load());

        $cmd = new AuthCommand(new Renderer(), $store);
        ob_start();
        $code = $cmd->execute(['auth_args' => ['logout', 'kimi-code']]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertNull($creds->load());
    }

    public function test_login_kimi_alias_also_works(): void
    {
        // `superagent auth login kimi` (without -code) should be
        // accepted as an alias — matches the lenient matching of the
        // other providers (claude/claude-code/anthropic).
        $flow = new FakeDeviceCodeFlow(new TokenResponse(
            accessToken: 'tok-alias', tokenType: 'bearer', scope: '',
            refreshToken: null, expiresIn: 60,
        ));
        $store = $this->testStore();
        $creds = new KimiCodeCredentials($store);

        $cmd = new TestableAuthCommand(new Renderer(), $store);
        ob_start();
        $code = $cmd->callLoginKimiCode($creds, $flow);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertNotNull($creds->load());
    }

    // ── helpers ────────────────────────────────────────────────────

    private function testStore(): CredentialStore
    {
        return new CredentialStore(
            baseDir: $this->tmpHome . '/.superagent/credentials',
            encryptionEnabled: false,
        );
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}

/**
 * Exposes the protected loginKimiCode() to tests — we deliberately
 * kept it protected on the parent so production call-sites go through
 * `login('kimi-code')` and get the real DeviceCodeFlow.
 */
final class TestableAuthCommand extends AuthCommand
{
    public function callLoginKimiCode(
        ?KimiCodeCredentials $creds = null,
        ?DeviceCodeFlow $flow = null,
    ): int {
        return $this->loginKimiCode($creds, $flow);
    }
}

/**
 * Stub DeviceCodeFlow that bypasses network + polling and returns a
 * pre-canned TokenResponse. Lets us exercise the glue without
 * mocking HTTP at four layers.
 */
final class FakeDeviceCodeFlow extends DeviceCodeFlow
{
    public function __construct(
        private ?TokenResponse $token = null,
        private ?\Throwable $toThrow = null,
    ) {
        parent::__construct(
            clientId: 'test-client',
            deviceCodeUrl: 'https://invalid.test/',
            tokenUrl: 'https://invalid.test/',
        );
    }

    public function authenticate(): TokenResponse
    {
        if ($this->toThrow !== null) {
            throw $this->toThrow;
        }
        return $this->token;
    }

    public function requestDeviceCode(): DeviceCodeResponse
    {
        throw new \LogicException('FakeDeviceCodeFlow: requestDeviceCode should not be called directly');
    }

    public function pollForToken(DeviceCodeResponse $deviceCode): TokenResponse
    {
        throw new \LogicException('FakeDeviceCodeFlow: pollForToken should not be called directly');
    }
}
