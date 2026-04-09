<?php

declare(strict_types=1);

namespace SuperAgent\Middleware;

/**
 * Composable middleware for the agent request/response pipeline.
 *
 * Middleware wraps the LLM call in an onion-model chain, enabling
 * cross-cutting concerns (rate limiting, cost tracking, logging,
 * guardrails, retry) to be composed without modifying core agent logic.
 */
interface MiddlewareInterface
{
    /**
     * Unique middleware name for identification and ordering.
     */
    public function name(): string;

    /**
     * Process the request through this middleware.
     *
     * Call $next($context) to continue the chain, or return early to short-circuit.
     *
     * @param MiddlewareContext $context  Request context (messages, options, metadata)
     * @param callable          $next     Next middleware: fn(MiddlewareContext): MiddlewareResult
     * @return MiddlewareResult
     */
    public function handle(MiddlewareContext $context, callable $next): MiddlewareResult;

    /**
     * Priority determines execution order (higher = runs first / outermost).
     * Default: 0. Rate limiting should be high (100), logging low (-100).
     */
    public function priority(): int;
}
