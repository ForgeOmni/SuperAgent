<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Exceptions\Provider;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Exceptions\Provider\ContextWindowExceededException;
use SuperAgent\Exceptions\Provider\CyberPolicyException;
use SuperAgent\Exceptions\Provider\InvalidPromptException;
use SuperAgent\Exceptions\Provider\OpenAIErrorClassifier;
use SuperAgent\Exceptions\Provider\QuotaExceededException;
use SuperAgent\Exceptions\Provider\ServerOverloadedException;
use SuperAgent\Exceptions\Provider\UsageNotIncludedException;

/**
 * Pins each classification branch with a real-shape error body. The
 * classifier must ALWAYS return a ProviderException (never null, never
 * throw), so `instanceof ProviderException` is checked first — the
 * specific-subclass assertion is what locks the routing.
 */
class OpenAIErrorClassifierTest extends TestCase
{
    // ---------------- ContextWindowExceeded ----------------

    public function test_context_length_exceeded_code(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 400,
            body: ['error' => ['code' => 'context_length_exceeded', 'message' => 'too long']],
            message: 'fallback',
            provider: 'openai',
        );
        $this->assertInstanceOf(ContextWindowExceededException::class, $err);
        $this->assertFalse($err->isRetryable());
        $this->assertSame(400, $err->statusCode);
    }

    public function test_context_length_by_message_substring(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 400,
            body: ['error' => ['message' => "This model's maximum context length is 128000 tokens. Please reduce the length of the messages."]],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(ContextWindowExceededException::class, $err);
    }

    // ---------------- Quota ----------------

    public function test_insufficient_quota_code(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 429,
            body: ['error' => ['code' => 'insufficient_quota', 'message' => 'You exceeded your current quota']],
            message: 'fallback',
            provider: 'openai',
        );
        $this->assertInstanceOf(QuotaExceededException::class, $err);
        $this->assertFalse($err->isRetryable(), 'quota is a plan limit, not a transient rate cap');
    }

    public function test_billing_hard_limit_reached(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 402,
            body: ['error' => ['code' => 'billing_hard_limit_reached']],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(QuotaExceededException::class, $err);
    }

    // ---------------- Plan / usage ----------------

    public function test_usage_not_included(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 403,
            body: ['error' => ['code' => 'usage_not_included', 'message' => 'This model is not included in your plan.']],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(UsageNotIncludedException::class, $err);
    }

    public function test_plan_restricted_by_message(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 403,
            body: ['error' => ['message' => 'Please upgrade your plan to access GPT-5']],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(UsageNotIncludedException::class, $err);
    }

    // ---------------- Cyber policy ----------------

    public function test_cyber_policy_code(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 400,
            body: ['error' => ['code' => 'cyber_policy', 'message' => 'refused']],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(CyberPolicyException::class, $err);
    }

    public function test_content_policy_violation(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 400,
            body: ['error' => ['code' => 'content_policy_violation', 'message' => 'against our usage policies']],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(CyberPolicyException::class, $err);
    }

    public function test_safety_system_by_message(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 400,
            body: ['error' => ['message' => 'Our safety system flagged this content.']],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(CyberPolicyException::class, $err);
    }

    // ---------------- Overload ----------------

    public function test_server_overloaded_code(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 503,
            body: ['error' => ['code' => 'server_overloaded', 'message' => 'try again', 'retry_after' => 5]],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(ServerOverloadedException::class, $err);
        $this->assertTrue($err->isRetryable());
        $this->assertSame(5.0, $err->retryAfterSeconds);
    }

    public function test_anthropic_529_overloaded(): void
    {
        // Anthropic returns 529 for capacity pressure; we share the
        // classifier so this path needs to catch it too.
        $err = OpenAIErrorClassifier::classify(
            statusCode: 529,
            body: null,
            message: 'Overloaded',
            provider: 'anthropic',
        );
        $this->assertInstanceOf(ServerOverloadedException::class, $err);
        $this->assertTrue($err->isRetryable());
    }

    // ---------------- Invalid prompt ----------------

    public function test_invalid_request_error_type(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 400,
            body: ['error' => ['type' => 'invalid_request_error', 'message' => 'tool schema missing required field']],
            message: '',
            provider: 'openai',
        );
        $this->assertInstanceOf(InvalidPromptException::class, $err);
    }

    public function test_plain_400_falls_to_invalid_prompt(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 400,
            body: null,
            message: 'Bad Request',
            provider: 'openai',
        );
        $this->assertInstanceOf(InvalidPromptException::class, $err);
    }

    // ---------------- Fallback ----------------

    public function test_unknown_shape_falls_to_base_retryable_on_429(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 429,
            body: null,
            message: 'Too many requests',
            provider: 'openai',
        );
        // 429 without insufficient_quota code is a rate limit, which we
        // currently surface as plain ProviderException (retryable).
        $this->assertInstanceOf(ProviderException::class, $err);
        $this->assertNotInstanceOf(QuotaExceededException::class, $err);
        $this->assertTrue($err->isRetryable());
    }

    public function test_unknown_shape_500_is_retryable_base(): void
    {
        $err = OpenAIErrorClassifier::classify(
            statusCode: 500,
            body: null,
            message: 'Internal server error',
            provider: 'openai',
        );
        $this->assertInstanceOf(ProviderException::class, $err);
        $this->assertTrue($err->isRetryable());
    }

    // ---------------- Backward compat ----------------

    public function test_all_subclasses_extend_provider_exception(): void
    {
        // Catching `ProviderException` must still catch every
        // classified variant — the existing call sites in the agent
        // loop rely on this.
        $variants = [
            ContextWindowExceededException::class,
            QuotaExceededException::class,
            UsageNotIncludedException::class,
            CyberPolicyException::class,
            ServerOverloadedException::class,
            InvalidPromptException::class,
        ];
        foreach ($variants as $cls) {
            $this->assertTrue(
                is_subclass_of($cls, ProviderException::class),
                "{$cls} must extend ProviderException"
            );
        }
    }
}
