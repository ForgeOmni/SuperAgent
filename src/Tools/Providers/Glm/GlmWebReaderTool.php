<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Glm;

use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Fetch a URL via Z.AI's Web Reader endpoint — the server fetches the
 * page, strips nav chrome / ads / JS, and returns clean text or
 * markdown that a main LLM can consume directly.
 *
 * Companion to `GlmWebSearchTool`: the typical pattern is
 *   search → pick a hit → read that URL → feed to main LLM.
 *
 * Endpoint: POST {base}/tools/web_reader
 *   body: {"url": "...", "output_format": "markdown"|"text"|"html"}
 *
 * Accepts a couple of response shapes that have shipped in GLM-4.x
 * (`content` flat string, `data.content`, `result.text`) and normalises
 * them to a single `content` string.
 */
class GlmWebReaderTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'glm_web_reader';
    }

    public function description(): string
    {
        return 'Fetch a web page via GLM\'s Web Reader and return its main '
            . 'content as clean text or markdown. Use after glm_web_search '
            . 'to read a hit, or any time the main model needs the actual '
            . 'content of a known URL.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute URL to fetch.',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['markdown', 'text', 'html'],
                    'description' => 'Output format (default: markdown).',
                    'default' => 'markdown',
                ],
            ],
            'required' => ['url'],
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
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                return ToolResult::error('url is required and must be a valid absolute URL');
            }

            $format = $input['format'] ?? 'markdown';
            if (! in_array($format, ['markdown', 'text', 'html'], true)) {
                $format = 'markdown';
            }

            $response = $this->client()->post('tools/web_reader', [
                'json' => ['url' => $url, 'output_format' => $format],
            ]);
            $decoded = json_decode((string) $response->getBody(), true);

            return ToolResult::success([
                'url' => $url,
                'format' => $format,
                'content' => self::extractContent($decoded),
            ]);
        });
    }

    /**
     * Accept the handful of response shapes GLM Web Reader has shipped
     * over its lifetime; fall back to an empty string rather than raise
     * when none match.
     *
     * @param mixed $response
     */
    private static function extractContent($response): string
    {
        if (! is_array($response)) {
            return '';
        }

        foreach ([
            $response['content'] ?? null,
            $response['data']['content'] ?? null,
            $response['result']['text'] ?? null,
            $response['result']['content'] ?? null,
            $response['text'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }
}
