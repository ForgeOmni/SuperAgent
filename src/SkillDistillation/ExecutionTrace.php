<?php

declare(strict_types=1);

namespace SuperAgent\SkillDistillation;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Captures the execution trace of a successful agent run for distillation.
 *
 * Extracts the ordered sequence of tool calls, their inputs/outputs,
 * the original prompt, model used, cost, and token usage. This is the
 * raw material from which a reusable skill template is distilled.
 */
class ExecutionTrace
{
    /**
     * @param string $originalPrompt The user's original prompt
     * @param string $model Model that executed the task
     * @param ToolCallRecord[] $toolCalls Ordered tool call sequence
     * @param string $finalOutput The agent's final text response
     * @param float $costUsd Total cost of the execution
     * @param int $inputTokens Total input tokens
     * @param int $outputTokens Total output tokens
     * @param int $turns Number of LLM turns
     * @param string $createdAt ISO 8601 timestamp
     */
    public function __construct(
        public readonly string $originalPrompt,
        public readonly string $model,
        public readonly array $toolCalls,
        public readonly string $finalOutput,
        public readonly float $costUsd,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $turns,
        public readonly string $createdAt,
    ) {}

    /**
     * Build an ExecutionTrace from an AgentResult's message history.
     *
     * @param string $prompt The original user prompt
     * @param Message[] $messages Full conversation history
     * @param string $model Model identifier
     * @param float $costUsd Total cost
     * @param int $inputTokens Total input tokens
     * @param int $outputTokens Total output tokens
     * @param int $turns Number of turns
     */
    public static function fromMessages(
        string $prompt,
        array $messages,
        string $model,
        float $costUsd = 0.0,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $turns = 0,
    ): self {
        $toolCalls = [];
        $finalOutput = '';

        // Build a map of tool_use_id → tool result content
        $toolResults = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->content as $block) {
                    if (isset($block['tool_use_id'])) {
                        $toolResults[$block['tool_use_id']] = [
                            'content' => $block['content'] ?? '',
                            'is_error' => $block['is_error'] ?? false,
                        ];
                    }
                }
            }
        }

        // Extract tool calls from assistant messages
        foreach ($messages as $message) {
            if (!($message instanceof AssistantMessage)) {
                continue;
            }

            foreach ($message->content as $block) {
                if ($block->type === 'tool_use') {
                    $result = $toolResults[$block->toolUseId] ?? null;
                    $toolCalls[] = new ToolCallRecord(
                        toolName: $block->toolName,
                        toolInput: $block->toolInput ?? [],
                        toolOutput: $result['content'] ?? '',
                        isError: $result['is_error'] ?? false,
                    );
                }
            }

            // Capture the final text output (last assistant message with text)
            $text = $message->text();
            if (!empty($text) && !$message->hasToolUse()) {
                $finalOutput = $text;
            }
        }

        return new self(
            originalPrompt: $prompt,
            model: $model,
            toolCalls: $toolCalls,
            finalOutput: $finalOutput,
            costUsd: $costUsd,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            turns: $turns,
            createdAt: date('c'),
        );
    }

    /**
     * Get the unique tool names used in this trace.
     *
     * @return string[]
     */
    public function getUsedTools(): array
    {
        return array_values(array_unique(
            array_map(fn (ToolCallRecord $tc) => $tc->toolName, $this->toolCalls),
        ));
    }

    /**
     * Get the tool call sequence as a simplified summary.
     *
     * @return array{tool: string, input_summary: string}[]
     */
    public function getToolSequenceSummary(): array
    {
        return array_map(fn (ToolCallRecord $tc) => [
            'tool' => $tc->toolName,
            'input_summary' => $tc->summarizeInput(),
        ], $this->toolCalls);
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        return [
            'original_prompt' => $this->originalPrompt,
            'model' => $this->model,
            'tool_calls' => array_map(fn (ToolCallRecord $tc) => $tc->toArray(), $this->toolCalls),
            'final_output' => $this->finalOutput,
            'cost_usd' => $this->costUsd,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'turns' => $this->turns,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Deserialize from array.
     */
    public static function fromArray(array $data): self
    {
        $toolCalls = array_map(
            fn (array $tc) => ToolCallRecord::fromArray($tc),
            $data['tool_calls'] ?? [],
        );

        return new self(
            originalPrompt: $data['original_prompt'] ?? '',
            model: $data['model'] ?? '',
            toolCalls: $toolCalls,
            finalOutput: $data['final_output'] ?? '',
            costUsd: (float) ($data['cost_usd'] ?? 0.0),
            inputTokens: (int) ($data['input_tokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? 0),
            turns: (int) ($data['turns'] ?? 0),
            createdAt: $data['created_at'] ?? date('c'),
        );
    }
}
