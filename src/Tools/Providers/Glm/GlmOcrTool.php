<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Glm;

use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Z.AI Layout Parsing / GLM-OCR.
 *
 * Hits GLM's layout endpoint with either a URL or base64 payload, returns
 * `{text, blocks?}` where `blocks` is structural layout (paragraph /
 * table / figure) when the upstream provides it.
 *
 * Endpoint (POST): `tools/layout_parsing`
 *   body: {"model": "glm-ocr", "input": {"url"|"base64": "..."}, "options": {...}}
 *
 * Schema tolerance: the tool accepts several candidate response shapes
 * (`text` flat, `data.text`, `result.blocks[].text` concatenated) so it
 * survives minor upstream drift across GLM-4.x / GLM-5.
 */
class GlmOcrTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'glm_ocr';
    }

    public function description(): string
    {
        return 'Run GLM-OCR / Layout Parsing on a document or image. '
            . 'Accepts a URL or a base64-encoded payload. Returns '
            . 'extracted plain text and (when available) structural layout blocks.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute URL of the document/image. Provide either url OR base64.',
                ],
                'base64' => [
                    'type' => 'string',
                    'description' => 'Base64-encoded document/image bytes.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'OCR model id (default glm-ocr).',
                    'default' => 'glm-ocr',
                ],
                'want_blocks' => [
                    'type' => 'boolean',
                    'description' => 'Request structural layout blocks (default true).',
                    'default' => true,
                ],
            ],
        ];
    }

    public function attributes(): array
    {
        return ['network', 'cost'];
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $url = $input['url'] ?? null;
            $b64 = $input['base64'] ?? null;
            if (! (is_string($url) && $url !== '') && ! (is_string($b64) && $b64 !== '')) {
                return ToolResult::error('either url or base64 is required');
            }

            $payload = is_string($url) && $url !== ''
                ? ['url' => $url]
                : ['base64' => $b64];

            $body = [
                'model' => $input['model'] ?? 'glm-ocr',
                'input' => $payload,
                'options' => [
                    'want_blocks' => (bool) ($input['want_blocks'] ?? true),
                ],
            ];

            $response = $this->client()->post('tools/layout_parsing', ['json' => $body]);
            $decoded = json_decode((string) $response->getBody(), true);

            return ToolResult::success([
                'text' => self::extractText($decoded),
                'blocks' => self::extractBlocks($decoded),
            ]);
        });
    }

    private static function extractText($decoded): string
    {
        if (! is_array($decoded)) {
            return '';
        }
        foreach ([
            $decoded['text'] ?? null,
            $decoded['data']['text'] ?? null,
            $decoded['result']['text'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        // Fallback: concatenate blocks[].text
        $blocks = self::extractBlocks($decoded);
        $parts = [];
        foreach ($blocks as $block) {
            if (! empty($block['text']) && is_string($block['text'])) {
                $parts[] = $block['text'];
            }
        }
        return implode("\n", $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extractBlocks($decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }
        foreach ([
            $decoded['blocks'] ?? null,
            $decoded['data']['blocks'] ?? null,
            $decoded['result']['blocks'] ?? null,
        ] as $candidate) {
            if (is_array($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }
        return [];
    }
}
