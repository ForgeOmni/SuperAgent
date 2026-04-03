<?php

declare(strict_types=1);

namespace SuperAgent\SkillDistillation;

/**
 * A single tool call record within an execution trace.
 */
class ToolCallRecord
{
    public function __construct(
        public readonly string $toolName,
        public readonly array $toolInput,
        public readonly string $toolOutput,
        public readonly bool $isError = false,
    ) {}

    /**
     * Summarize the input for display (avoid huge content).
     */
    public function summarizeInput(): string
    {
        return match ($this->toolName) {
            'Bash' => $this->toolInput['command'] ?? '',
            'Read' => $this->toolInput['file_path'] ?? '',
            'Write' => $this->toolInput['file_path'] ?? '',
            'Edit' => $this->toolInput['file_path'] ?? '',
            'Glob' => $this->toolInput['pattern'] ?? '',
            'Grep' => $this->toolInput['pattern'] ?? '',
            default => json_encode(array_keys($this->toolInput)),
        };
    }

    /**
     * Generalize specific file paths into template variables.
     *
     * "/Users/x/project/src/App.php" → "{{target_file}}"
     */
    public function generalizeInput(string $cwd = ''): array
    {
        $generalized = $this->toolInput;

        foreach ($generalized as $key => &$value) {
            if (!is_string($value)) {
                continue;
            }

            // Replace the working directory prefix with {{cwd}}
            if (!empty($cwd) && str_starts_with($value, $cwd)) {
                $value = '{{cwd}}' . substr($value, strlen($cwd));
            }
        }

        return $generalized;
    }

    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'tool_input' => $this->toolInput,
            'tool_output' => substr($this->toolOutput, 0, 500),
            'is_error' => $this->isError,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data['tool_name'] ?? '',
            toolInput: $data['tool_input'] ?? [],
            toolOutput: $data['tool_output'] ?? '',
            isError: (bool) ($data['is_error'] ?? false),
        );
    }
}
