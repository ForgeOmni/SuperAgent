<?php

declare(strict_types=1);

namespace SuperAgent\Security;

/**
 * Uniform verdict returned by every Phase-8 security check.
 *
 * Three outcomes:
 *   - allow  — proceed with the tool call
 *   - ask    — present the user with the `reason`; wait for confirmation
 *   - deny   — refuse the call; surface `reason` as the error
 *
 * `reason` is always populated for `ask` / `deny`. For `allow` it's
 * optional metadata (e.g. "within daily cost limit").
 */
final class SecurityDecision
{
    public function __construct(
        public readonly string $verdict,   // 'allow' | 'ask' | 'deny'
        public readonly string $reason = '',
        public readonly array $context = [],
    ) {}

    public static function allow(string $reason = '', array $context = []): self
    {
        return new self('allow', $reason, $context);
    }

    public static function ask(string $reason, array $context = []): self
    {
        return new self('ask', $reason, $context);
    }

    public static function deny(string $reason, array $context = []): self
    {
        return new self('deny', $reason, $context);
    }

    public function isAllow(): bool  { return $this->verdict === 'allow'; }
    public function isAsk(): bool    { return $this->verdict === 'ask'; }
    public function isDeny(): bool   { return $this->verdict === 'deny'; }
}
