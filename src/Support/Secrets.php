<?php

declare(strict_types=1);

namespace SuperAgent\Support;

/**
 * Keeps credentials out of anything that leaves the process.
 *
 * Provider configuration travels as a plain array — through spawn configs,
 * telemetry payloads, log context, exception data — and it carries the API
 * key. One `json_encode()` of that array in a host's log line publishes a
 * tenant's credential, and nothing in the array's shape warns anyone.
 *
 * @since 1.5.0
 */
final class Secrets
{
    public const REDACTED = '[redacted]';

    /**
     * Keys whose values never appear in serialised output.
     *
     * Matching ignores case and separators, so one entry covers
     * `api_key`, `apiKey`, `X-Api-Key` and `ANTHROPIC_API_KEY` alike —
     * every vendor spells its own differently, and a list of exact names
     * would be one header short on the day it matters.
     */
    private const SECRET_KEY_PATTERNS = [
        'apikey',
        'accesstoken',
        'refreshtoken',
        'idtoken',
        'secret',
        'password',
        'passwd',
        'authorization',
        'authtoken',
        'sessiontoken',
        'privatekey',
        'credential',
        'bearer',
    ];

    /**
     * A copy of $data with every secret-looking value replaced, at any depth.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $out[$key] = $value === null || $value === '' ? $value : self::REDACTED;
                continue;
            }

            $out[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }

    public static function isSecretKey(string $key): bool
    {
        $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        foreach (self::SECRET_KEY_PATTERNS as $pattern) {
            if (str_contains($normalised, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A value safe to show a human: the last four characters, nothing else.
     * For a support conversation about *which* key is configured, never for a
     * log line — a fingerprint is still a fact about a credential.
     */
    public static function fingerprint(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::REDACTED;
        }

        return strlen($value) <= 4
            ? self::REDACTED
            : '…' . substr($value, -4);
    }
}
