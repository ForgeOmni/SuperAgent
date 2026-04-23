<?php

namespace SuperAgent\Auth;

/**
 * OAuth 2.0 Device Authorization Grant (RFC 8628).
 * Used for authenticating with providers like GitHub Copilot that require
 * browser-based login from CLI applications.
 *
 * Optionally supports PKCE (RFC 7636) for providers that require it — Qwen
 * Code does, others don't. When `$pkceChallengeMethod !== null`, the
 * `code_challenge` is included in the device-authorization request and a
 * matching `code_verifier` is sent with the token poll. See
 * `generatePkcePair()` for the S256 helper.
 */
class DeviceCodeFlow
{
    private const DEFAULT_POLL_INTERVAL = 5;
    private const DEFAULT_TIMEOUT = 300; // 5 minutes

    /** @var callable|null */
    private $outputCallback;

    public function __construct(
        private string $clientId,
        private string $deviceCodeUrl,    // e.g. https://github.com/login/device/code
        private string $tokenUrl,         // e.g. https://github.com/login/oauth/access_token
        private array $scopes = [],
        private int $timeout = self::DEFAULT_TIMEOUT,
        ?callable $outputCallback = null, // fn(string $message) for display
        private ?string $pkceCodeVerifier = null,       // RFC 7636 PKCE verifier (raw 43-128 chars)
        private ?string $pkceCodeChallenge = null,      // SHA-256 base64url of $pkceCodeVerifier (S256)
        private ?string $pkceChallengeMethod = null,    // 'S256' | 'plain' | null to disable PKCE
    ) {
        $this->outputCallback = $outputCallback;
    }

    /**
     * Generate a PKCE code_verifier + code_challenge pair (S256).
     *
     * Equivalent to qwen-code's `generatePKCEPair()` in
     * `packages/core/src/qwen/qwenOAuth2.ts:70-77`: 32 random bytes →
     * base64url for the verifier; sha256 → base64url for the challenge.
     *
     * @return array{code_verifier:string, code_challenge:string, code_challenge_method:string}
     */
    public static function generatePkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return [
            'code_verifier' => $verifier,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
    }

    /**
     * Request a device code from the authorization server.
     * @return DeviceCodeResponse
     * @throws AuthenticationException
     */
    public function requestDeviceCode(): DeviceCodeResponse
    {
        $params = [
            'client_id' => $this->clientId,
            'scope' => implode(' ', $this->scopes),
        ];
        // PKCE: include code_challenge + method only when enabled. Qwen
        // Code requires this; Kimi Code / GitHub don't care if omitted.
        if ($this->pkceChallengeMethod !== null && $this->pkceCodeChallenge !== null) {
            $params['code_challenge'] = $this->pkceCodeChallenge;
            $params['code_challenge_method'] = $this->pkceChallengeMethod;
        }
        $payload = http_build_query($params);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents($this->deviceCodeUrl, false, $context);
        if ($response === false) {
            throw new AuthenticationException('Failed to request device code');
        }

        $data = json_decode($response, true);
        if (!isset($data['device_code'], $data['user_code'], $data['verification_uri'])) {
            throw new AuthenticationException('Invalid device code response: ' . $response);
        }

        return new DeviceCodeResponse(
            deviceCode: $data['device_code'],
            userCode: $data['user_code'],
            verificationUri: $data['verification_uri'],
            verificationUriComplete: $data['verification_uri_complete'] ?? null,
            expiresIn: $data['expires_in'] ?? $this->timeout,
            interval: $data['interval'] ?? self::DEFAULT_POLL_INTERVAL,
        );
    }

    /**
     * Poll for the access token after user has authorized.
     * @return TokenResponse
     * @throws AuthenticationException on timeout or denied
     */
    public function pollForToken(DeviceCodeResponse $deviceCode): TokenResponse
    {
        $interval = $deviceCode->interval;
        $deadline = time() + min($deviceCode->expiresIn, $this->timeout);

        while (time() < $deadline) {
            sleep($interval);

            $tokenParams = [
                'client_id' => $this->clientId,
                'device_code' => $deviceCode->deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ];
            // PKCE verifier pairs with the challenge we sent in
            // requestDeviceCode() — completes the RFC 7636 loop.
            if ($this->pkceChallengeMethod !== null && $this->pkceCodeVerifier !== null) {
                $tokenParams['code_verifier'] = $this->pkceCodeVerifier;
            }
            $payload = http_build_query($tokenParams);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($this->tokenUrl, false, $context);
            if ($response === false) continue;

            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                // Collect vendor-specific extras (resource_url, id_token,
                // etc.) that aren't part of RFC 8628 — callers that
                // care (e.g. QwenCodeCredentials → resource_url for
                // dynamic base URL) pull them off `extra`.
                $standard = ['access_token', 'token_type', 'scope', 'refresh_token', 'expires_in'];
                $extra = array_diff_key($data, array_flip($standard));
                return new TokenResponse(
                    accessToken: $data['access_token'],
                    tokenType: $data['token_type'] ?? 'bearer',
                    scope: $data['scope'] ?? '',
                    refreshToken: $data['refresh_token'] ?? null,
                    expiresIn: $data['expires_in'] ?? null,
                    extra: $extra !== [] ? $extra : null,
                );
            }

            $error = $data['error'] ?? '';
            if ($error === 'authorization_pending') continue;
            if ($error === 'slow_down') { $interval += 5; continue; }
            if ($error === 'expired_token') throw new AuthenticationException('Device code expired');
            if ($error === 'access_denied') throw new AuthenticationException('User denied authorization');
        }

        throw new AuthenticationException('Timeout waiting for user authorization');
    }

    /**
     * Full flow: request code, display to user, poll for token.
     */
    public function authenticate(): TokenResponse
    {
        $deviceCode = $this->requestDeviceCode();

        $message = sprintf(
            "Please visit %s and enter code: %s",
            $deviceCode->verificationUri,
            $deviceCode->userCode
        );

        if ($this->outputCallback) {
            ($this->outputCallback)($message);
        } else {
            echo $message . "\n";
        }

        // Try to open browser automatically
        $this->tryOpenBrowser($deviceCode->verificationUriComplete ?? $deviceCode->verificationUri);

        return $this->pollForToken($deviceCode);
    }

    private function tryOpenBrowser(string $url): void
    {
        // Honour the global "no browser" switch so headless CI runs and the
        // unit suite don't spawn a real browser (previously the platform-
        // detection test actually launched the user's browser at example.com).
        if ($this->browserLaunchDisabled()) {
            return;
        }

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Linux' => 'xdg-open',
            'Windows' => 'start',
            default => null,
        };
        if ($command) {
            @exec("{$command} " . escapeshellarg($url) . ' 2>/dev/null &');
        }
    }

    private function browserLaunchDisabled(): bool
    {
        foreach (['SUPERAGENT_NO_BROWSER', 'CI', 'PHPUNIT_RUNNING'] as $var) {
            $v = getenv($var);
            if ($v !== false && $v !== '' && $v !== '0' && strtolower($v) !== 'false') {
                return true;
            }
        }
        return false;
    }
}
