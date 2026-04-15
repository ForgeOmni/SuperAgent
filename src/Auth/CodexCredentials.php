<?php

declare(strict_types=1);

namespace SuperAgent\Auth;

/**
 * Reads OAuth credentials from a local Codex CLI install.
 *
 * Codex stores credentials at ~/.codex/auth.json with shape:
 * {
 *   "OPENAI_API_KEY": "sk-..." | null,
 *   "tokens": {
 *     "id_token": "eyJ...",
 *     "access_token": "eyJ...",
 *     "refresh_token": "...",
 *     "account_id": "..."
 *   },
 *   "last_refresh": "2025-..."
 * }
 *
 * Either an API key or a ChatGPT-account OAuth token may be present.
 */
class CodexCredentials
{
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    public function __construct(
        private readonly string $path,
    ) {}

    public static function default(): self
    {
        $home = self::homeDir();
        return new self($home . '/.codex/auth.json');
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
     * @return array{mode:string,access_token:?string,refresh_token:?string,api_key:?string,id_token:?string,account_id:?string,last_refresh:?string}|null
     * mode is one of: "api_key", "oauth", or null if unusable.
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
        if (! is_array($data)) {
            return null;
        }
        $apiKey = $data['OPENAI_API_KEY'] ?? null;
        $tokens = $data['tokens'] ?? null;
        $access = is_array($tokens) ? ($tokens['access_token'] ?? null) : null;

        if (empty($apiKey) && empty($access)) {
            return null;
        }

        return [
            'mode' => ! empty($access) ? 'oauth' : 'api_key',
            'access_token' => $access ? (string) $access : null,
            'refresh_token' => is_array($tokens) && ! empty($tokens['refresh_token'])
                ? (string) $tokens['refresh_token']
                : null,
            'id_token' => is_array($tokens) && ! empty($tokens['id_token'])
                ? (string) $tokens['id_token']
                : null,
            'account_id' => is_array($tokens) && ! empty($tokens['account_id'])
                ? (string) $tokens['account_id']
                : null,
            'api_key' => $apiKey ? (string) $apiKey : null,
            'last_refresh' => isset($data['last_refresh']) ? (string) $data['last_refresh'] : null,
        ];
    }

    /**
     * OpenAI OAuth tokens are JWTs — try to read exp claim for expiry check.
     */
    public function isExpired(array $creds, int $skewSeconds = 60): bool
    {
        if (($creds['mode'] ?? null) !== 'oauth') {
            return false;
        }
        $token = $creds['access_token'] ?? null;
        if (! $token) {
            return true;
        }
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return false;
        }
        $payload = $this->b64urlDecode($parts[1]);
        $claims = json_decode($payload, true);
        $exp = is_array($claims) ? ($claims['exp'] ?? null) : null;
        if (! $exp) {
            return false;
        }
        return (int) $exp - $skewSeconds <= time();
    }

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
            'scope' => 'openid profile email offline_access',
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
        return array_merge($creds, [
            'mode' => 'oauth',
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? $refreshToken),
            'id_token' => (string) ($data['id_token'] ?? ($creds['id_token'] ?? '')),
            'last_refresh' => date('c'),
        ]);
    }

    private function b64urlDecode(string $input): string
    {
        $pad = strlen($input) % 4;
        if ($pad) {
            $input .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($input, '-_', '+/'));
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
