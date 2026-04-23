<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions\Provider;

use SuperAgent\Exceptions\ProviderException;

/**
 * Provider refused the request on safety / abuse / policy grounds —
 * content moderation, jailbreak detection, or a hard-blocked use
 * case (weapons, CSAM, etc.). Distinct from `InvalidPromptError`
 * (syntactic problem) and `QuotaExceededException` (billing).
 *
 * Not retryable — the policy evaluator will keep rejecting the same
 * prompt. The caller should surface the message to the operator /
 * end-user so they can rephrase or redirect.
 *
 * Maps to codex's `ApiError::CyberPolicy` — a distinct variant
 * because OpenAI's response body for policy errors carries a
 * `reason` field with specific rejection codes the caller might
 * want to inspect.
 */
final class CyberPolicyException extends ProviderException
{
}
