<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class AskUserQuestionTool extends Tool
{
    private static array $questionHistory = [];
    private static $questionHandler = null;

    public function name(): string
    {
        return 'ask_user';
    }

    public function description(): string
    {
        return 'Ask the user a question and wait for their response. Use this when you need clarification or additional information.';
    }

    public function category(): string
    {
        return 'interaction';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The question to ask the user.',
                ],
                'options' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of suggested answers or choices.',
                ],
                'default' => [
                    'type' => 'string',
                    'description' => 'Default answer if user provides no input.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['text', 'yes_no', 'choice', 'number'],
                    'description' => 'Type of expected answer. Default: text.',
                ],
                'validation' => [
                    'type' => 'object',
                    'properties' => [
                        'min_length' => ['type' => 'integer'],
                        'max_length' => ['type' => 'integer'],
                        'pattern' => ['type' => 'string'],
                        'min_value' => ['type' => 'number'],
                        'max_value' => ['type' => 'number'],
                    ],
                    'description' => 'Validation rules for the answer.',
                ],
            ],
            'required' => ['question'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $question = $input['question'] ?? '';
        $options = $input['options'] ?? [];
        $default = $input['default'] ?? null;
        $type = $input['type'] ?? 'text';
        $validation = $input['validation'] ?? [];

        if (empty($question)) {
            return ToolResult::error('Question is required.');
        }

        // Format the question based on type
        $formattedQuestion = $this->formatQuestion($question, $type, $options, $default);

        // Get the answer
        $answer = $this->getAnswer($formattedQuestion, $type, $options, $default);

        // Validate the answer
        $validationResult = $this->validateAnswer($answer, $type, $validation, $options);
        
        if (!$validationResult['valid']) {
            return ToolResult::error($validationResult['message']);
        }

        // Process answer based on type
        $processedAnswer = $this->processAnswer($answer, $type);

        // Store in history
        self::$questionHistory[] = [
            'question' => $question,
            'answer' => $processedAnswer,
            'type' => $type,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        return ToolResult::success([
            'question' => $question,
            'answer' => $processedAnswer,
            'type' => $type,
        ]);
    }

    private function formatQuestion(string $question, string $type, array $options, ?string $default): string
    {
        $formatted = $question;

        switch ($type) {
            case 'yes_no':
                $formatted .= ' (yes/no)';
                if ($default !== null) {
                    $formatted .= " [default: {$default}]";
                }
                break;

            case 'choice':
                if (!empty($options)) {
                    $formatted .= "\nOptions:\n";
                    foreach ($options as $index => $option) {
                        $formatted .= "  " . ($index + 1) . ". {$option}\n";
                    }
                    $formatted .= "Enter your choice (1-" . count($options) . ")";
                    if ($default !== null) {
                        $formatted .= " [default: {$default}]";
                    }
                }
                break;

            case 'number':
                $formatted .= ' (enter a number)';
                if ($default !== null) {
                    $formatted .= " [default: {$default}]";
                }
                break;

            default: // text
                if (!empty($options)) {
                    $formatted .= "\nSuggestions: " . implode(', ', $options);
                }
                if ($default !== null) {
                    $formatted .= " [default: {$default}]";
                }
                break;
        }

        return $formatted;
    }

    private function getAnswer(string $formattedQuestion, string $type, array $options, ?string $default): string
    {
        // If a custom handler is set (for testing or integration), use it
        if (self::$questionHandler !== null) {
            return call_user_func(self::$questionHandler, $formattedQuestion, $type, $options, $default);
        }

        // In a real implementation, this would interact with the user
        // For now, return a simulated answer or default
        if ($default !== null) {
            return $default;
        }

        // Simulate different answer types
        switch ($type) {
            case 'yes_no':
                return 'yes';
            case 'choice':
                return !empty($options) ? '1' : '';
            case 'number':
                return '42';
            default:
                return 'User response';
        }
    }

    private function validateAnswer(string $answer, string $type, array $validation, array $options): array
    {
        // Empty answer check
        if (empty($answer) && $type !== 'text') {
            return ['valid' => false, 'message' => 'Answer cannot be empty.'];
        }

        switch ($type) {
            case 'yes_no':
                $lower = strtolower($answer);
                if (!in_array($lower, ['yes', 'no', 'y', 'n'])) {
                    return ['valid' => false, 'message' => 'Please answer yes or no.'];
                }
                break;

            case 'choice':
                if (!empty($options)) {
                    $choice = (int) $answer;
                    if ($choice < 1 || $choice > count($options)) {
                        return ['valid' => false, 'message' => 'Invalid choice. Please select a number between 1 and ' . count($options) . '.'];
                    }
                }
                break;

            case 'number':
                if (!is_numeric($answer)) {
                    return ['valid' => false, 'message' => 'Please enter a valid number.'];
                }
                $num = (float) $answer;
                
                if (isset($validation['min_value']) && $num < $validation['min_value']) {
                    return ['valid' => false, 'message' => "Number must be at least {$validation['min_value']}."];
                }
                
                if (isset($validation['max_value']) && $num > $validation['max_value']) {
                    return ['valid' => false, 'message' => "Number must be at most {$validation['max_value']}."];
                }
                break;

            case 'text':
            default:
                $len = strlen($answer);
                
                if (isset($validation['min_length']) && $len < $validation['min_length']) {
                    return ['valid' => false, 'message' => "Answer must be at least {$validation['min_length']} characters."];
                }
                
                if (isset($validation['max_length']) && $len > $validation['max_length']) {
                    return ['valid' => false, 'message' => "Answer must be at most {$validation['max_length']} characters."];
                }
                
                if (isset($validation['pattern'])) {
                    if (!preg_match('/' . $validation['pattern'] . '/', $answer)) {
                        return ['valid' => false, 'message' => 'Answer does not match the required pattern.'];
                    }
                }
                break;
        }

        return ['valid' => true];
    }

    private function processAnswer(string $answer, string $type)
    {
        switch ($type) {
            case 'yes_no':
                $lower = strtolower($answer);
                return in_array($lower, ['yes', 'y']);

            case 'number':
                return is_numeric($answer) ? (float) $answer : $answer;

            default:
                return $answer;
        }
    }

    /**
     * Set a custom question handler (for testing or integration).
     */
    public static function setQuestionHandler(?callable $handler): void
    {
        self::$questionHandler = $handler;
    }

    /**
     * Get question history.
     */
    public static function getHistory(): array
    {
        return self::$questionHistory;
    }

    /**
     * Clear question history.
     */
    public static function clearHistory(): void
    {
        self::$questionHistory = [];
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}