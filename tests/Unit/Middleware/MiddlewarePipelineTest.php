<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Middleware\MiddlewareContext;
use SuperAgent\Middleware\MiddlewareInterface;
use SuperAgent\Middleware\MiddlewarePipeline;
use SuperAgent\Middleware\MiddlewareResult;
use SuperAgent\Middleware\Builtin\RetryMiddleware;
use SuperAgent\Middleware\Builtin\LoggingMiddleware;
use SuperAgent\Middleware\Builtin\CostTrackingMiddleware;
use SuperAgent\Middleware\Builtin\GuardrailMiddleware;
use SuperAgent\Exceptions\BudgetExceededException;
use SuperAgent\Exceptions\ValidationException;

class MiddlewarePipelineTest extends TestCase
{
    private function makeResult(string $text = 'ok'): MiddlewareResult
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text($text)];
        return new MiddlewareResult($msg, ['input_tokens' => 10, 'output_tokens' => 5]);
    }

    private function makeContext(): MiddlewareContext
    {
        return new MiddlewareContext(messages: [], provider: 'anthropic');
    }

    public function test_empty_pipeline_calls_handler_directly(): void
    {
        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->execute($this->makeContext(), fn($ctx) => $this->makeResult('direct'));
        $this->assertSame('direct', $result->response->text());
    }

    public function test_middleware_wraps_handler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $order = [];

        $mw = new class($order) implements MiddlewareInterface {
            public function __construct(private array &$order) {}
            public function name(): string { return 'test'; }
            public function priority(): int { return 0; }
            public function handle(MiddlewareContext $ctx, callable $next): MiddlewareResult {
                $this->order[] = 'before';
                $result = $next($ctx);
                $this->order[] = 'after';
                return $result;
            }
        };

        $pipeline->use($mw);
        $pipeline->execute($this->makeContext(), function ($ctx) use (&$order) {
            $order[] = 'handler';
            return $this->makeResult();
        });

        $this->assertSame(['before', 'handler', 'after'], $order);
    }

    public function test_priority_ordering(): void
    {
        $pipeline = new MiddlewarePipeline();
        $order = [];

        foreach ([['low', -10], ['high', 10], ['mid', 0]] as [$name, $prio]) {
            $mw = new class($name, $prio, $order) implements MiddlewareInterface {
                public function __construct(
                    private string $n,
                    private int $p,
                    private array &$order,
                ) {}
                public function name(): string { return $this->n; }
                public function priority(): int { return $this->p; }
                public function handle(MiddlewareContext $ctx, callable $next): MiddlewareResult {
                    $this->order[] = $this->n;
                    return $next($ctx);
                }
            };
            $pipeline->use($mw);
        }

        $pipeline->execute($this->makeContext(), fn($ctx) => $this->makeResult());
        // High priority runs first (outermost)
        $this->assertSame(['high', 'mid', 'low'], $order);
    }

    public function test_remove_middleware(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->use(new LoggingMiddleware());
        $this->assertTrue($pipeline->has('logging'));

        $pipeline->remove('logging');
        $this->assertFalse($pipeline->has('logging'));
    }

    public function test_cost_tracking_tracks_usage(): void
    {
        $cost = new CostTrackingMiddleware(budgetUsd: 100.0); // generous budget
        $pipeline = new MiddlewarePipeline();
        $pipeline->use($cost);

        $ctx = $this->makeContext();
        $ctx->options['model'] = 'claude-sonnet-4-something';
        $pipeline->execute($ctx, fn($ctx) => $this->makeResult());

        $this->assertGreaterThan(0, $cost->getTotalCost());
        $this->assertSame(1, $cost->getTotalRequests());
    }

    public function test_cost_tracking_enforces_budget(): void
    {
        $cost = new CostTrackingMiddleware(budgetUsd: 0.0000001);
        $pipeline = new MiddlewarePipeline();
        $pipeline->use($cost);

        $this->expectException(BudgetExceededException::class);
        $pipeline->execute($this->makeContext(), fn($ctx) => $this->makeResult());
    }

    public function test_guardrail_blocks_invalid_input(): void
    {
        $guardrail = new GuardrailMiddleware();
        $guardrail->addInputValidator(function (MiddlewareContext $ctx) {
            if (empty($ctx->messages)) {
                throw new ValidationException('No messages provided');
            }
        });

        $pipeline = new MiddlewarePipeline();
        $pipeline->use($guardrail);

        $this->expectException(ValidationException::class);
        $pipeline->execute($this->makeContext(), fn($ctx) => $this->makeResult());
    }

    public function test_guardrail_transforms_output(): void
    {
        $guardrail = new GuardrailMiddleware();
        $guardrail->addOutputValidator(function (MiddlewareResult $result, MiddlewareContext $ctx) {
            return $result->withMetadata('validated', true);
        });

        $pipeline = new MiddlewarePipeline();
        $pipeline->use($guardrail);

        $result = $pipeline->execute(
            new MiddlewareContext(messages: [new \SuperAgent\Messages\UserMessage('hi')]),
            fn($ctx) => $this->makeResult(),
        );

        $this->assertTrue($result->metadata['validated']);
    }

    public function test_retry_retries_on_transient_error(): void
    {
        $retry = new RetryMiddleware(maxRetries: 2, baseDelayMs: 10);
        $pipeline = new MiddlewarePipeline();
        $pipeline->use($retry);

        $attempts = 0;
        $result = $pipeline->execute($this->makeContext(), function ($ctx) use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw new \RuntimeException('connection reset', 503);
            }
            return $this->makeResult('recovered');
        });

        $this->assertSame(2, $attempts);
        $this->assertSame('recovered', $result->response->text());
    }

    public function test_context_immutability(): void
    {
        $ctx = new MiddlewareContext(messages: [], options: ['model' => 'a']);
        $ctx2 = $ctx->withOptions(['temperature' => 0.5]);

        $this->assertSame('a', $ctx->options['model']);
        $this->assertSame('a', $ctx2->options['model']);
        $this->assertSame(0.5, $ctx2->options['temperature']);
        $this->assertArrayNotHasKey('temperature', $ctx->options);
    }

    public function test_result_metadata(): void
    {
        $result = $this->makeResult();
        $result2 = $result->withMetadata('key', 'value');

        $this->assertArrayNotHasKey('key', $result->metadata);
        $this->assertSame('value', $result2->metadata['key']);
    }

    public function test_pipeline_count(): void
    {
        $pipeline = new MiddlewarePipeline();
        $this->assertSame(0, $pipeline->count());

        $pipeline->use(new LoggingMiddleware());
        $pipeline->use(new RetryMiddleware());
        $this->assertSame(2, $pipeline->count());
    }
}
