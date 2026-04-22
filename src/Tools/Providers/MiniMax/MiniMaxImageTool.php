<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\MiniMax;

use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * MiniMax image generation (`image-01`) — text-to-image and
 * image-to-image. MiniMax's image endpoint is typically synchronous:
 * submit returns an `images` array directly. If a deployment ever
 * switches to async the shared task_id path lands in the
 * poll helper — not wired here to keep the sync path clean.
 *
 * Endpoint: POST `v1/image_generation`
 */
class MiniMaxImageTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'minimax_image';
    }

    public function description(): string
    {
        return 'Generate images with MiniMax image-01. Supports text-to-image '
            . '(prompt only) and image-to-image (reference image provided). '
            . 'Returns an array of image entries (URL or base64 payload).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Text prompt describing the image.',
                ],
                'reference_image_url' => [
                    'type' => 'string',
                    'description' => 'Optional reference image URL for I2I mode.',
                ],
                'aspect_ratio' => [
                    'type' => 'string',
                    'description' => 'Aspect ratio (1:1, 16:9, 9:16, 4:3, 3:4, …).',
                ],
                'n' => [
                    'type' => 'integer',
                    'description' => 'Number of images to generate (1-4).',
                    'default' => 1,
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Model id (default image-01).',
                    'default' => 'image-01',
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
                'model' => $input['model'] ?? 'image-01',
                'prompt' => $prompt,
                'n' => max(1, min(4, (int) ($input['n'] ?? 1))),
            ];
            if (! empty($input['aspect_ratio'])) {
                $body['aspect_ratio'] = (string) $input['aspect_ratio'];
            }
            if (! empty($input['reference_image_url'])) {
                $body['subject_reference'] = [[
                    'type' => 'image',
                    'image_url' => (string) $input['reference_image_url'],
                ]];
            }

            $response = $this->client()->post('v1/image_generation', ['json' => $body]);
            $decoded = json_decode((string) $response->getBody(), true);

            $images = MiniMaxMediaExtractor::extractImages($decoded);
            if ($images === []) {
                return ToolResult::error('MiniMax response contained no images');
            }

            return ToolResult::success([
                'images' => $images,
                'count' => count($images),
                'model' => $body['model'],
            ]);
        });
    }
}
