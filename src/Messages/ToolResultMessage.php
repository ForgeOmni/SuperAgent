<?php

namespace SuperAgent\Messages;

use SuperAgent\Enums\Role;

class ToolResultMessage extends Message
{
    /**
     * @param  ContentBlock[]  $content  The tool result content blocks.
     */
    public function __construct(
        public readonly array $content,
    ) {
        parent::__construct(Role::User);
    }

    /**
     * Create from a single tool result.
     */
    public static function fromResult(string $toolUseId, string|array $resultContent, bool $isError = false): static
    {
        $block = new ContentBlock(
            type: 'tool_result',
            toolUseId: $toolUseId,
            content: is_string($resultContent) ? $resultContent : json_encode($resultContent),
            isError: $isError,
        );

        return new static([$block]);
    }

    /**
     * Create from multiple tool results (parallel tool execution).
     *
     * @param  array<array{tool_use_id: string, content: string|array, is_error?: bool}>  $results
     */
    public static function fromResults(array $results): static
    {
        $blocks = [];
        foreach ($results as $result) {
            $content = $result['content'];
            $blocks[] = new ContentBlock(
                type: 'tool_result',
                toolUseId: $result['tool_use_id'],
                content: is_string($content) ? $content : json_encode($content),
                isError: $result['is_error'] ?? false,
            );
        }

        return new static($blocks);
    }

    public function toArray(): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn (ContentBlock $b) => $b->toArray(), $this->content),
        ];
    }
}
