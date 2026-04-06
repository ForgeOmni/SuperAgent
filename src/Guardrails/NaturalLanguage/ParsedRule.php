<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\NaturalLanguage;

final class ParsedRule
{
    public function __construct(
        public readonly string $originalText,
        public readonly string $action,
        public readonly ?string $toolName,
        public readonly array $conditions,
        public readonly string $message,
        public readonly float $confidence,
        public readonly bool $needsReview,
        public readonly ?string $groupName = null,
        public readonly int $priority = 50,
    ) {}

    public function toConditionArray(): array
    {
        return $this->conditions;
    }

    public function toRuleArray(): array
    {
        $rule = [
            'condition' => $this->conditions,
            'action' => $this->action,
            'message' => $this->message,
        ];

        if ($this->toolName !== null) {
            $rule['condition']['tool_name'] = $this->toolName;
        }

        return $rule;
    }

    public function toArray(): array
    {
        return [
            'original_text' => $this->originalText,
            'action' => $this->action,
            'tool_name' => $this->toolName,
            'conditions' => $this->conditions,
            'message' => $this->message,
            'confidence' => $this->confidence,
            'needs_review' => $this->needsReview,
            'group_name' => $this->groupName,
            'priority' => $this->priority,
        ];
    }
}
