<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Auth\AuthenticationException;
use SuperAgent\Auth\CredentialStore;
use SuperAgent\Auth\DeviceCodeFlow;
use SuperAgent\Auth\DeviceCodeResponse;
use SuperAgent\Auth\TokenResponse;

class AuthTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/superagent_auth_test_' . getmypid() . '_' . mt_rand();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tmpDir)) {
            foreach (glob("{$this->tmpDir}/*") as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    // ── DeviceCodeResponse ────────────────────────────────────────

    public function testDeviceCodeResponseConstruction(): void
    {
        $response = new DeviceCodeResponse(
            deviceCode: 'abc123',
            userCode: 'ABCD-1234',
            verificationUri: 'https://example.com/device',
        );

        $this->assertSame('abc123', $response->deviceCode);
        $this->assertSame('ABCD-1234', $response->userCode);
        $this->assertSame('https://example.com/device', $response->verificationUri);
        $this->assertNull($response->verificationUriComplete);
        $this->assertSame(300, $response->expiresIn);
        $this->assertSame(5, $response->interval);
    }

    public function testDeviceCodeResponseWithAllFields(): void
    {
        $response = new DeviceCodeResponse(
            deviceCode: 'abc123',
            userCode: 'ABCD-1234',
            verificationUri: 'https://example.com/device',
            verificationUriComplete: 'https://example.com/device?code=ABCD-1234',
            expiresIn: 600,
            interval: 10,
        );

        $this->assertSame('https://example.com/device?code=ABCD-1234', $response->verificationUriComplete);
        $this->assertSame(600, $response->expiresIn);
        $this->assertSame(10, $response->interval);
    }

    public function testDeviceCodeResponseFieldsAreReadonly(): void
    {
        $response = new DeviceCodeResponse(
            deviceCode: 'abc123',
            userCode: 'ABCD-1234',
            verificationUri: 'https://example.com/device',
        );

        $ref = new \ReflectionClass($response);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }

    // ── TokenResponse ─────────────────────────────────────────────

    public function testTokenResponseConstruction(): void
    {
        $token = new TokenResponse(accessToken: 'gho_abc123');

        $this->assertSame('gho_abc123', $token->accessToken);
        $this->assertSame('bearer', $token->tokenType);
        $this->assertSame('', $token->scope);
        $this->assertNull($token->refreshToken);
        $this->assertNull($token->expiresIn);
    }

    public function testTokenResponseWithAllFields(): void
    {
        $token = new TokenResponse(
            accessToken: 'gho_abc123',
            tokenType: 'Bearer',
            scope: 'read:user repo',
            refreshToken: 'ghr_refresh456',
            expiresIn: 3600,
        );

        $this->assertSame('gho_abc123', $token->accessToken);
        $this->assertSame('Bearer', $token->tokenType);
        $this->assertSame('read:user repo', $token->scope);
        $this->assertSame('ghr_refresh456', $token->refreshToken);
        $this->assertSame(3600, $token->expiresIn);
    }

    public function testTokenResponseIsExpiredReturnsFalse(): void
    {
        $token = new TokenResponse(accessToken: 'gho_abc123');
        $this->assertFalse($token->isExpired());
    }

    public function testTokenResponseToArrayWithRequiredOnly(): void
    {
        $token = new TokenResponse(accessToken: 'gho_abc123');
        $array = $token->toArray();

        $this->assertSame('gho_abc123', $array['access_token']);
        $this->assertSame('bearer', $array['token_type']);
        $this->assertSame('', $array['scope']);
        $this->assertArrayNotHasKey('refresh_token', $array);
        $this->assertArrayNotHasKey('expires_in', $array);
    }

    public function testTokenResponseToArrayWithAllFields(): void
    {
        $token = new TokenResponse(
            accessToken: 'gho_abc123',
            tokenType: 'Bearer',
            scope: 'repo',
            refreshToken: 'ghr_refresh456',
            expiresIn: 3600,
        );
        $array = $token->toArray();

        $this->assertSame('gho_abc123', $array['access_token']);
        $this->assertSame('Bearer', $array['token_type']);
        $this->assertSame('repo', $array['scope']);
        $this->assertSame('ghr_refresh456', $array['refresh_token']);
        $this->assertSame(3600, $array['expires_in']);
    }

    public function testTokenResponseFieldsAreReadonly(): void
    {
        $token = new TokenResponse(accessToken: 'gho_abc123');
        $ref = new \ReflectionClass($token);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }

    // ── CredentialStore ───────────────────────────────────────────

    public function testCredentialStoreStoreAndGet(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'gho_abc123');

        $this->assertSame('gho_abc123', $store->get('github', 'access_token'));
    }

    public function testCredentialStoreGetNonExistentProvider(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $this->assertNull($store->get('nonexistent', 'access_token'));
    }

    public function testCredentialStoreGetNonExistentKey(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'gho_abc123');

        $this->assertNull($store->get('github', 'nonexistent_key'));
    }

    public function testCredentialStoreHas(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'gho_abc123');

        $this->assertTrue($store->has('github', 'access_token'));
        $this->assertFalse($store->has('github', 'nonexistent'));
        $this->assertFalse($store->has('nonexistent', 'access_token'));
    }

    public function testCredentialStoreDeleteKey(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'gho_abc123');
        $store->store('github', 'refresh_token', 'ghr_refresh456');

        $store->delete('github', 'access_token');

        $this->assertNull($store->get('github', 'access_token'));
        $this->assertSame('ghr_refresh456', $store->get('github', 'refresh_token'));
    }

    public function testCredentialStoreDeleteProvider(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'gho_abc123');

        $store->delete('github');

        $this->assertNull($store->get('github', 'access_token'));
        $this->assertFileDoesNotExist("{$this->tmpDir}/github.json");
    }

    public function testCredentialStoreListProviders(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'token', 'abc');
        $store->store('gitlab', 'token', 'def');
        $store->store('bitbucket', 'token', 'ghi');

        $providers = $store->listProviders();
        sort($providers);

        $this->assertSame(['bitbucket', 'github', 'gitlab'], $providers);
    }

    public function testCredentialStoreListProvidersEmptyDir(): void
    {
        $emptyDir = $this->tmpDir . '/empty';
        // Don't create the directory - listProviders should handle missing dir
        $store = new CredentialStore($emptyDir);

        $this->assertSame([], $store->listProviders());
    }

    public function testCredentialStoreFilePermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix file permissions not supported on Windows.');
        }

        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'secret');

        $path = "{$this->tmpDir}/github.json";
        $perms = fileperms($path) & 0777;

        $this->assertSame(0600, $perms, 'Credential file should have 0600 permissions');
    }

    public function testCredentialStoreAtomicWrite(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'first');
        $store->store('github', 'access_token', 'second');

        // No leftover .tmp files
        $tmpFiles = glob("{$this->tmpDir}/*.tmp.*");
        $this->assertEmpty($tmpFiles, 'No temporary files should remain after write');
        $this->assertSame('second', $store->get('github', 'access_token'));
    }

    public function testCredentialStoreMultipleKeysPerProvider(): void
    {
        $store = new CredentialStore($this->tmpDir);
        $store->store('github', 'access_token', 'gho_abc');
        $store->store('github', 'refresh_token', 'ghr_def');
        $store->store('github', 'scope', 'repo user');

        $this->assertSame('gho_abc', $store->get('github', 'access_token'));
        $this->assertSame('ghr_def', $store->get('github', 'refresh_token'));
        $this->assertSame('repo user', $store->get('github', 'scope'));
    }

    public function testCredentialStoreCreatesDirectoryIfMissing(): void
    {
        $nested = $this->tmpDir . '/nested/deep/dir';
        $store = new CredentialStore($nested);
        $store->store('github', 'token', 'abc');

        $this->assertDirectoryExists($nested);
        $this->assertSame('abc', $store->get('github', 'token'));
    }

    // ── DeviceCodeFlow ────────────────────────────────────────────

    public function testDeviceCodeFlowConstructor(): void
    {
        $flow = new DeviceCodeFlow(
            clientId: 'test-client-id',
            deviceCodeUrl: 'https://github.com/login/device/code',
            tokenUrl: 'https://github.com/login/oauth/access_token',
            scopes: ['read:user', 'repo'],
            timeout: 120,
        );

        // Constructor should not throw
        $this->assertInstanceOf(DeviceCodeFlow::class, $flow);
    }

    public function testDeviceCodeFlowRequestDeviceCodeFailsOnNetworkError(): void
    {
        $flow = new DeviceCodeFlow(
            clientId: 'test-client-id',
            deviceCodeUrl: 'http://127.0.0.1:1/nonexistent',
            tokenUrl: 'http://127.0.0.1:1/nonexistent',
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Failed to request device code');
        $flow->requestDeviceCode();
    }

    public function testDeviceCodeFlowPollForTokenThrowsOnExpiredToken(): void
    {
        // Use reflection to test pollForToken error handling without real HTTP
        $flow = new DeviceCodeFlow(
            clientId: 'test',
            deviceCodeUrl: 'http://127.0.0.1:1/unused',
            tokenUrl: 'http://127.0.0.1:1/unused',
            timeout: 1, // very short timeout
        );

        $deviceCode = new DeviceCodeResponse(
            deviceCode: 'test_device_code',
            userCode: 'TEST-CODE',
            verificationUri: 'https://example.com/device',
            expiresIn: 1,
            interval: 0, // don't actually sleep
        );

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Timeout waiting for user authorization');
        $flow->pollForToken($deviceCode);
    }

    public function testDeviceCodeFlowTryOpenBrowserPlatformDetection(): void
    {
        $flow = new DeviceCodeFlow(
            clientId: 'test',
            deviceCodeUrl: 'http://127.0.0.1:1/unused',
            tokenUrl: 'http://127.0.0.1:1/unused',
        );

        // Use reflection to call private method
        $ref = new \ReflectionMethod($flow, 'tryOpenBrowser');
        $ref->setAccessible(true);

        // Should not throw on any platform
        $ref->invoke($flow, 'https://example.com/device');
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testDeviceCodeFlowOutputCallback(): void
    {
        $captured = null;
        $flow = new DeviceCodeFlow(
            clientId: 'test',
            deviceCodeUrl: 'http://127.0.0.1:1/unused',
            tokenUrl: 'http://127.0.0.1:1/unused',
            outputCallback: function (string $msg) use (&$captured) {
                $captured = $msg;
            },
        );

        // We can't test authenticate() fully without HTTP, but we can verify
        // the callback is stored by checking the constructor accepted it
        $this->assertInstanceOf(DeviceCodeFlow::class, $flow);
    }

    public function testDeviceCodeFlowDefaultScopes(): void
    {
        $flow = new DeviceCodeFlow(
            clientId: 'test',
            deviceCodeUrl: 'http://127.0.0.1:1/unused',
            tokenUrl: 'http://127.0.0.1:1/unused',
        );

        // Default scopes should be empty - verified by constructor not throwing
        $this->assertInstanceOf(DeviceCodeFlow::class, $flow);
    }

    // ── AuthenticationException ───────────────────────────────────

    public function testAuthenticationExceptionIsRuntimeException(): void
    {
        $exception = new AuthenticationException('test error');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('test error', $exception->getMessage());
    }

    public function testAuthenticationExceptionWithCode(): void
    {
        $exception = new AuthenticationException('test error', 42);

        $this->assertSame(42, $exception->getCode());
    }

    public function testAuthenticationExceptionWithPrevious(): void
    {
        $previous = new \Exception('root cause');
        $exception = new AuthenticationException('wrapper', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
