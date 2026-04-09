<?php

declare(strict_types=1);

namespace SuperAgent\Middleware\Builtin;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Middleware\MiddlewareContext;
use SuperAgent\Middleware\MiddlewareInterface;
use SuperAgent\Middleware\MiddlewareResult;

/**
 * Structured logging for LLM requests and responses.
 */
class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger();
    }

    public function name(): string
    {
        return 'logging';
    }

    public function priority(): int
    {
        return -100; // runs last (innermost before handler)
    }

    public function handle(MiddlewareContext $context, callable $next): MiddlewareResult
    {
        $start = microtime(true);
        $messageCount = count($context->messages);

        $this->logger->info('llm.request', [
            'provider' => $context->provider,
            'model' => $context->options['model'] ?? 'default',
            'message_count' => $messageCount,
            'tool_count' => count($context->tools),
        ]);

        try {
            $result = $next($context);
            $duration = (int) ((microtime(true) - $start) * 1000);

            $this->logger->info('llm.response', [
                'provider' => $context->provider,
                'duration_ms' => $duration,
                'usage' => $result->usage,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);

            $this->logger->error('llm.error', [
                'provider' => $context->provider,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw $e;
        }
    }
}
