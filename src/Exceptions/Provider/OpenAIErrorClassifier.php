<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions\Provider;

use SuperAgent\Exceptions\ProviderException;

/**
 * Dispatch an HTTP status + response body into the right
 * {@see ProviderException} subclass. Backward-compatible — unknown
 * shapes fall through to plain `ProviderException`, which is what
 * every existing `catch (ProviderException)` in the codebase expects.
 *
 * Called from {@see ChatCompletionsProvider::chat()} after decoding
 * an HTTP error body, and from the future Responses API provider on
 * `response.failed` SSE events. The two paths share the same body
 * shape (`{"error": {"code", "message", "type"}}`), so one matcher
 * suffices.
 *
 * Detection is pattern-based rather than code-based: OpenAI's error
 * codes are not stable across endpoints (Responses uses different
 * strings than Chat Completions, Azure rebinds some codes). The
 * canonical signals we look at:
 *
 *   - `error.code`    — e.g. `context_length_exceeded`, `insufficient_quota`
 *   - `error.type`    — e.g. `invalid_request_error`, `server_error`
 *   - `error.message` — fallback, loose substring match
 *   - HTTP status     — last-resort bucketing for 429 / 5xx / 400
 *
 * Lifted from codex's per-category matchers in
 * `codex-api/src/sse/responses.rs` — adapted to the synchronous
 * Guzzle-exception path SuperAgent uses.
 */
final class OpenAIErrorClassifier
{
    /**
     * Classify into a specific subclass of {@see ProviderException}.
     *
     * @param  array<string, mixed>|null $body    Decoded error body, if any.
     * @param  string                    $message Fallback message when the body is empty.
     * @return ProviderException  — always a subclass instance; never null.
     */
    public static function classify(
        int $statusCode,
        ?array $body,
        string $message,
        string $provider,
        ?\Throwable $previous = null,
    ): ProviderException {
        $errorNode = is_array($body) && isset($body['error']) && is_array($body['error'])
            ? $body['error']
            : null;

        $code = $errorNode['code'] ?? null;
        $type = $errorNode['type'] ?? null;
        $msg  = $errorNode['message'] ?? $message;

        $retryAfter = self::readRetryAfter($errorNode);

        // ── Context window ────────────────────────────────────────
        if (
            $code === 'context_length_exceeded'
            || $code === 'string_above_max_length'
            || self::msgContainsAny($msg, [
                'context_length_exceeded',
                'maximum context length',
                'reduce the length',
            ])
        ) {
            return new ContextWindowExceededException(
                message: $msg,
                provider: $provider,
                statusCode: $statusCode,
                responseBody: $body,
                retryable: false,
                previous: $previous,
            );
        }

        // ── Quota ────────────────────────────────────────────────
        if (
            $code === 'insufficient_quota'
            || $code === 'billing_hard_limit_reached'
            || self::msgContainsAny($msg, ['exceeded your current quota', 'insufficient_quota'])
        ) {
            return new QuotaExceededException(
                message: $msg,
                provider: $provider,
                statusCode: $statusCode,
                responseBody: $body,
                retryable: false,
                previous: $previous,
            );
        }

        // ── Plan / usage not included ────────────────────────────
        if (
            $code === 'usage_not_included'
            || $code === 'plan_restricted'
            || self::msgContainsAny($msg, ['not included in your plan', 'upgrade your plan'])
        ) {
            return new UsageNotIncludedException(
                message: $msg,
                provider: $provider,
                statusCode: $statusCode,
                responseBody: $body,
                retryable: false,
                previous: $previous,
            );
        }

        // ── Cyber / safety / policy ──────────────────────────────
        if (
            $code === 'cyber_policy'
            || $code === 'content_policy_violation'
            || $code === 'safety'
            || $type === 'cyber_policy_error'
            || self::msgContainsAny($msg, [
                'policy', 'safety system', 'cyber policy',
                'violates', 'against our usage policies',
            ])
        ) {
            return new CyberPolicyException(
                message: $msg,
                provider: $provider,
                statusCode: $statusCode,
                responseBody: $body,
                retryable: false,
                previous: $previous,
            );
        }

        // ── Overload / capacity ──────────────────────────────────
        if (
            $code === 'server_overloaded'
            || $code === 'overloaded'
            || $code === 'engine_overloaded'
            || $statusCode === 529
            || self::msgContainsAny($msg, ['overloaded', 'temporarily unavailable'])
        ) {
            return new ServerOverloadedException(
                message: $msg,
                provider: $provider,
                statusCode: $statusCode,
                responseBody: $body,
                retryable: true,
                retryAfterSeconds: $retryAfter,
                previous: $previous,
            );
        }

        // ── Invalid prompt / body ────────────────────────────────
        if (
            $type === 'invalid_request_error'
            || $statusCode === 400
        ) {
            return new InvalidPromptException(
                message: $msg,
                provider: $provider,
                statusCode: $statusCode,
                responseBody: $body,
                retryable: false,
                previous: $previous,
            );
        }

        // ── Fallback: retryable by status ────────────────────────
        $retryable = in_array($statusCode, [429, 500, 502, 503], true);
        return new ProviderException(
            message: $msg,
            provider: $provider,
            statusCode: $statusCode,
            responseBody: $body,
            retryable: $retryable,
            retryAfterSeconds: $retryable ? $retryAfter : null,
            previous: $previous,
        );
    }

    /**
     * @param array<string, mixed>|null $errorNode
     */
    private static function readRetryAfter(?array $errorNode): ?float
    {
        if (! is_array($errorNode)) return null;
        // Some providers surface Retry-After inside the error body
        // (OpenAI response.failed for 429s carries `retry_after` seconds
        // when the client doesn't set the header). Tolerate both shapes.
        foreach (['retry_after', 'retryAfter', 'retry_after_seconds'] as $k) {
            if (isset($errorNode[$k]) && is_numeric($errorNode[$k])) {
                return (float) $errorNode[$k];
            }
        }
        return null;
    }

    private static function msgContainsAny(mixed $msg, array $needles): bool
    {
        if (! is_string($msg) || $msg === '') return false;
        $lower = strtolower($msg);
        foreach ($needles as $n) {
            if (str_contains($lower, strtolower($n))) return true;
        }
        return false;
    }
}
