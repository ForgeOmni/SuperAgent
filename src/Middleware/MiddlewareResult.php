<?php

declare(strict_types=1);

namespace SuperAgent\Middleware;

use SuperAgent\Messages\AssistantMessage;

/**
 * Result returned from the middleware pipeline.
 */
class MiddlewareResult
{
    public function __construct(
        public readonly AssistantMessage $response,
        public readonly array $usage = [],
        public readonly array $metadata = [],
    ) {}

    public function withMetadata(string $key, mixed $value): self
    {
        $metadata = $this->metadata;
        $metadata[$key] = $value;
        return new self($this->response, $this->usage, $metadata);
    }
}
