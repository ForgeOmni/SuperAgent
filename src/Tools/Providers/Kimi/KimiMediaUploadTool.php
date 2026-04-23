<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Upload a video or image to Kimi's Files API and return an `ms://<id>`
 * URI that subsequent chat requests can reference directly as a
 * multimodal content part.
 *
 * This is the multimodal counterpart to `KimiFileExtractTool`
 * (documents → extracted text). Where that tool round-trips to
 * extracted text, this one yields a vendor URI the model inlines as
 * a first-class media part — avoiding base64 inflation on the chat
 * request body.
 *
 * Endpoint: `POST /v1/files` with `purpose=video|image` (see kimi-cli
 * `packages/kosong/src/kosong/chat_provider/kimi.py:249-274`).
 * Response shape: `{"id": "..."}`. The convention is that callers
 * then pass `"ms://{id}"` anywhere the model accepts a URL (e.g.
 * `video_url.url` or `image_url.url` content parts).
 *
 * Limits:
 *   - `purpose=video`: Moonshot-documented max size varies by plan;
 *     we don't enforce it client-side — the API returns 413 which
 *     we surface as a ToolResult error.
 *   - `purpose=image`: same — client stays thin.
 */
class KimiMediaUploadTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'kimi_media_upload';
    }

    public function description(): string
    {
        return 'Upload a video or image to Kimi and get back a `ms://<id>` '
            . 'URI. Reference the URI from any subsequent chat request '
            . '(video_url / image_url content parts) — no base64 inflation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Local path to the media file.',
                ],
                'mime_type' => [
                    'type' => 'string',
                    'description' => 'Required — e.g. "video/mp4" or "image/png". '
                        . 'Used both as the multipart part type AND to pick the '
                        . '`purpose` (video/* → video, image/* → image).',
                ],
                'purpose' => [
                    'type' => 'string',
                    'description' => 'Optional override — "video" or "image". '
                        . 'When absent, derived from `mime_type` prefix.',
                    'enum' => ['video', 'image'],
                ],
            ],
            'required' => ['file_path', 'mime_type'],
        ];
    }

    public function attributes(): array
    {
        return ['network', 'cost', 'sensitive'];
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $path = $input['file_path'] ?? null;
            if (! is_string($path) || $path === '') {
                return ToolResult::error('file_path is required and must be a string');
            }
            if (! is_file($path) || ! is_readable($path)) {
                return ToolResult::error("File not readable: {$path}");
            }
            $mime = (string) ($input['mime_type'] ?? '');
            if ($mime === '') {
                return ToolResult::error('mime_type is required (e.g. video/mp4, image/png)');
            }

            $purpose = $input['purpose'] ?? $this->derivePurpose($mime);
            if ($purpose === null) {
                return ToolResult::error(
                    "Cannot derive purpose from mime_type '{$mime}' — pass an explicit "
                    . "`purpose` (video|image)."
                );
            }

            $id = $this->upload($path, $mime, $purpose);
            return ToolResult::success([
                'file_id' => $id,
                'uri'     => 'ms://' . $id,
                'purpose' => $purpose,
                'mime_type' => $mime,
                'bytes'   => filesize($path) ?: null,
            ]);
        });
    }

    /**
     * POST /v1/files with multipart/form-data: file + purpose.
     * Returns the file id from the JSON response.
     */
    private function upload(string $path, string $mimeType, string $purpose): string
    {
        $parts = [
            ['name' => 'purpose', 'contents' => $purpose],
            [
                'name'     => 'file',
                'contents' => Utils::tryFopen($path, 'r'),
                'filename' => basename($path),
                'headers'  => ['Content-Type' => $mimeType],
            ],
        ];

        $boundary = bin2hex(random_bytes(16));
        $body = new MultipartStream($parts, $boundary);

        $response = $this->client()->post('v1/files', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw new \RuntimeException('Kimi /v1/files returned no file id');
        }
        return (string) $decoded['id'];
    }

    /**
     * Map a MIME type to an upload purpose Moonshot accepts. Returns
     * null when the mime family isn't one of the supported media types
     * — callers can still force a purpose via `$input['purpose']`.
     */
    public static function derivePurpose(string $mimeType): ?string
    {
        $mime = strtolower($mimeType);
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        return null;
    }
}
