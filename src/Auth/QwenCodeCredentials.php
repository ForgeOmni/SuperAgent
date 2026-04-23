<?php

declare(strict_types=1);

namespace SuperAgent\Auth;

/**
 * OAuth credentials for the Qwen Code subscription plan (Alibaba's
 * managed Qwen coding endpoint, separate from the public DashScope
 * API-key endpoint).
 *
 * Unlike `QWEN_API_KEY` which maps to public DashScope endpoints at
 * a metered per-token price, the Qwen Code plan uses OAuth against
 * `chat.qwen.ai` with **PKCE S256** — see
 * `packages/core/src/qwen/qwenOAuth2.ts:43-77` in the qwen-code
 * upstream for the canonical implementation.
 *
 * Storage shape at `~/.superagent/credentials/qwen-code.json`:
 *   {
 *     "access_token":  "...",
 *     "refresh_token": "...",
 *     "expires_at":    1234567890,   // unix seconds
 *     "resource_url":  "portal.qwen.ai/v1/...",  // per-account base URL
 *     "scopes":        ["openid", "profile", "email", "model.completion"]
 *   }
 *
 * The `resource_url` field is Qwen-specific: Alibaba's token response
 * returns an account-specific API base URL that overrides the default
 * DashScope endpoint for THAT account. `QwenProvider` with
 * `region: 'code'` reads this field at construction time to pick its
 * base URL. Falls back to the default compatible-mode endpoint when
 * absent.
 */
class QwenCodeCredentials
{
    public const CLIENT_ID = 'f0304373b74a44d2b584a3fb70ca9e56';
    public const DEFAULT_OAUTH_HOST = 'https://chat.qwen.ai';
    public const DEVICE_AUTH_PATH = '/api/v1/oauth2/device/code';
    public const TOKEN_PATH = '/api/v1/oauth2/token';
    public const CREDENTIAL_NAME = 'qwen-code';
    public const DEFAULT_SCOPE = 'openid profile email model.completion';

    public function __construct(
        private readonly CredentialStore $store = new CredentialStore(),
    ) {
    }

    public function host(): string
    {
        return getenv('QWEN_OAUTH_HOST')
            ?: getenv('QWEN_CODE_OAUTH_HOST')
            ?: self::DEFAULT_OAUTH_HOST;
    }

