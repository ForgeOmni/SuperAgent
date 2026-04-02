<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModelsController
{
    public function index(Request $request): JsonResponse
    {
        $models = array_keys(config('superagent.bridge.model_aliases', []));

        // Always include the default model
        $defaultModel = config('superagent.bridge.default_model', 'gpt-4o');
        if (! in_array($defaultModel, $models, true)) {
            $models[] = $defaultModel;
        }

        $data = array_map(fn (string $id) => [
            'id' => $id,
            'object' => 'model',
            'created' => 1700000000,
            'owned_by' => 'superagent-bridge',
        ], $models);

        return response()->json([
            'object' => 'list',
            'data' => $data,
        ]);
    }
}
