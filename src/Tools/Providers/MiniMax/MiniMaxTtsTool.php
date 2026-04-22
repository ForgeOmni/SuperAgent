<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\MiniMax;

use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Synchronous text-to-speech via MiniMax T2A v2 (`/v1/t2a_v2`).
 *
 * The sync endpoint caps at ~10 000 characters per request; longer text
 * should use the async variant (`submitTTS()` on a `SupportsTTS` provider
 * — separate Tool lands when async polling plumbing is built out).
 *
 * Schema note: MiniMax has iterated on the T2A response shape several
 * times (`data.audio` as hex, then as base64, then as a CDN URL). This
 * tool normalises the three forms to a single `audio` field with a
 * companion `encoding` hint (`hex` / `base64` / `url`) so callers can
 * decode without guessing.
 *
 * Attributes: `network` + `cost`. Always metered per generated second.
 */
class MiniMaxTtsTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'minimax_tts';
    }

    public function description(): string
    {
        return 'Synthesise speech from text via MiniMax T2A. Returns audio '
            . 'as bytes (base64), hex, or a CDN URL depending on the '
            . 'MiniMax account format. Sync endpoint is capped at ~10 000 '
            . 'characters per call.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'description' => 'Text to synthesise (max ~10 000 characters).',
                ],
                'voice_id' => [
                    'type' => 'string',
                    'description' => 'Preset voice id (e.g. "male-qn-qingse") or a previously cloned voice id.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'TTS model id — default "speech-2.8-hd".',
                    'default' => 'speech-2.8-hd',
                ],
                'speed' => [
                    'type' => 'number',
                    'description' => 'Playback speed (0.5 – 2.0, default 1.0).',
                    'default' => 1.0,
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['mp3', 'wav', 'pcm', 'flac'],
                    'description' => 'Output audio format (default mp3).',
                    'default' => 'mp3',
                ],
            ],
            'required' => ['text', 'voice_id'],
        ];
    }

    public function attributes(): array
    {
        return ['network', 'cost'];
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $text = $input['text'] ?? null;
            $voiceId = $input['voice_id'] ?? null;
            if (! is_string($text) || $text === '') {
                return ToolResult::error('text is required');
            }
            if (! is_string($voiceId) || $voiceId === '') {
                return ToolResult::error('voice_id is required');
            }
            if (mb_strlen($text) > 10_000) {
                return ToolResult::error(
                    'text exceeds 10 000 characters — use the async long-form endpoint instead',
                );
            }

            $body = [
                'model' => $input['model'] ?? 'speech-2.8-hd',
                'text' => $text,
                'voice_setting' => [
                    'voice_id' => $voiceId,
                    'speed' => (float) ($input['speed'] ?? 1.0),
                ],
                'audio_setting' => [
                    'format' => $input['format'] ?? 'mp3',
                ],
                'stream' => false,
            ];

            $response = $this->client()->post('v1/t2a_v2', ['json' => $body]);
            $decoded = json_decode((string) $response->getBody(), true);

            [$audio, $encoding] = MiniMaxMediaExtractor::extractAudio($decoded);
            if ($audio === null) {
                return ToolResult::error('MiniMax response contained no audio payload');
            }

            return ToolResult::success([
                'audio' => $audio,
                'encoding' => $encoding,
                'format' => $body['audio_setting']['format'],
                'trace_id' => $decoded['trace_id'] ?? null,
                'extra_info' => $decoded['extra_info'] ?? null,
            ]);
        });
    }
}
