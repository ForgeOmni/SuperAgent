<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\SelfHealing;

final class Diagnosis
{
    public const CATEGORY_INPUT_ERROR = 'input_error';
    public const CATEGORY_MODEL_LIMITATION = 'model_limitation';
    public const CATEGORY_TOOL_FAILURE = 'tool_failure';
    public const CATEGORY_TIMEOUT = 'timeout';
    public const CATEGORY_RESOURCE_EXHAUSTION = 'resource_exhaustion';
    public const CATEGORY_LOGIC_ERROR = 'logic_error';
    public const CATEGORY_EXTERNAL_DEPENDENCY = 'external_dependency';
    public const CATEGORY_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $rootCause,
        public readonly string $category,
        public readonly float $confidence,
        public readonly array $suggestedFixes,
        public readonly bool $isHealable,
        public readonly string $reasoning,
    ) {}

    public function getBestFix(): ?string
    {
        return $this->suggestedFixes[0] ?? null;
    }

    public function toArray(): array
    {
        return [
            'root_cause' => $this->rootCause,
            'category' => $this->category,
            'confidence' => round($this->confidence, 2),
            'suggested_fixes' => $this->suggestedFixes,
            'is_healable' => $this->isHealable,
            'reasoning' => $this->reasoning,
        ];
    }
}
