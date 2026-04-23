<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions\Provider;

use SuperAgent\Exceptions\ProviderException;

/**
 * Provider-side quota exhausted — monthly usage cap, organisation
 * spend limit, or free-tier allowance consumed. Distinct from a
 * rate-limit 429, which clears on its own after a cooldown.
 *
 * Not retryable. Caller should notify an operator (bump billing,
 * rotate key, swap to a cheaper model) rather than silently retrying.
 *
 * Maps to codex's `ApiError::QuotaExceeded` + OpenAI's
 * `insufficient_quota` code.
 */
final class QuotaExceededException extends ProviderException
{
}
