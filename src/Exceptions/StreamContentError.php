<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * The provider streamed a `finish_reason: "error_finish"` chunk — a
 * convention some OpenAI-compatible endpoints (notably DashScope's
 * compatible mode) use to report mid-stream errors like TPM
 * throttling. The error text arrives in the final `delta.content`
 * alongside this finish reason rather than as an HTTP status code,
 * so a naive SSE parser silently returns the truncated content.
 *
 * Throwing this (instead of letting the stream end normally) lets
 * the retry loop in `ChatCompletionsProvider::chat()` treat it like
 * any other retryable 429 / 5xx — same exponential backoff, same
 * final `ProviderException` escalation after `maxRetries`.
 *
 * Extends `ProviderException` so existing callers that catch the
 * base type continue to work.
 */
class StreamContentError extends ProviderException
{
    public function __construct(
        string $provider,
        public readonly string $partialContent,
        public readonly string $errorMessage,
    ) {
        parent::__construct(
            message: "Stream error_finish from {$provider}: {$errorMessage}",
            provider: $provider,
            statusCode: 429, // treat like a throttle so retry loop kicks in
            retryable: true,
        );
    }
}
