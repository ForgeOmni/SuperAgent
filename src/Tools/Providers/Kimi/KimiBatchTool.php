<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Submit a JSONL batch of chat requests to Kimi, wait for completion,
 * and return the aggregated output lines. OpenAI-shaped `/v1/batches`
 * API.
 *
 * Flow:
 *   1. POST /v1/files (purpose=batch)       → input_file_id
 *   2. POST /v1/batches {input_file_id, endpoint, completion_window}
 *   3. GET  /v1/batches/{id}                → poll until status=completed
 *   4. GET  /v1/files/{output_file_id}/content  → JSONL of responses
 *
 * Default sync-wait timeout is aggressive (180s) because Kimi batches are
 * usually seconds-to-minutes. Callers that want fire-and-forget can set
 * `wait=false` to get back a `{batch_id}` handle for later polling.
 */
class KimiBatchTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'kimi_batch';
    }

    public function description(): string
    {
        return 'Submit a batch of chat-completion requests to Kimi (JSONL), '
            . 'wait for the batch to complete, and return the output lines. '
            . 'Useful for bulk-processing many prompts at reduced per-token cost.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jsonl_path' => [
                    'type' => 'string',
                    'description' => 'Local path to a JSONL file. Each line must be a '
                        . 'Kimi chat-completion request envelope.',
                ],
                'endpoint' => [
                    'type' => 'string',
                    'description' => 'Kimi endpoint to batch against (default /v1/chat/completions).',
                    'default' => '/v1/chat/completions',
                ],
                'completion_window' => [
                    'type' => 'string',
                    'description' => 'Kimi completion-window hint (default "24h").',
                    'default' => '24h',
                ],
                'wait' => [
                    'type' => 'boolean',
                    'description' => 'If true (default) sync-wait for the batch; if false, return batch_id immediately.',
                    'default' => true,
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'Max seconds to wait when wait=true (default 180).',
                    'default' => 180,
                ],
            ],
            'required' => ['jsonl_path'],
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
            $path = $input['jsonl_path'] ?? null;
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                return ToolResult::error('jsonl_path must point to a readable file');
            }

            $inputFileId = $this->uploadBatchInput($path);
            $batchId = $this->createBatch(
                $inputFileId,
                (string) ($input['endpoint'] ?? '/v1/chat/completions'),
                (string) ($input['completion_window'] ?? '24h'),
            );

            if (! ($input['wait'] ?? true)) {
                return ToolResult::success([
                    'batch_id' => $batchId,
                    'input_file_id' => $inputFileId,
                    'status' => 'submitted',
                ]);
            }

            $final = $this->pollUntilDone(
                fn () => $this->probeBatch($batchId),
                (int) ($input['timeout_seconds'] ?? 180),
                2.0,
            );

            $output = null;
            if (! empty($final['output_file_id'])) {
                $output = $this->fetchOutputJsonl((string) $final['output_file_id']);
            }

            return ToolResult::success([
                'batch_id' => $batchId,
                'status' => 'completed',
                'input_file_id' => $inputFileId,
                'output_file_id' => $final['output_file_id'] ?? null,
                'output' => $output,
                'request_counts' => $final['request_counts'] ?? null,
            ]);
        });
    }

    private function uploadBatchInput(string $path): string
    {
        $parts = [
            ['name' => 'purpose', 'contents' => 'batch'],
            ['name' => 'file', 'contents' => Utils::tryFopen($path, 'r'), 'filename' => basename($path)],
        ];
        $boundary = bin2hex(random_bytes(16));
        $body = new MultipartStream($parts, $boundary);

        $response = $this->client()->post('v1/files', [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            'body' => $body,
        ]);
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw new \RuntimeException('Kimi /v1/files returned no file id');
        }
        return (string) $decoded['id'];
    }

    private function createBatch(string $inputFileId, string $endpoint, string $window): string
    {
        $response = $this->client()->post('v1/batches', [
            'json' => [
                'input_file_id' => $inputFileId,
                'endpoint' => $endpoint,
                'completion_window' => $window,
            ],
        ]);
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw new \RuntimeException('Kimi /v1/batches returned no batch id');
        }
        return (string) $decoded['id'];
    }

    /**
     * Map Kimi's batch statuses to the `pollUntilDone` contract:
     *   - `completed`          → 'done'
     *   - `failed`|`expired`|`cancelled` → 'failed'
     *   - everything else (`validating`, `in_progress`, `finalizing`) → pass through
     */
    private function probeBatch(string $batchId): array
    {
        $response = $this->client()->get('v1/batches/' . rawurlencode($batchId));
        $decoded = json_decode((string) $response->getBody(), true);
        $upstream = is_array($decoded) ? $decoded : [];

        $status = (string) ($upstream['status'] ?? 'unknown');
        $normalised = match ($status) {
            'completed' => 'done',
            'failed', 'expired', 'cancelled', 'canceled' => 'failed',
            default => $status,
        };

        return array_merge($upstream, [
            'status' => $normalised,
            'error' => $upstream['errors']['data'][0]['message'] ?? null,
        ]);
    }

    private function fetchOutputJsonl(string $outputFileId): string
    {
        $response = $this->client()->get('v1/files/' . rawurlencode($outputFileId) . '/content');
        return (string) $response->getBody();
    }
}
