<?php

declare(strict_types=1);

namespace SuperAgent\Bridge;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SuperAgent\Bridge\Http\Controllers\ChatCompletionsController;
use SuperAgent\Bridge\Http\Controllers\ModelsController;
use SuperAgent\Bridge\Http\Controllers\ResponsesController;
use SuperAgent\Bridge\Http\Middleware\BridgeAuth;

class BridgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $prefix = config('superagent.bridge.prefix', '');

        Route::prefix($prefix)
            ->middleware(BridgeAuth::class)
            ->group(function () {
                Route::post('/v1/chat/completions', [ChatCompletionsController::class, 'handle']);
                Route::post('/v1/responses', [ResponsesController::class, 'handle']);
                Route::get('/v1/models', [ModelsController::class, 'index']);
            });
    }
}
