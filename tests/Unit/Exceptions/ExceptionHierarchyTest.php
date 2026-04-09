<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\AgentException;
use SuperAgent\Exceptions\BudgetExceededException;
use SuperAgent\Exceptions\ContextOverflowException;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Exceptions\RateLimitException;
use SuperAgent\Exceptions\RecoverableException;
use SuperAgent\Exceptions\SuperAgentException;
use SuperAgent\Exceptions\ToolException;
use SuperAgent\Exceptions\ValidationException;

class ExceptionHierarchyTest extends TestCase
{
    public function test_hierarchy_inheritance(): void
    {
        $this->assertInstanceOf(SuperAgentException::class, new AgentException());
        $this->assertInstanceOf(SuperAgentException::class, new ProviderException('err', 'anthropic'));
        $this->assertInstanceOf(SuperAgentException::class, new ToolException('err', 'bash'));
        $this->assertInstanceOf(SuperAgentException::class, new BudgetExceededException(1.0, 0.5));
        $this->assertInstanceOf(SuperAgentException::class, new ContextOverflowException(200000, 100000));
        $this->assertInstanceOf(SuperAgentException::class, new ValidationException('bad'));

        // RateLimitException is in RecoverableException hierarchy (separate from SuperAgentException)
        $this->assertInstanceOf(RecoverableException::class, new RateLimitException());
    }

    public function test_provider_exception_retryable(): void
    {
        $retryable = new ProviderException('err', 'anthropic', retryable: true);
        $fatal = new ProviderException('err', 'anthropic', retryable: false);

        $this->assertTrue($retryable->isRetryable());
        $this->assertFalse($fatal->isRetryable());
    }

    public function test_provider_from_http_status(): void
    {
        $e429 = ProviderException::fromHttpStatus(429, 'Rate limited', 'openai');
        $this->assertTrue($e429->isRetryable());
        $this->assertSame(429, $e429->statusCode);
        $this->assertSame(60.0, $e429->retryAfterSeconds);

        $e401 = ProviderException::fromHttpStatus(401, 'Unauthorized', 'anthropic');
        $this->assertFalse($e401->isRetryable());
    }

    public function test_rate_limit_exception(): void
    {
        $e = new RateLimitException('too fast');
        $e->setRetryAfter(30);
        $this->assertTrue($e->canRetry());
        $this->assertSame(30, $e->getRetryAfter());
    }

    public function test_budget_exceeded_message(): void
    {
        $e = new BudgetExceededException(spent: 5.50, budget: 5.00);
        $this->assertStringContainsString('5.5000', $e->getMessage());
        $this->assertStringContainsString('5.0000', $e->getMessage());
        $this->assertSame(5.50, $e->spent);
        $this->assertSame(5.00, $e->budget);
    }

    public function test_context_overflow_retryable(): void
    {
        $e = new ContextOverflowException(200000, 100000);
        $this->assertTrue($e->isRetryable());
        $this->assertSame(200000, $e->tokens);
        $this->assertSame(100000, $e->maxTokens);
    }

    public function test_tool_exception_carries_input(): void
    {
        $e = new ToolException('failed', 'bash', ['command' => 'ls']);
        $this->assertSame('bash', $e->toolName);
        $this->assertSame(['command' => 'ls'], $e->toolInput);
    }

    public function test_validation_exception_violations(): void
    {
        $violations = ['field_a' => 'required', 'field_b' => 'invalid'];
        $e = new ValidationException('Invalid input', $violations);
        $this->assertSame($violations, $e->violations);
    }

    public function test_to_array_serialization(): void
    {
        $e = new ProviderException('fail', 'anthropic', statusCode: 500, retryable: true);
        $arr = $e->toArray();

        $this->assertSame(ProviderException::class, $arr['type']);
        $this->assertStringContainsString('fail', $arr['message']);
        $this->assertTrue($arr['retryable']);
        $this->assertSame('anthropic', $arr['provider']);
        $this->assertSame(500, $arr['status_code']);
    }

    public function test_context_data(): void
    {
        $e = new SuperAgentException('err', context: ['key' => 'val']);
        $this->assertSame(['key' => 'val'], $e->context);
        $this->assertSame('val', $e->toArray()['context']['key']);
    }
}
