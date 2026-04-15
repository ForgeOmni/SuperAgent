<?php

declare(strict_types=1);

namespace SuperAgent\Auth;

/**
 * Reads OAuth credentials from a local Claude Code install.
 *
 * Claude Code stores credentials at ~/.claude/.credentials.json with shape:
 * {
 *   "claudeAiOauth": {
 *     "accessToken": "sk-ant-oat01-...",
 *     "refreshToken": "sk-ant-ort01-...",
 *     "expiresAt": 1730000000000,   // ms since epoch
 *     "scopes": ["user:inference", "user:profile"],
 *     "subscriptionType": "pro" | "max" | ...
 *   }
 * }
 *
 * On macOS the credentials may live in the Keychain instead; we only read the
 * JSON file form here. If the file is missing we return null.
 */
class ClaudeCodeCredentials
{
    private const TOKEN_URL = 'https://console.anthropic.com/v1/oauth/token';
    private const CLIENT_ID = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';

    public function __construct(
        private readonly string $path,
    ) {}

    public static function default(): self
    {
        $home = self::homeDir();
        return new self($home . '/.claude/.credentials.json');
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array{access_token:string,refresh_token:?string,expires_at:?int,scopes:array,subscription:?string}|null
     */
    public function read(): ?array
    {
        if (! $this->exists()) {
            return null;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        $oauth = $data['claudeAiOauth'] ?? null;
        if (! is_array($oauth) || empty($oauth['accessToken'])) {
            return null;
        }
        return [
            'access_token' => (string) $oauth['accessToken'],
            'refresh_token' => isset($oauth['refreshToken']) ? (string) $oauth['refreshToken'] : null,
            'expires_at' => isset($oauth['expiresAt']) ? (int) $oauth['expiresAt'] : null,
            'scopes' => (array) ($oauth['scopes'] ?? []),
            'subscription' => isset($oauth['subscriptionType']) ? (string) $oauth['subscriptionType'] : null,
        ];
    }

    public function isExpired(array $creds, int $skewSeconds = 60): bool
    {
        $expiresAt = $creds['expires_at'] ?? null;
        if (! $expiresAt) {
            return false;
        }
        // expiresAt is in milliseconds
        return (int) floor($expiresAt / 1000) - $skewSeconds <= time();
    }

    /**
     * Refresh the access token using the stored refresh_token.
     * Returns the refreshed creds array or null on failure.
     */
    public function refresh(array $creds): ?array
    {
        $refreshToken = $creds['refresh_token'] ?? null;
        if (! $refreshToken) {
            return null;
        }

        $payload = json_encode([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => self::CLIENT_ID,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents(self::TOKEN_URL, false, $ctx);
        if ($resp === false) {
            return null;
        }
        $data = json_decode($resp, true);
        if (! is_array($data) || empty($data['access_token'])) {
            return null;
        }
        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? $refreshToken),
            'expires_at' => isset($data['expires_in'])
                ? (time() + (int) $data['expires_in']) * 1000
                : ($creds['expires_at'] ?? null),
            'scopes' => $creds['scopes'] ?? [],
            'subscription' => $creds['subscription'] ?? null,
        ];
    }

    private static function homeDir(): string
    {
        $home = getenv('HOME') ?: $_SERVER['HOME'] ?? null;
        if (! $home && PHP_OS_FAMILY === 'Windows') {
            $home = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? '');
        }
        return (string) $home;
    }
}