    /**
     * Read persisted credentials (decrypting via CredentialStore).
     *
     * @return array{access_token:string, refresh_token:?string, expires_at:?int, resource_url:?string, scopes:array<int,string>}|null
     */
    public function load(): ?array
    {
        $access = $this->store->get(self::CREDENTIAL_NAME, 'access_token');
        if ($access === null || $access === '') {
            return null;
        }
        $scopesRaw = $this->store->get(self::CREDENTIAL_NAME, 'scopes');
        $scopes = [];
        if ($scopesRaw !== null && $scopesRaw !== '') {
            $decoded = json_decode($scopesRaw, true);
            if (is_array($decoded)) {
                $scopes = array_values(array_map('strval', $decoded));
            }
        }
        $expiresAt = $this->store->get(self::CREDENTIAL_NAME, 'expires_at');
        $refresh = $this->store->get(self::CREDENTIAL_NAME, 'refresh_token');
        $resourceUrl = $this->store->get(self::CREDENTIAL_NAME, 'resource_url');

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh ?: null,
            'expires_at'    => ($expiresAt === null || $expiresAt === '') ? null : (int) $expiresAt,
            'resource_url'  => $resourceUrl ?: null,
            'scopes'        => $scopes,
        ];
    }

    /**
     * @param array{access_token:string, refresh_token?:?string, expires_at?:?int, resource_url?:?string, scopes?:array<int,string>} $creds
     */
    public function save(array $creds): void
    {
        $this->store->store(self::CREDENTIAL_NAME, 'access_token', (string) $creds['access_token']);
        if (!empty($creds['refresh_token'])) {
            $this->store->store(self::CREDENTIAL_NAME, 'refresh_token', (string) $creds['refresh_token']);
        }
        if (isset($creds['expires_at']) && $creds['expires_at'] !== null) {
            $this->store->store(self::CREDENTIAL_NAME, 'expires_at', (string) $creds['expires_at']);
        }
        if (!empty($creds['resource_url'])) {
            $this->store->store(self::CREDENTIAL_NAME, 'resource_url', (string) $creds['resource_url']);
        }
        if (!empty($creds['scopes'])) {
            $this->store->store(self::CREDENTIAL_NAME, 'scopes', (string) json_encode($creds['scopes']));
        }
        $this->store->store(self::CREDENTIAL_NAME, 'updated_at', (string) time());
    }

    public function delete(): void
    {
        $this->store->delete(self::CREDENTIAL_NAME);
    }

    public function isExpired(int $skewSeconds = 60): bool
    {
        $creds = $this->load();
        if ($creds === null) {
            return true;
        }
        $expiresAt = $creds['expires_at'] ?? null;
        if ($expiresAt === null) {
            return false;
        }
        return ($expiresAt - $skewSeconds) <= time();
    }

    /**
     * Refresh the access token. Runs under CredentialStore::withLock
     * (Phase 3) so parallel SuperAgent sessions don't race-refresh
     * and clobber each other's tokens. Double-checks expiry inside
     * the lock — if another process already refreshed, we return
     * its fresh token without making a redundant HTTP call.
     *
     * @return array{access_token:string, refresh_token:?string, expires_at:?int, resource_url:?string, scopes:array<int,string>}|null
     */
    public function refresh(): ?array
    {
        return $this->store->withLock(self::CREDENTIAL_NAME, function () {
            $creds = $this->load();
            if ($creds === null || empty($creds['refresh_token'])) {
                return null;
            }

            // Double-check: another process may have already refreshed.
            $expiresAt = $creds['expires_at'] ?? null;
            if ($expiresAt !== null && ($expiresAt - 60) > time()) {
                return $creds;
            }

            $url = rtrim($this->host(), '/') . self::TOKEN_PATH;
            $payload = http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $creds['refresh_token'],
                'client_id'     => self::CLIENT_ID,
            ]);

            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                    'content'       => $payload,
                    'timeout'       => 30,
                    'ignore_errors' => true,
                ],
            ]);

            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) {
                return null;
            }
            $data = json_decode($resp, true);
            if (! is_array($data) || empty($data['access_token'])) {
                return null;
            }

            $new = [
                'access_token'  => (string) $data['access_token'],
                'refresh_token' => (string) ($data['refresh_token'] ?? $creds['refresh_token']),
                'expires_at'    => isset($data['expires_in'])
                    ? (time() + (int) $data['expires_in'])
                    : ($creds['expires_at'] ?? null),
                // Alibaba may return a new resource_url on refresh (rare
                // but legal); keep the stored one if refresh doesn't.
                'resource_url'  => (string) ($data['resource_url'] ?? $creds['resource_url'] ?? ''),
                'scopes'        => $creds['scopes'] ?? [],
            ];
            if ($new['resource_url'] === '') {
                $new['resource_url'] = null;
            }
            $this->save($new);
            return $new;
        });
    }

    /**
     * Convenience: return the current access token, auto-refreshing
     * if it's within `$skewSeconds` of expiry. Returns null when no
     * credential is stored OR when refresh fails.
     */
    public function currentAccessToken(int $skewSeconds = 60): ?string
    {
        $creds = $this->load();
        if ($creds === null) {
            return null;
        }
        if ($this->isExpired($skewSeconds)) {
            $refreshed = $this->refresh();
            if ($refreshed === null) {
                return null;
            }
            return $refreshed['access_token'];
        }
        return $creds['access_token'];
    }

    /**
     * The per-account DashScope base URL returned by OAuth (if any).
     * `QwenProvider::regionToBaseUrl('code')` calls this to decide
     * whether to use the default compatible-mode endpoint or the
     * account-specific one Alibaba's token response specified.
     *
     * Normalized: always `https://...` scheme, no trailing slash.
     * Returns null when no OAuth creds are stored or the token
     * response had no `resource_url`.
     */
    public function resourceUrl(): ?string
    {
        $creds = $this->load();
        if ($creds === null || empty($creds['resource_url'])) {
            return null;
        }
        $url = (string) $creds['resource_url'];
        // Some accounts' resource_url comes back without a scheme —
        // match qwen-code's normalization (prepend https:// when absent).
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }
}
