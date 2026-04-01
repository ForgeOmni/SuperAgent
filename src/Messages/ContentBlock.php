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
    ) {
    }

    public static function text(string $text): static
    {
        return new static(type: 'text', text: $text);
    }

    public static function toolUse(string $id, string $name, array $input): static
    {
        return new static(type: 'tool_use', toolUseId: $id, toolName: $name, toolInput: $input);
    }

    public static function toolResult(string $toolUseId, string $content, bool $isError = false): static
    {
        return new static(type: 'tool_result', toolUseId: $toolUseId, content: $content, isError: $isError);
    }

    public static function thinking(string $thinking): static
    {
        return new static(type: 'thinking', thinking: $thinking);
    }

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
