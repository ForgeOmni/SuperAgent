<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\AuthenticationException;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Auth\DeviceCodeFlow;
use SuperAgent\Auth\DeviceCodeResponse;
use SuperAgent\Auth\QwenCodeCredentials;
use SuperAgent\Auth\TokenResponse;
use SuperAgent\CLI\Commands\AuthCommand;
use SuperAgent\CLI\Terminal\Renderer;

class AuthCommandQwenCodeTest extends TestCase
{
    private string $tmpHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpHome = sys_get_temp_dir() . '/superagent-qc-login-' . bin2hex(random_bytes(4));
        mkdir($this->tmpHome, 0755, true);
        putenv('HOME=' . $this->tmpHome);
        putenv('SUPERAGENT_NO_BROWSER=1');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpHome);
        putenv('HOME');
        putenv('SUPERAGENT_NO_BROWSER');
        parent::tearDown();
    }

    public function test_login_stores_access_token_and_resource_url(): void
    {
        $flow = new FakeQwenDeviceCodeFlow(new TokenResponse(
            accessToken: 'qwen-tok',
            tokenType: 'bearer',
            scope: 'openid profile email model.completion',
            refreshToken: 'qwen-ref',
            expiresIn: 3600,
            extra: ['resource_url' => 'https://portal.qwen.ai/v1'],
        ));

        $store = $this->testStore();
        $creds = new QwenCodeCredentials($store);

        $cmd = new TestableAuthCommandForQwen(new Renderer(), $store);
        ob_start();
        $code = $cmd->callLoginQwenCode($creds, $flow);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Logged in to Qwen Code', $out);
        $this->assertStringContainsString('portal.qwen.ai', $out, 'resource_url hint should surface');

        $loaded = $creds->load();
        $this->assertNotNull($loaded);
        $this->assertSame('qwen-tok', $loaded['access_token']);
        $this->assertSame('qwen-ref', $loaded['refresh_token']);
        $this->assertSame('https://portal.qwen.ai/v1', $loaded['resource_url']);
        $this->assertSame(
            ['openid', 'profile', 'email', 'model.completion'],
            $loaded['scopes'],
        );
    }

    public function test_login_stores_without_resource_url_when_token_omits_it(): void
    {
        // Some accounts won't come back with a resource_url — the
        // provider falls back to the default compatible-mode URL.
        $flow = new FakeQwenDeviceCodeFlow(new TokenResponse(
            accessToken: 'qwen-tok',
            tokenType: 'bearer',
            scope: '',
            refreshToken: 'qwen-ref',
            expiresIn: 600,
            extra: null,
        ));

        $store = $this->testStore();
        $creds = new QwenCodeCredentials($store);

        $cmd = new TestableAuthCommandForQwen(new Renderer(), $store);
        ob_start();
        $code = $cmd->callLoginQwenCode($creds, $flow);
        ob_end_clean();

        $this->assertSame(0, $code);
        $loaded = $creds->load();
        $this->assertNotNull($loaded);
        $this->assertNull($loaded['resource_url']);
    }

    public function test_login_failure_returns_1(): void
    {
        $flow = new FakeQwenDeviceCodeFlow(null, new AuthenticationException('User denied'));

        $store = $this->testStore();
        $creds = new QwenCodeCredentials($store);

        $cmd = new TestableAuthCommandForQwen(new Renderer(), $store);
        ob_start();
        $code = $cmd->callLoginQwenCode($creds, $flow);
        $out = ob_get_clean();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Login failed', $out);
        $this->assertNull($creds->load());
    }

    public function test_logout_deletes_qwen_code_credentials(): void
    {
        $store = $this->testStore();
        $creds = new QwenCodeCredentials($store);
        $creds->save(['access_token' => 't', 'resource_url' => 'https://portal.qwen.ai/v1']);
        $this->assertNotNull($creds->load());

        $cmd = new AuthCommand(new Renderer(), $store);
        ob_start();
        $code = $cmd->execute(['auth_args' => ['logout', 'qwen-code']]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertNull($creds->load());
    }

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

final class TestableAuthCommandForQwen extends AuthCommand
{
    public function callLoginQwenCode(
        ?QwenCodeCredentials $creds = null,
        ?DeviceCodeFlow $flow = null,
    ): int {
        return $this->loginQwenCode($creds, $flow);
    }
}

final class FakeQwenDeviceCodeFlow extends DeviceCodeFlow
{
    public function __construct(
        private ?TokenResponse $token = null,
        private ?\Throwable $toThrow = null,
    ) {
        parent::__construct(
            clientId: 'test-qwen-client',
            deviceCodeUrl: 'https://invalid.test/',
            tokenUrl: 'https://invalid.test/',
            pkceCodeVerifier: 'verifier',
            pkceCodeChallenge: 'challenge',
            pkceChallengeMethod: 'S256',
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
        throw new \LogicException('FakeQwenDeviceCodeFlow: requestDeviceCode should not be called directly');
    }

    public function pollForToken(DeviceCodeResponse $deviceCode): TokenResponse
    {
        throw new \LogicException('FakeQwenDeviceCodeFlow: pollForToken should not be called directly');
    }
}
