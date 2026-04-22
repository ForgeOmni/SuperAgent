<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\MiniMax;

use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * MiniMax music generation (`music-2.6`).
 *
 * Submit endpoint: POST `v1/music_generation` with lyrics + optional
 * reference audio / style knobs. The MiniMax implementation varies
 * between "sync-ish" (task returns inline within seconds for short
 * tracks) and "fully async with `task_id`" (longer tracks). This tool
 * handles both transparently: if the submit response already carries
 * an audio payload, return it; otherwise poll `v1/query/music_generation`
 * until the job finishes.
 *
 * Response normalisation matches `MiniMaxTtsTool` — `{audio, encoding,
 * format, trace_id}`.
 */
class MiniMaxMusicTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'minimax_music';
    }

    public function description(): string
    {
        return 'Generate a music track with MiniMax music-2.6. Accepts '
            . 'lyrics, a musical style/prompt, optional reference audio, '
            . 'and an instrumental flag. Returns an audio URL or base64 '
            . 'payload once the track is rendered.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Musical style / vibe prompt.',
                ],
                'lyrics' => [
                    'type' => 'string',
                    'description' => 'Lyrics (ignored when instrumental=true).',
                ],
                'instrumental' => [
                    'type' => 'boolean',
                    'description' => 'If true, generate instrumental without vocals.',
                    'default' => false,
                ],
                'reference_audio_url' => [
                    'type' => 'string',
                    'description' => 'Optional reference audio to emulate style / voice.',
                ],
                'duration_seconds' => [
                    'type' => 'integer',
                    'description' => 'Target duration (provider clamps to supported range).',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'MiniMax music model (default music-2.6).',
                    'default' => 'music-2.6',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'Max sync-wait when the job is async (default 180).',
                    'default' => 180,
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
                'model' => $input['model'] ?? 'music-2.6',
                'prompt' => $prompt,
                'instrumental' => (bool) ($input['instrumental'] ?? false),
            ];
            if (! empty($input['lyrics'])) {
                $body['lyrics'] = (string) $input['lyrics'];
            }
            if (! empty($input['reference_audio_url'])) {
                $body['reference_audio'] = ['url' => (string) $input['reference_audio_url']];
            }
            if (! empty($input['duration_seconds'])) {
                $body['duration'] = (int) $input['duration_seconds'];
            }

            $submit = $this->client()->post('v1/music_generation', ['json' => $body]);
            $decoded = json_decode((string) $submit->getBody(), true);
            $upstream = is_array($decoded) ? $decoded : [];

            // Fast path: inline audio payload.
            [$audio, $encoding] = MiniMaxMediaExtractor::extractAudio($upstream);
            if ($audio !== null) {
                return ToolResult::success([
                    'audio' => $audio,
                    'encoding' => $encoding,
                    'trace_id' => $upstream['trace_id'] ?? null,
                ]);
            }

            // Async path: poll by task_id.
            $taskId = $upstream['task_id'] ?? ($upstream['data']['task_id'] ?? null);
            if (! is_string($taskId) || $taskId === '') {
                return ToolResult::error('MiniMax returned neither audio nor task_id');
            }

            $final = $this->pollUntilDone(
                fn () => $this->probeMusic($taskId),
                (int) ($input['timeout_seconds'] ?? 180),
                2.0,
            );

            return ToolResult::success([
                'audio' => $final['audio'] ?? null,
                'encoding' => $final['encoding'] ?? null,
                'task_id' => $taskId,
            ]);
        });
    }

    private function probeMusic(string $taskId): array
    {
        $response = $this->client()->get('v1/query/music_generation', [
            'query' => ['task_id' => $taskId],
        ]);
        $decoded = json_decode((string) $response->getBody(), true);
        $upstream = is_array($decoded) ? $decoded : [];

        $status = (string) ($upstream['status'] ?? $upstream['task_status'] ?? 'unknown');
        [$audio, $encoding] = MiniMaxMediaExtractor::extractAudio($upstream);

        $normalised = match (strtolower($status)) {
            'success', 'completed', 'done', 'finished' => 'done',
            'failed', 'error', 'cancelled', 'canceled' => 'failed',
            default => $audio !== null ? 'done' : $status,
        };

        return [
            'status' => $normalised,
            'audio' => $audio,
            'encoding' => $encoding,
            'error' => $upstream['error'] ?? ($upstream['message'] ?? null),
        ];
    }
}
