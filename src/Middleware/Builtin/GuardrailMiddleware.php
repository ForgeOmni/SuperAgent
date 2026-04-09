<?php

declare(strict_types=1);

namespace SuperAgent\Middleware\Builtin;

use SuperAgent\Middleware\MiddlewareContext;
use SuperAgent\Middleware\MiddlewareInterface;
use SuperAgent\Middleware\MiddlewareResult;
use SuperAgent\Exceptions\ValidationException;

/**
 * Input/output validation guardrails.
 *
 * Runs custom validator callables before the LLM call (input) and
 * after (output). Validators throw ValidationException to block.
 */
class GuardrailMiddleware implements MiddlewareInterface
{
    /** @var array<callable(MiddlewareContext): void> */
    private array $inputValidators = [];

    /** @var array<callable(MiddlewareResult, MiddlewareContext): MiddlewareResult> */
    private array $outputValidators = [];

    public function name(): string
    {
        return 'guardrail';
    }

    public function priority(): int
    {
        return 70;
    }

    /**
     * Add an input validator. Throw ValidationException to block.
     *
     * @param callable(MiddlewareContext): void $validator
     */
    public function addInputValidator(callable $validator): self
    {
        $this->inputValidators[] = $validator;
        return $this;
    }

    /**
     * Add an output validator. Can transform the result or throw.
     *
     * @param callable(MiddlewareResult, MiddlewareContext): MiddlewareResult $validator
     */
    public function addOutputValidator(callable $validator): self
    {
        $this->outputValidators[] = $validator;
        return $this;
    }

    public function handle(MiddlewareContext $context, callable $next): MiddlewareResult
    {
        // Input validation
        foreach ($this->inputValidators as $validator) {
            $validator($context);
        }

        $result = $next($context);

        // Output validation
        foreach ($this->outputValidators as $validator) {
            $result = $validator($result, $context);
        }

        return $result;
    }
}
