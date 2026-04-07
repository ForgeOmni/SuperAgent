<?php

namespace SuperAgent\Auth;

/**
 * OAuth 2.0 Device Authorization Grant (RFC 8628).
 * Used for authenticating with providers like GitHub Copilot that require
 * browser-based login from CLI applications.
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
    ) {
        $this->outputCallback = $outputCallback;
    }

    /**
     * Request a device code from the authorization server.
     * @return DeviceCodeResponse
     * @throws AuthenticationException
     */
    public function requestDeviceCode(): DeviceCodeResponse
    {
        $payload = http_build_query([
            'client_id' => $this->clientId,
            'scope' => implode(' ', $this->scopes),
        ]);

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

            $payload = http_build_query([
                'client_id' => $this->clientId,
                'device_code' => $deviceCode->deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ]);

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
                return new TokenResponse(
                    accessToken: $data['access_token'],
                    tokenType: $data['token_type'] ?? 'bearer',
                    scope: $data['scope'] ?? '',
                    refreshToken: $data['refresh_token'] ?? null,
                    expiresIn: $data['expires_in'] ?? null,
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
}
