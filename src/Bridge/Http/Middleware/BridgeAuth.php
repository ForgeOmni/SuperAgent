<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BridgeAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKeys = config('superagent.bridge.api_keys', []);

        // Skip auth if no keys configured (development mode)
        if (empty($apiKeys)) {
            return $next($request);
        }

        $token = $request->bearerToken();

        if ($token === null || ! in_array($token, $apiKeys, true)) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid API key.',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

        return $next($request);
    }
}
