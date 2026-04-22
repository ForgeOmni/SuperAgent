<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\MiniMax;

/**
 * Shared helpers for pulling audio / video / image payloads out of the
 * various MiniMax response shapes. The same `{audio_url | audio}` and
 * `{video_url | file_id}` envelopes show up on the TTS, music, image and
 * video endpoints with tiny variations across model versions.
 *
 * Keeping the extraction logic in one place means each concrete tool is
 * mostly just "wire up the endpoint, call extract*, return ToolResult."
 */
final class MiniMaxMediaExtractor
{
    /**
     * @param mixed $decoded
     * @return array{0: string|null, 1: string|null} [payload, encoding]
     *         encoding ∈ {'url', 'hex', 'base64'} or null when no payload found
     */
    public static function extractAudio($decoded): array
    {
        if (! is_array($decoded)) {
            return [null, null];
        }

        foreach ([
            $decoded['audio_url'] ?? null,
            $decoded['data']['audio_url'] ?? null,
            $decoded['data']['audio']['url'] ?? null,
        ] as $url) {
            if (is_string($url) && $url !== '') {
                return [$url, 'url'];
            }
        }

        foreach ([
            $decoded['audio'] ?? null,
            $decoded['data']['audio'] ?? null,
        ] as $audio) {
            if (is_string($audio) && $audio !== '') {
                return [$audio, self::detectEncoding($audio)];
            }
        }

        return [null, null];
    }

    /**
     * @param mixed $decoded
     * @return array{0: string|null, 1: string|null}
     */
    public static function extractVideo($decoded): array
    {
        if (! is_array($decoded)) {
            return [null, null];
        }

        foreach ([
            $decoded['video_url'] ?? null,
            $decoded['data']['video_url'] ?? null,
            $decoded['data']['video']['url'] ?? null,
            $decoded['file']['url'] ?? null,
        ] as $url) {
            if (is_string($url) && $url !== '') {
                return [$url, 'url'];
            }
        }

        foreach ([
            $decoded['file_id'] ?? null,
            $decoded['data']['file_id'] ?? null,
        ] as $fid) {
            if (is_string($fid) && $fid !== '') {
                return [$fid, 'file_id'];
            }
        }

        return [null, null];
    }

    /**
     * @param mixed $decoded
     * @return array<int, array{url?: string, base64?: string}>
     */
    public static function extractImages($decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $candidates = $decoded['images']
            ?? ($decoded['data']['images'] ?? null)
            ?? ($decoded['data'] ?? null);

        if (! is_array($candidates)) {
            return [];
        }

        $out = [];
        foreach ($candidates as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = filter_var($item, FILTER_VALIDATE_URL)
                    ? ['url' => $item]
                    : ['base64' => $item];
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            if (! empty($item['url']) && is_string($item['url'])) {
                $out[] = ['url' => $item['url']];
                continue;
            }
            if (! empty($item['b64_json']) && is_string($item['b64_json'])) {
                $out[] = ['base64' => $item['b64_json']];
                continue;
            }
            if (! empty($item['image_base64']) && is_string($item['image_base64'])) {
                $out[] = ['base64' => $item['image_base64']];
            }
        }
        return $out;
    }

    public static function detectEncoding(string $payload): string
    {
        if (strlen($payload) % 2 === 0 && preg_match('/^[0-9a-fA-F]+$/', $payload)) {
            return 'hex';
        }
        return 'base64';
    }
}
