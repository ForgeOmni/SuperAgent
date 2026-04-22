<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Upload a PDF / PPT / DOCX / TXT to the Kimi Files API with
 * `purpose=file-extract`, then fetch the server-side extracted plain
 * text. Usable by any main brain (Claude, GPT, Gemini, Qwen, …) via
 * standard tool-calling — the brain doesn't need to speak Kimi's
 * API natively.
 *
 * Kimi endpoints used (documented in the Kimi Platform API index):
 *   POST /v1/files                multipart: file + purpose
 *   GET  /v1/files/{id}/content   → 200 OK with text body
 *
 * The tool is stateful on the vendor side (uploading creates a stored
 * file) but SuperAgent doesn't consider that "read-only from our
 * perspective" — uploading is a write the user should approve. Hence
 * `isReadOnly() = false` and the `sensitive` attribute.
 *
 * Cost: Kimi bills a small per-MB fee for file storage + extraction.
 */
class KimiFileExtractTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'kimi_file_extract';
    }

    public function description(): string
    {
        return 'Upload a document (PDF, PPT, DOCX, TXT, Markdown) to Kimi '
            . 'and retrieve its extracted plain-text content. Useful when '
            . 'the main model needs to read a long file that exceeds its '
            . 'context or that requires server-side layout parsing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Local path to the file to upload.',
                ],
                'mime_type' => [
                    'type' => 'string',
                    'description' => 'Optional MIME type override (e.g. application/pdf).',
                ],
            ],
            'required' => ['file_path'],
        ];
    }

    public function attributes(): array
    {
        return ['network', 'cost', 'sensitive'];
    }

    public function isReadOnly(): bool
    {
        // Uploading mutates vendor-side storage.
        return false;
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

            $fileId = $this->upload($path, $input['mime_type'] ?? null);
            $text = $this->fetchContent($fileId);

            return ToolResult::success([
                'file_id' => $fileId,
                'text' => $text,
                'bytes' => strlen($text),
            ]);
        });
    }

    /**
     * POST /v1/files with multipart/form-data: file + purpose=file-extract
     * Returns the file id from the JSON response.
     */
    private function upload(string $path, ?string $mimeType): string
    {
        $parts = [
            [
                'name' => 'purpose',
                'contents' => 'file-extract',
            ],
            [
                'name' => 'file',
                'contents' => Utils::tryFopen($path, 'r'),
                'filename' => basename($path),
            ],
        ];
        if ($mimeType !== null && $mimeType !== '') {
            $parts[1]['headers'] = ['Content-Type' => $mimeType];
        }

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
     * GET /v1/files/{id}/content
     *
     * Kimi returns the extracted text directly as the response body (no
     * JSON envelope) when purpose=file-extract was used at upload time.
     */
    private function fetchContent(string $fileId): string
    {
        $response = $this->client()->get('v1/files/' . rawurlencode($fileId) . '/content');
        $body = (string) $response->getBody();

        // Some Kimi variants wrap the text in `{"content": "..."}`. Accept
        // either form transparently so the tool survives schema drift.
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['content']) && is_string($decoded['content'])) {
            return $decoded['content'];
        }
        return $body;
    }
}
