<?php

declare(strict_types=1);

namespace SuperAgent\Auth;

/**
 * OAuth credentials for the Kimi Code subscription plan.
 *
 * Unlike `KIMI_API_KEY` (which maps to the public `api.moonshot.{ai,cn}`
 * endpoints at a metered per-token price), the Kimi Code plan uses
 * OAuth against `api.kimi.com/coding/v1` — a managed endpoint with a
 * subscription quota. Login is a standard RFC 8628 Device
 * Authorization Grant against `auth.kimi.com` (see kimi-cli's
 * `src/kimi_cli/auth/oauth.py` for the canonical implementation).
 *
 * Storage shape at `~/.superagent/credentials/kimi-code.json`:
 *   {
 *     "access_token": "...",
 *     "refresh_token": "...",
 *     "expires_at":    1234567890,   // unix seconds, NOT ms
 *     "scopes":        ["..."]       // optional
 *   }
 *
 * NOTE: The existing `CredentialStore` encrypts files at rest since
 * 0.8.7. We reuse it for Kimi Code so the token gets AES-256-GCM
 * encryption for free. This class stays a thin wrapper around
 * `CredentialStore` plus the refresh-token logic.
 */
class KimiCodeCredentials
{
    public const CLIENT_ID = '17e5f671-d194-4dfb-9706-5516cb48c098';
    public const DEFAULT_OAUTH_HOST = 'https://auth.kimi.com';
    public const DEVICE_AUTH_PATH = '/api/oauth/device_authorization';
    public const TOKEN_PATH = '/api/oauth/token';
    public const CREDENTIAL_NAME = 'kimi-code';

    public function __construct(
        private readonly CredentialStore $store = new CredentialStore(),
    ) {
    }

    public function host(): string
    {
        return getenv('KIMI_CODE_OAUTH_HOST')
            ?: getenv('KIMI_OAUTH_HOST')
            ?: self::DEFAULT_OAUTH_HOST;
    }

    /**
     * Read persisted credentials (decrypting via CredentialStore).
     *
     * @return array{access_token:string, refresh_token:?string, expires_at:?int, scopes:array<int,string>}|null
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

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh ?: null,
            'expires_at'    => ($expiresAt === null || $expiresAt === '') ? null : (int) $expiresAt,
            'scopes'        => $scopes,
        ];
    }

    /**
     * @param array{access_token:string, refresh_token?:?string, expires_at?:?int, scopes?:array<int,string>} $creds
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
        if (!empty($creds['scopes'])) {
            $this->store->store(self::CREDENTIAL_NAME, 'scopes', (string) json_encode($creds['scopes']));
        }
        $this->store->store(self::CREDENTIAL_NAME, 'updated_at', (string) time());
    }

    public function delete(): void
    {
        // Drop the entire kimi-code file — simpler than deleting keys one
        // by one, and matches what `logout` semantically means.
        $this->store->delete(self::CREDENTIAL_NAME);
    }

    /**
     * Is the stored access token within `$skewSeconds` of expiry?
     * Treats missing expiry as "unknown, assume valid" — matches
     * ClaudeCodeCredentials behaviour so the callers are uniform.
     */
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
     * Refresh the access token using the stored refresh_token. Returns
     * the new credential row on success, null on failure. Callers that
     * want to persist the new token should call `save()` afterwards.
     *
     * @return array{access_token:string, refresh_token:?string, expires_at:?int, scopes:array<int,string>}|null
     */
    public function refresh(): ?array
    {
        // Serialize refreshes across concurrent SuperAgent sessions so
        // two parallel processes don't race-write the same file and
        // lose each other's fresh token. The critical section covers
        // the full load→network→save path because another process's
        // save() could have landed while our `load()` was reading
        // stale state.
        return $this->store->withLock(self::CREDENTIAL_NAME, function () {
            $creds = $this->load();
            if ($creds === null || empty($creds['refresh_token'])) {
                return null;
            }

            // Double-check expiry under the lock — another process may
            // have already refreshed by the time we got here, in which
            // case we should just return that fresh token.
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
                'scopes'        => $creds['scopes'] ?? [],
            ];
            $this->save($new);
            return $new;
        });
    }

    /**
     * Convenience: return the current access token, auto-refreshing if
     * it's within `$skewSeconds` of expiry. Returns null when no
     * credential is stored and when refresh fails.
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
}
