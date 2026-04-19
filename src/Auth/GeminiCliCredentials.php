<?php

declare(strict_types=1);

namespace SuperAgent\Auth;

/**
 * Reads credentials from a locally-installed Google Gemini CLI / AI Studio setup.
 *
 * Gemini's official CLI (`@google/gemini-cli`) and related tooling store credentials
 * in a handful of well-known shapes depending on which auth mode the user picked.
 * The reader probes each known location and normalizes whatever it finds into a
 * single return shape so `AuthCommand` can treat it the same way it treats Claude
 * Code / Codex imports.
 *
 * Discovery order (first hit wins):
 *   1. `~/.gemini/oauth_creds.json`        — OAuth tokens from Google Sign-In flow
 *   2. `~/.gemini/credentials.json`        — alternate OAuth/API-key file used by some builds
 *   3. `~/.gemini/settings.json`           — may carry `apiKey` / `api_key` for API-key mode
 *   4. Env: `GEMINI_API_KEY` or `GOOGLE_API_KEY` — bare API key (no file)
 *
 * Returned `mode` is one of:
 *   - `oauth`    — `access_token` present (+ optionally `refresh_token`, `expires_at` in ms)
 *   - `api_key`  — `api_key` present
 *
 * OAuth refresh is intentionally *not* automated here. Google's refresh endpoint
 * requires the Gemini-CLI client_id/secret pair which is release-specific; instead
 * the caller should re-run `gemini login` if the token is stale, and we fall
 * through to the API-key mode when available.
 */
class GeminiCliCredentials
{
    /** @var array<int, string> */
    private readonly array $candidatePaths;

    public function __construct(
        string ...$paths,
    ) {
        $this->candidatePaths = $paths;
    }

    public static function default(): self
    {
        $home = self::homeDir();
        return new self(
            $home . '/.gemini/oauth_creds.json',
            $home . '/.gemini/credentials.json',
            $home . '/.gemini/settings.json',
        );
    }

    /**
     * True if any of the candidate files (or env-var fallback) yields a usable credential.
     */
    public function exists(): bool
    {
        foreach ($this->candidatePaths as $p) {
            if (is_file($p)) {
                return true;
            }
        }
        return (bool) (getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY'));
    }

    /**
     * Human-friendly pointer for error messages. Returns the first candidate path.
     */
    public function path(): string
    {
        return $this->candidatePaths[0] ?? '';
    }

    /**
     * @return array{mode:string,access_token:?string,refresh_token:?string,expires_at:?int,api_key:?string,source:string}|null
     */
    public function read(): ?array
    {
        foreach ($this->candidatePaths as $path) {
            if (! is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                continue;
            }

            // ── OAuth shape ──────────────────────────────────────
            $access = $data['access_token'] ?? $data['accessToken'] ?? null;
            if (! empty($access)) {
                $expiresAt = $data['expires_at'] ?? $data['expiresAt'] ?? null;
                if (is_string($expiresAt)) {
                    $parsed = strtotime($expiresAt);
                    $expiresAt = $parsed !== false ? $parsed * 1000 : null;
                } elseif (is_numeric($expiresAt)) {
                    // Assume seconds if it looks like a unix timestamp, ms otherwise
                    $expiresAt = (int) $expiresAt;
                    if ($expiresAt < 10_000_000_000) {
                        $expiresAt *= 1000;
                    }
                }
                return [
                    'mode' => 'oauth',
                    'access_token' => (string) $access,
                    'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token']
                        : (isset($data['refreshToken']) ? (string) $data['refreshToken'] : null),
                    'expires_at' => $expiresAt,
                    'api_key' => null,
                    'source' => $path,
                ];
            }

            // ── API-key shape (settings.json or credentials.json) ─
            $apiKey = $data['apiKey'] ?? $data['api_key'] ?? $data['GEMINI_API_KEY'] ?? $data['GOOGLE_API_KEY'] ?? null;
            if (! empty($apiKey)) {
                return [
                    'mode' => 'api_key',
                    'access_token' => null,
                    'refresh_token' => null,
                    'expires_at' => null,
                    'api_key' => (string) $apiKey,
                    'source' => $path,
                ];
            }
        }

        // ── Env-var fallback ─────────────────────────────────────
        $envKey = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY');
        if ($envKey) {
            return [
                'mode' => 'api_key',
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
                'api_key' => (string) $envKey,
                'source' => 'env',
            ];
        }

        return null;
    }

    public function isExpired(array $creds, int $skewSeconds = 60): bool
    {
        if (($creds['mode'] ?? '') !== 'oauth') {
            return false;
        }
        $expiresAt = $creds['expires_at'] ?? null;
        if (! $expiresAt) {
            return false;
        }
        // expires_at is in milliseconds per our read() normalization
        return (int) floor($expiresAt / 1000) - $skewSeconds <= time();
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
