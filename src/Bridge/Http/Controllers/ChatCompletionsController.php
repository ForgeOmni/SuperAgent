<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SuperAgent\Bridge\BridgeFactory;
use SuperAgent\Bridge\Converters\OpenAIMessageAdapter;
use SuperAgent\Bridge\Streaming\OpenAIStreamTranslator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatCompletionsController
{
    public function handle(Request $request): JsonResponse|StreamedResponse
    {
        $body = $request->all();
        $streaming = $body['stream'] ?? false;

        // Parse OpenAI messages into internal format
        $parsed = OpenAIMessageAdapter::fromOpenAI($body['messages'] ?? []);
        $messages = $parsed['messages'];
        $systemPrompt = $parsed['systemPrompt'];

        // Resolve model and build options
        $requestedModel = $body['model'] ?? config('superagent.bridge.default_model', 'gpt-4o');
        $options = $this->buildOptions($body, $requestedModel);

        // Build enhanced provider via factory
        $provider = BridgeFactory::createProvider($requestedModel);

        // Convert OpenAI tool definitions to internal format
        $tools = $this->parseTools($body['tools'] ?? []);

        try {
            // Call provider (with enhancements applied)
            $generator = $provider->chat($messages, $tools, $systemPrompt, $options);

            if ($streaming) {
                return $this->streamResponse($generator, $requestedModel);
            }

            return $this->jsonResponse($generator, $requestedModel);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'server_error',
                    'code' => 'internal_error',
                ],
            ], 500);
        }
    }

    private function streamResponse(\Generator $generator, string $model): StreamedResponse
    {
        return new StreamedResponse(function () use ($generator, $model) {
            $translator = new OpenAIStreamTranslator($model);

            foreach ($generator as $assistantMessage) {
                $chunks = $translator->translate($assistantMessage);
                foreach ($chunks as $chunk) {
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function jsonResponse(\Generator $generator, string $model): JsonResponse
    {
        $lastMessage = null;
        foreach ($generator as $msg) {
            $lastMessage = $msg;
        }

        if ($lastMessage === null) {
            return response()->json([
                'error' => ['message' => 'No response from provider', 'type' => 'server_error'],
            ], 500);
        }

        $requestId = bin2hex(random_bytes(12));

        return response()->json(
            OpenAIMessageAdapter::toCompletionResponse($lastMessage, $model, $requestId)
        );
    }

    private function buildOptions(array $body, string $model): array
    {
        $options = [];

        // Map model name if configured
        $modelMap = config('superagent.bridge.model_map', []);
        $options['model'] = $modelMap[$model] ?? $model;

        foreach (['temperature', 'top_p', 'max_tokens', 'max_completion_tokens'] as $key) {
            if (isset($body[$key])) {
                $targetKey = $key === 'max_completion_tokens' ? 'max_tokens' : $key;
                $options[$targetKey] = $body[$key];
            }
        }

        if (! isset($options['max_tokens'])) {
            $options['max_tokens'] = config('superagent.bridge.max_tokens', 16384);
        }

        return $options;
    }

    /**
     * Convert OpenAI tool definitions into lightweight proxy objects.
     *
     * Returns them as arrays — the inner provider's formatTools() will handle
     * the final conversion to its API-specific format.
     */
    private function parseTools(array $openaiTools): array
    {
        $tools = [];

        foreach ($openaiTools as $toolDef) {
            if (($toolDef['type'] ?? '') !== 'function') {
                continue;
            }

            $fn = $toolDef['function'] ?? [];
            $tools[] = new \SuperAgent\Bridge\BridgeToolProxy(
                $fn['name'] ?? '',
                $fn['description'] ?? '',
                $fn['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
            );
        }

        return $tools;
    }
}
