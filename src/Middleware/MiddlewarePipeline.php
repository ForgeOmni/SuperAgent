<?php

declare(strict_types=1);

namespace SuperAgent\Middleware;

/**
 * Composable middleware pipeline using the onion model.
 *
 * Middleware are sorted by priority (descending) and nested so that the
 * highest-priority middleware is the outermost layer. The innermost
 * handler is the actual LLM provider call.
 */
class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middleware = [];
    private bool $sorted = false;

    /**
     * Add middleware to the pipeline.
     */
    public function use(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        $this->sorted = false;
        return $this;
    }

    /**
     * Remove middleware by name.
     */
    public function remove(string $name): self
    {
        $this->middleware = array_values(array_filter(
            $this->middleware,
            fn(MiddlewareInterface $mw) => $mw->name() !== $name,
        ));
        return $this;
    }

    /**
     * Check if middleware exists by name.
     */
    public function has(string $name): bool
    {
        foreach ($this->middleware as $mw) {
            if ($mw->name() === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Execute the pipeline with the given context and inner handler.
     *
     * @param MiddlewareContext $context
     * @param callable $handler  The innermost handler: fn(MiddlewareContext): MiddlewareResult
     * @return MiddlewareResult
     */
    public function execute(MiddlewareContext $context, callable $handler): MiddlewareResult
    {
        $this->ensureSorted();

        $chain = array_reduce(
            array_reverse($this->middleware),
            function (callable $next, MiddlewareInterface $mw): callable {
                return fn(MiddlewareContext $ctx) => $mw->handle($ctx, $next);
            },
            $handler,
        );

        return $chain($context);
    }

    /**
     * Get all registered middleware (sorted by priority).
     *
     * @return MiddlewareInterface[]
     */
    public function all(): array
    {
        $this->ensureSorted();
        return $this->middleware;
    }

    /**
     * Get the number of middleware in the pipeline.
     */
    public function count(): int
    {
        return count($this->middleware);
    }

    private function ensureSorted(): void
    {
        if ($this->sorted) {
            return;
        }
        usort($this->middleware, fn(MiddlewareInterface $a, MiddlewareInterface $b) =>
            $b->priority() <=> $a->priority()
        );
        $this->sorted = true;
    }
}
