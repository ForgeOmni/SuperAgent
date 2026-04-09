<?php

declare(strict_types=1);

namespace SuperAgent\Middleware;

use SuperAgent\Messages\Message;

/**
 * Context passed through the middleware pipeline.
 */
class MiddlewareContext
{
    private array $metadata = [];

    /**
     * @param Message[] $messages    Conversation messages
     * @param array     $tools       Tool schemas for this request
     * @param string|null $systemPrompt System prompt
     * @param array     $options     Provider options (model, temperature, etc.)
     * @param string    $provider    Provider name
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public ?string $systemPrompt = null,
        public array $options = [],
        public string $provider = 'anthropic',
    ) {}

    public function setMeta(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function getAllMeta(): array
    {
        return $this->metadata;
    }

    /**
     * Create a copy with modified messages (immutable-ish pattern).
     */
    public function withMessages(array $messages): self
    {
        $clone = clone $this;
        $clone->messages = $messages;
        return $clone;
    }

    /**
     * Create a copy with modified options.
     */
    public function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->options = array_merge($clone->options, $options);
        return $clone;
    }
}
