<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions\Provider;

use SuperAgent\Exceptions\ProviderException;

/**
 * The prompt (messages + tools + system + reserved output budget)
 * exceeded the model's context window. Distinct from a per-turn
 * token cap (TokenLimitException) — this comes back as a 400 or
 * `response.failed` event from the provider, not a client-side check.
 *
 * Not retryable as-is: the agent loop needs to compact history,
 * swap models, or drop tool schemas before trying again.
 *
 * Maps to codex's `ApiError::ContextWindowExceeded` on the Responses
 * API path, and to the plain `context_length_exceeded` / `string
 * too long` errors OpenAI returns on Chat Completions. Caught by
 * pattern match on the error body (see {@see OpenAIErrorClassifier}).
 */
final class ContextWindowExceededException extends ProviderException
{
}
