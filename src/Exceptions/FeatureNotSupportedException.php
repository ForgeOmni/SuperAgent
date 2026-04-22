<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * Thrown when a request's `features` payload asks for a capability the
 * resolved provider cannot satisfy AND the request marked the feature as
 * `required: true`.
 *
 * When `required` is false (the default), the feature should be silently
 * dropped or passed to a fallback adapter instead of raising — this
 * exception is only for the "hard fail" branch of the mixed-invocation
 * design (see `design/NATIVE_PROVIDERS_CN.md` §4.2 – §4.3).
 *
 * Extends `ProviderException` so every `catch (ProviderException $e)` in
 * existing caller code continues to work without modification — a core
 * backward-compat requirement documented in §5.1 of the design.
 */
class FeatureNotSupportedException extends ProviderException
{
    public function __construct(
        public readonly string $feature,
        string $provider,
        ?string $model = null,
        ?\Throwable $previous = null,
    ) {
        $detail = $model !== null ? " (model: {$model})" : '';
        parent::__construct(
            message: "feature '{$feature}' is not supported by this provider{$detail}",
            provider: $provider,
            statusCode: 0,
            responseBody: null,
            retryable: false,
            retryAfterSeconds: null,
            previous: $previous,
        );
    }
}
