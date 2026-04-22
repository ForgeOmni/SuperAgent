<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Glm;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * GLM-ASR audio transcription.
 *
 * OpenAI-shaped multipart endpoint: POST audio/transcriptions
 *   form fields:  file, model=glm-asr-2512 (default), language?,
 *                 response_format? (json|text|verbose_json|srt|vtt)
 *
 * Returns `{text, segments?, language?}`. When `response_format` is a
 * subtitle format (srt/vtt/text), the upstream returns raw text rather
 * than JSON — the tool passes that through as `text` with empty
 * segments.
 */
class GlmAsrTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'glm_asr';
    }

    public function description(): string
    {
        return 'Transcribe an audio file with GLM-ASR (speech-to-text). '
            . 'Accepts common audio formats (mp3/wav/m4a/ogg). Returns '
            . 'plain text plus optional segment timings when requested.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Local path to an audio file.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'ASR model id (default glm-asr-2512).',
                    'default' => 'glm-asr-2512',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Language hint (ISO-639, e.g. "zh", "en").',
                ],
                'response_format' => [
                    'type' => 'string',
                    'enum' => ['json', 'text', 'verbose_json', 'srt', 'vtt'],
                    'description' => 'Response shape (default verbose_json with segments).',
                    'default' => 'verbose_json',
                ],
            ],
            'required' => ['file_path'],
        ];
    }

    public function attributes(): array
    {
        return ['network', 'cost'];
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $path = $input['file_path'] ?? null;
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                return ToolResult::error('file_path must point to a readable audio file');
            }

            $format = $input['response_format'] ?? 'verbose_json';
            $parts = [
                ['name' => 'model', 'contents' => (string) ($input['model'] ?? 'glm-asr-2512')],
                ['name' => 'response_format', 'contents' => (string) $format],
                ['name' => 'file', 'contents' => Utils::tryFopen($path, 'r'), 'filename' => basename($path)],
            ];
            if (! empty($input['language'])) {
                $parts[] = ['name' => 'language', 'contents' => (string) $input['language']];
            }

            $boundary = bin2hex(random_bytes(16));
            $body = new MultipartStream($parts, $boundary);

            $response = $this->client()->post('audio/transcriptions', [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
                'body' => $body,
            ]);
            $raw = (string) $response->getBody();

            // Non-JSON formats (text / srt / vtt) come back as plain strings.
            if (in_array($format, ['text', 'srt', 'vtt'], true)) {
                return ToolResult::success([
                    'text' => $raw,
                    'format' => $format,
                    'segments' => [],
                ]);
            }

            $decoded = json_decode($raw, true);
            $text = is_array($decoded) ? (string) ($decoded['text'] ?? '') : '';
            $segments = (is_array($decoded) && isset($decoded['segments']) && is_array($decoded['segments']))
                ? $decoded['segments']
                : [];
            $language = is_array($decoded) ? ($decoded['language'] ?? null) : null;

            return ToolResult::success([
                'text' => $text,
                'format' => $format,
                'segments' => $segments,
                'language' => $language,
            ]);
        });
    }
}
