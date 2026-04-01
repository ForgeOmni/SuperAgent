<?php

namespace SuperAgent\Messages;

use SuperAgent\Enums\Role;
use SuperAgent\Enums\StopReason;

class AssistantMessage extends Message
{
    /** @var ContentBlock[] */
    public array $content = [];

    public ?StopReason $stopReason = null;

    public ?Usage $usage = null;

    public function __construct()
    {
        parent::__construct(Role::Assistant);
    }

    /**
     * Extract the plain text content from this message.
     */
    public function text(): string
    {
        $parts = [];
        foreach ($this->content as $block) {
            if ($block->type === 'text') {
                $parts[] = $block->text;
            }
        }

        return implode('', $parts);
    }

    /**
     * Extract all tool_use blocks from this message.
     *
     * @return ContentBlock[]
     */
    public function toolUseBlocks(): array
    {
        return array_values(array_filter(
            $this->content,
            fn (ContentBlock $b) => $b->type === 'tool_use'
        ));
    }

    public function hasToolUse(): bool
    {
        return count($this->toolUseBlocks()) > 0;
    }

    public function toArray(): array
    {
        return [
            'role' => 'assistant',
            'content' => array_map(fn (ContentBlock $b) => $b->toArray(), $this->content),
        ];
    }
}
