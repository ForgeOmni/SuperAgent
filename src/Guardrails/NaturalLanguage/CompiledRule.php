<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\NaturalLanguage;

final class CompiledRule
{
    public function __construct(
        public readonly string $originalText,
        public readonly array $ruleDefinition,
        public readonly string $groupName,
        public readonly int $priority,
        public readonly float $confidence,
        public readonly bool $needsReview,
    ) {}

    public function toArray(): array
    {
        return [
            'original_text' => $this->originalText,
            'rule' => $this->ruleDefinition,
            'group' => $this->groupName,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
            'needs_review' => $this->needsReview,
        ];
    }

    public function toYaml(): string
    {
        $yaml = "# Source: {$this->originalText}\n";
        $yaml .= $this->arrayToYaml($this->ruleDefinition, 0);
        return $yaml;
    }

    private function arrayToYaml(array $data, int $indent): string
    {
        $output = '';
        $prefix = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $output .= "{$prefix}{$key}:\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $output .= "{$prefix}- \n" . $this->arrayToYaml($item, $indent + 2);
                        } else {
                            $output .= "{$prefix}- " . $this->yamlValue($item) . "\n";
                        }
                    }
                } else {
                    $output .= "{$prefix}{$key}:\n" . $this->arrayToYaml($value, $indent + 1);
                }
            } else {
                $output .= "{$prefix}{$key}: " . $this->yamlValue($value) . "\n";
            }
        }

        return $output;
    }

    private function yamlValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/[:#\[\]{}&*!|>\'"%@`]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return (string) $value;
    }
}
