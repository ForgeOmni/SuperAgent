<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions\Provider;

use SuperAgent\Exceptions\ProviderException;

/**
 * ChatGPT-OAuth / subscription-backed accounts whose plan doesn't
 * include this model or this endpoint. Distinct from a quota
 * (quota could clear at month rollover; this is a plan limitation).
 *
 * Not retryable. Caller should swap to an API-key auth mode, pick
 * a different model, or upgrade the plan.
 *
 * Maps to codex's `ApiError::UsageNotIncluded` — fires specifically
 * when a Pro account asks for GPT-5 or when a Plus account asks for
 * tools not covered by the subscription.
 */
final class UsageNotIncludedException extends ProviderException
{
}
