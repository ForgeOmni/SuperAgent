<?php

namespace SuperAgent\Messages;

class ContentBlock
{
    public function __construct(
        public readonly string $type,
        // For text blocks
        public readonly ?string $text = null,
        // For tool_use blocks
        public readonly ?string $toolUseId = null,
        public readonly ?string $toolName = null,
        public readonly ?array $toolInput = null,
        // For tool_result blocks
        public readonly ?string $content = null,
        public readonly ?bool $isError = null,
        // For thinking blocks
        public readonly ?string $thinking = null,
        /**
         * Provider-side detail that has to come back unchanged on the next
         * request, keyed by provider: Gemini's `thoughtSignature` is the
         * first — it signs a tool call, and Gemini 3 rejects a replayed call
         * that lost it. Nothing here is ever shown to a user or given to a
         * tool; it is carried, not read.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $meta = null,
    ) {
    }

    public static function text(string $text, ?array $meta = null): static
    {
        return new static(type: 'text', text: $text, meta: $meta);
    }

    public static function toolUse(string $id, string $name, array $input, ?array $meta = null): static
    {
        return new static(type: 'tool_use', toolUseId: $id, toolName: $name, toolInput: $input, meta: $meta);
    }

    public static function toolResult(string $toolUseId, string $content, bool $isError = false): static
    {
        return new static(type: 'tool_result', toolUseId: $toolUseId, content: $content, isError: $isError);
    }

    public static function thinking(string $thinking): static
    {
        return new static(type: 'thinking', thinking: $thinking);
    }

    /** One entry of the carried provider detail, or $default when absent. */
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /** The same block with provider detail attached (blocks are immutable). */
    public function withMeta(array $meta): static
    {
        if ($meta === []) {
            return $this;
        }

        return new static(
            type: $this->type,
            text: $this->text,
            toolUseId: $this->toolUseId,
            toolName: $this->toolName,
            toolInput: $this->toolInput,
            content: $this->content,
            isError: $this->isError,
            thinking: $this->thinking,
            meta: array_merge($this->meta ?? [], $meta),
        );
    }

    /**
     * The block in the SDK's own shape.
     *
     * `meta` is deliberately absent: this is the shape providers put on the
     * wire, and an unknown key is a 400 from more than one of them. Carrying
     * it across a save is MessageSerializer's job.
     */
    public function toArray(): array
    {
        return match ($this->type) {
            'text' => [
                'type' => 'text',
                'text' => $this->text,
            ],
            'tool_use' => [
                'type' => 'tool_use',
                'id' => $this->toolUseId,
                'name' => $this->toolName,
                'input' => empty($this->toolInput) ? (object) [] : $this->toolInput,
            ],
            'tool_result' => array_filter([
                'type' => 'tool_result',
                'tool_use_id' => $this->toolUseId,
                'content' => $this->content,
                'is_error' => $this->isError ?: null,
            ], fn ($v) => $v !== null),
            'thinking' => [
                'type' => 'thinking',
                'thinking' => $this->thinking,
            ],
            default => ['type' => $this->type],
        };
    }
}
