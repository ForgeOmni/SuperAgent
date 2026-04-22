<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\MiniMax;

use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * MiniMax Hailuo video generation — text-to-video and image-to-video.
 *
 * Submit: POST `v1/video_generation` with `{model, prompt, first_frame_image?}`
 * Poll:   GET  `v1/query/video_generation?task_id=...`
 * Fetch:  once `status=success`, the payload carries `file.url` or `file_id`.
 *
 * Default timeout is generous (300s) because even "Hailuo-2.3-Fast" can
 * take a minute for a short clip. Callers can pass `wait=false` to get
 * back the `task_id` immediately and poll externally.
 */
class MiniMaxVideoTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'minimax_video';
    }

    public function description(): string
    {
        return 'Generate a short video clip with MiniMax Hailuo. Supports '
            . 'text-to-video (prompt only) and image-to-video (first frame '
            . 'provided). Returns a CDN URL or file id once the render '
            . 'finishes; pass wait=false to poll externally.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Text prompt describing the video.',
                ],
                'first_frame_url' => [
                    'type' => 'string',
                    'description' => 'Optional URL to a first-frame image (triggers I2V mode).',
                ],
                'first_frame_base64' => [
                    'type' => 'string',
                    'description' => 'Optional base64 image bytes for I2V mode.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Model id — MiniMax-Hailuo-2.3 | Hailuo-2.3-Fast | Hailuo-02',
                    'default' => 'MiniMax-Hailuo-2.3',
                ],
                'wait' => [
                    'type' => 'boolean',
                    'description' => 'If true (default) sync-wait; if false, return task_id immediately.',
                    'default' => true,
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'Max seconds to sync-wait (default 300).',
                    'default' => 300,
                ],
            ],
            'required' => ['prompt'],
        ];
    }

    public function attributes(): array
    {
        return ['network', 'cost'];
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $prompt = $input['prompt'] ?? null;
            if (! is_string($prompt) || trim($prompt) === '') {
                return ToolResult::error('prompt is required');
            }

            $body = [
                'model' => (string) ($input['model'] ?? 'MiniMax-Hailuo-2.3'),
                'prompt' => $prompt,
            ];
            if (! empty($input['first_frame_url'])) {
                $body['first_frame_image'] = ['url' => (string) $input['first_frame_url']];
            } elseif (! empty($input['first_frame_base64'])) {
                $body['first_frame_image'] = ['base64' => (string) $input['first_frame_base64']];
            }

            $submit = $this->client()->post('v1/video_generation', ['json' => $body]);
            $decoded = json_decode((string) $submit->getBody(), true);
            $upstream = is_array($decoded) ? $decoded : [];

            $taskId = $upstream['task_id'] ?? ($upstream['data']['task_id'] ?? null);
            if (! is_string($taskId) || $taskId === '') {
                return ToolResult::error('MiniMax submit returned no task_id');
            }

            if (! ($input['wait'] ?? true)) {
                return ToolResult::success([
                    'task_id' => $taskId,
                    'status' => 'submitted',
                ]);
            }

            $final = $this->pollUntilDone(
                fn () => $this->probeVideo($taskId),
                (int) ($input['timeout_seconds'] ?? 300),
                3.0,
            );

            return ToolResult::success([
                'task_id' => $taskId,
                'video' => $final['video'] ?? null,
                'encoding' => $final['encoding'] ?? null,
                'model' => $body['model'],
            ]);
        });
    }

    private function probeVideo(string $taskId): array
    {
        $response = $this->client()->get('v1/query/video_generation', [
            'query' => ['task_id' => $taskId],
        ]);
        $decoded = json_decode((string) $response->getBody(), true);
        $upstream = is_array($decoded) ? $decoded : [];

        $status = (string) ($upstream['status'] ?? $upstream['task_status'] ?? 'unknown');
        [$video, $encoding] = MiniMaxMediaExtractor::extractVideo($upstream);

        $normalised = match (strtolower($status)) {
            'success', 'completed', 'done', 'finished' => 'done',
            'failed', 'error', 'cancelled', 'canceled' => 'failed',
            default => $video !== null ? 'done' : $status,
        };

        return [
            'status' => $normalised,
            'video' => $video,
            'encoding' => $encoding,
            'error' => $upstream['error'] ?? ($upstream['message'] ?? null),
        ];
    }
}
