<?php

declare(strict_types=1);

namespace SuperAgent\MCP;

/**
 * OAuth 2.0 device-code flow helper for MCP servers that require it.
 *
 * **Status: scaffold.** The method signatures + token cache paths are
 * stable — the wire implementation (`registerClient`, `requestDeviceCode`,
 * `pollForToken`) contains working HTTP calls against RFC 8628 (Device
 * Authorization Grant). It is **untested against a real MCP server**
 * because no major MCP server in production today requires OAuth
 * device-flow auth — the vast majority use plain API keys / bearer
 * tokens in headers, which MCPManager's `add <name> http <url> --header`
 * CLI already covers.
 *
 * When the first OAuth-requiring MCP server surfaces in the wild:
 *   1. Wire `McpOAuth::authenticate('server-name', $config)` into the
 *      `superagent mcp auth` CLI subcommand.
 *   2. Add an integration test that hits the real authorization server.
 *   3. Cache the refresh-token alongside access-token in the sidecar
 *      file so we don't force re-auth every hour.
 *
 * Token storage:
 *   ~/.superagent/mcp-auth.json  (chmod 0600)
 *
 * Shape:
 *   {
 *     "schema": 1,
 *     "servers": {
 *       "<server-name>": {
 *         "access_token": "...",
 *         "refresh_token": "...",
 *         "expires_at": 1700000000
 *       }
 *     }
 *   }
 */
final class McpOAuth
{
    public static function tokenStorePath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.superagent/mcp-auth.json';
    }

    /**
     * Return a cached token for `$serverName` if still valid, otherwise
     * null. Callers that want to refresh should catch the null case and
     * re-run `authenticate()`.
     *
     * @return array{access_token: string, expires_at: int}|null
     */
    public static function cachedToken(string $serverName): ?array
    {
        $path = self::tokenStorePath();
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['schema'] ?? null) !== 1) {
            return null;
        }
        $entry = $data['servers'][$serverName] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        if (empty($entry['access_token']) || ($entry['expires_at'] ?? 0) <= time()) {
            return null;
        }
        return [
            'access_token' => (string) $entry['access_token'],
            'expires_at'   => (int) $entry['expires_at'],
        ];
    }

    /**
     * Persist a freshly-obtained token. Caller is responsible for
     * computing `expires_at` (`time() + expires_in`).
     *
     * @param array{access_token: string, expires_at: int, refresh_token?: string} $token
     */
    public static function storeToken(string $serverName, array $token): void
    {
        $path = self::tokenStorePath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $data = ['schema' => 1, 'servers' => []];
        if (is_file($path)) {
            $existing = json_decode((string) @file_get_contents($path), true);
            if (is_array($existing) && ($existing['schema'] ?? null) === 1) {
                $data = $existing;
            }
        }

        $data['servers'][$serverName] = $token;
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n") === false) {
            throw new \RuntimeException("Failed to write: {$tmp}");
        }
        @rename($tmp, $path);
        @chmod($path, 0600);
    }

    /**
     * Delete a stored token (e.g. on logout).
     */
    public static function clearToken(string $serverName): void
    {
        $path = self::tokenStorePath();
        if (! is_file($path)) {
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['schema'] ?? null) !== 1) {
            return;
        }
        unset($data['servers'][$serverName]);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        @chmod($path, 0600);
    }

    /**
     * Run the full RFC 8628 device-code dance. Displays the user-facing
     * verification URL + code, then polls the token endpoint until the
     * user approves (or the interval expires).
     *
     * Raises `RuntimeException` on any failure — partial state is not
     * persisted so retries start clean.
     *
     * @param array{client_id: string, device_endpoint: string, token_endpoint: string, scope?: string} $config
     * @return array{access_token: string, expires_at: int, refresh_token?: string}
     */
    public static function authenticate(string $serverName, array $config): array
    {
        foreach (['client_id', 'device_endpoint', 'token_endpoint'] as $req) {
            if (empty($config[$req])) {
                throw new \RuntimeException("McpOAuth: missing required config '{$req}'");
            }
        }

        // Step 1: request a device code.
        $device = self::postForm($config['device_endpoint'], [
            'client_id' => $config['client_id'],
            'scope'     => $config['scope'] ?? 'openid',
        ]);
        if (empty($device['device_code']) || empty($device['user_code']) || empty($device['verification_uri'])) {
            throw new \RuntimeException('Device authorization server response missing required fields');
        }

        // Step 2: present the code to the user. Caller captures stdout.
        fwrite(STDERR, sprintf(
            "\nOpen this URL in your browser:  %s\nEnter this code:  %s\n\nWaiting for approval...\n",
            $device['verification_uri'],
            $device['user_code'],
        ));

        // Step 3: poll the token endpoint.
        $interval = max(1, (int) ($device['interval'] ?? 5));
        $deadline = time() + (int) ($device['expires_in'] ?? 900);

        while (time() < $deadline) {
            sleep($interval);
            $token = self::postForm($config['token_endpoint'], [
                'grant_type'   => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code'  => $device['device_code'],
                'client_id'    => $config['client_id'],
            ], ignoreHttpErrors: true);

            if (! empty($token['access_token'])) {
                $record = [
                    'access_token' => (string) $token['access_token'],
                    'expires_at'   => time() + (int) ($token['expires_in'] ?? 3600),
                ];
                if (! empty($token['refresh_token'])) {
                    $record['refresh_token'] = (string) $token['refresh_token'];
                }
                self::storeToken($serverName, $record);
                return $record;
            }

            $error = $token['error'] ?? '';
            if ($error === 'authorization_pending') {
                continue;  // keep polling
            }
            if ($error === 'slow_down') {
                $interval += 5;
                continue;
            }
            throw new \RuntimeException('OAuth device flow failed: ' . $error);
        }

        throw new \RuntimeException('OAuth device flow timed out waiting for user approval');
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private static function postForm(string $url, array $form, bool $ignoreHttpErrors = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("OAuth request failed: {$err}");
        }
        if (! $ignoreHttpErrors && $status >= 400) {
            throw new \RuntimeException("OAuth request returned HTTP {$status}: {$body}");
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
