<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Qwen;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Upload a document to DashScope for Qwen-Long's 10M-token file-reference
 * mode. Returns a file id plus the `fileid://{id}` reference string that
 * callers paste into a Qwen-Long system message to give the model access
 * to the file's content without burning context tokens on it.
 *
 * Endpoint: POST {base}/api/v1/files (purpose=file-extract).
 *
 * Region note: Qwen-Long file mode is currently only available on the
 * `cn` (Beijing) DashScope region — the tool assumes the caller paired
 * it with a Beijing-region QwenProvider. A provider construction error
 * will naturally surface if the region is wrong.
 */
class QwenLongFileTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'qwen_long_file';
    }

    public function description(): string
    {
        return 'Upload a document to Qwen-Long (Alibaba DashScope) and '
            . 'return its file id. Include `fileid://{id}` in a system '
            . 'message of a subsequent qwen-long chat call to give the '
            . 'model access to up to 10M tokens of the file\'s content.';
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
                'purpose' => [
                    'type' => 'string',
                    'description' => 'DashScope file purpose (default "file-extract").',
                    'default' => 'file-extract',
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
        return false;
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $path = $input['file_path'] ?? null;
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                return ToolResult::error('file_path must point to a readable file');
            }

            $parts = [
                ['name' => 'purpose', 'contents' => (string) ($input['purpose'] ?? 'file-extract')],
                ['name' => 'file', 'contents' => Utils::tryFopen($path, 'r'), 'filename' => basename($path)],
            ];
            $boundary = bin2hex(random_bytes(16));
            $body = new MultipartStream($parts, $boundary);

            $response = $this->client()->post('api/v1/files', [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
                'body' => $body,
            ]);
            $decoded = json_decode((string) $response->getBody(), true);

            // DashScope wraps ids in either `id` or `data.id` depending on API version.
            $fileId = null;
            if (is_array($decoded)) {
                $fileId = $decoded['id']
                    ?? ($decoded['data']['id'] ?? null)
                    ?? ($decoded['output']['id'] ?? null);
            }
            if (! is_string($fileId) || $fileId === '') {
                return ToolResult::error('DashScope upload returned no file id');
            }

            return ToolResult::success([
                'file_id' => $fileId,
                'reference' => 'fileid://' . $fileId,
                'filename' => basename($path),
                'bytes' => filesize($path) ?: null,
            ]);
        });
    }
}
