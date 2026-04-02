<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SuperAgent\Bridge\BridgeFactory;
use SuperAgent\Bridge\BridgeToolProxy;
use SuperAgent\Bridge\Converters\ResponsesApiAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponsesController
{
    public function handle(Request $request): JsonResponse|StreamedResponse
    {
        $body = $request->all();
        $streaming = $body['stream'] ?? false;

        // Parse Responses API input into internal format
        $parsed = ResponsesApiAdapter::fromResponsesApi($body);
        $messages = $parsed['messages'];
        $systemPrompt = $parsed['systemPrompt'];

        // Resolve model
        $requestedModel = $body['model'] ?? config('superagent.bridge.default_model', 'gpt-4o');
        $options = $this->buildOptions($body, $requestedModel);

        // Build enhanced provider
        $provider = BridgeFactory::createProvider($requestedModel);

        // Parse tools
        $tools = $this->parseTools($parsed['tools']);

        try {
            $generator = $provider->chat($messages, $tools, $systemPrompt, $options);
            $responseId = 'resp_' . bin2hex(random_bytes(12));

            if ($streaming) {
                return $this->streamResponse($generator, $requestedModel, $responseId);
            }

            return $this->jsonResponse($generator, $requestedModel, $responseId);
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

    private function streamResponse(\Generator $generator, string $model, string $responseId): StreamedResponse
    {
        return new StreamedResponse(function () use ($generator, $model, $responseId) {
            foreach ($generator as $assistantMessage) {
                $events = ResponsesApiAdapter::toStreamEvents($assistantMessage, $model, $responseId);
                foreach ($events as $event) {
                    echo $event;
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

    private function jsonResponse(\Generator $generator, string $model, string $responseId): JsonResponse
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

        return response()->json(
            ResponsesApiAdapter::toResponsesApi($lastMessage, $model, $responseId)
        );
    }

    private function buildOptions(array $body, string $model): array
    {
        $modelMap = config('superagent.bridge.model_map', []);
        $options = [
            'model' => $modelMap[$model] ?? $model,
            'max_tokens' => $body['max_output_tokens'] ?? config('superagent.bridge.max_tokens', 16384),
        ];

        if (isset($body['temperature'])) {
            $options['temperature'] = $body['temperature'];
        }

        return $options;
    }

    private function parseTools(array $toolDefs): array
    {
        $tools = [];

        foreach ($toolDefs as $toolDef) {
            $type = $toolDef['type'] ?? '';

            if ($type === 'function') {
                $tools[] = new BridgeToolProxy(
                    $toolDef['name'] ?? '',
                    $toolDef['description'] ?? '',
                    $toolDef['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
                );
            }
        }

        return $tools;
    }
}
