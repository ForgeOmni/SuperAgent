<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions\Provider;

use SuperAgent\Exceptions\ProviderException;

/**
 * Provider temporarily cannot accept the request — infrastructure
 * saturation, rolling deploy, regional capacity pressure. Distinct
 * from 429 (per-key rate limit) and 5xx generic (which usually
 * indicates a hard bug on the vendor side).
 *
 * Retryable with exponential backoff. Callers that do their own
 * retry loop should respect {@see self::$retryAfterSeconds} when set.
 *
 * Maps to codex's `ApiError::ServerOverloaded` (recognises OpenAI's
 * `server_overloaded` / Anthropic's 529 signal) and the `overloaded`
 * error codes on other providers.
 */
final class ServerOverloadedException extends ProviderException
{
}
