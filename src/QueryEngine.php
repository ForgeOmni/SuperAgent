<?php

namespace SuperAgent;

use Generator;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Enums\StopReason;
use SuperAgent\Exceptions\SuperAgentException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Tools\ToolResult;

class QueryEngine
{
    /** @var Message[] */
    protected array $messages = [];

    /** @var ToolInterface[] name => tool */
    protected array $toolMap = [];

    protected int $turnCount = 0;

    /** @var string[] tool names that are denied */
    protected array $deniedTools = [];

    /** @var string[]|null tool names that are allowed (null = allow all) */
    protected ?array $allowedTools = null;

    protected float $totalCostUsd = 0.0;

    public function __construct(
        protected readonly LLMProvider $provider,
        protected readonly array $tools = [],
        protected readonly ?string $systemPrompt = null,
        protected readonly int $maxTurns = 50,
        protected readonly array $options = [],
        protected readonly ?StreamingHandler $streamingHandler = null,
        ?array $allowedTools = null,
        array $deniedTools = [],
        protected readonly float $maxBudgetUsd = 0.0,
    ) {
        foreach ($this->tools as $tool) {
            $this->toolMap[$tool->name()] = $tool;
        }
        $this->allowedTools = $allowedTools;
        $this->deniedTools = $deniedTools;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function getTotalCostUsd(): float
    {
        return $this->totalCostUsd;
    }

    /**
     * Run the agentic loop: send prompt, handle tool calls, repeat until done.
     *
     * @return Generator<int, AssistantMessage>
     */
    public function run(string|array $prompt): Generator
    {
        $this->messages[] = new UserMessage($prompt);
        $this->turnCount = 0;

        while ($this->turnCount < $this->maxTurns) {
            $this->turnCount++;

            // Budget check
            if ($this->maxBudgetUsd > 0 && $this->totalCostUsd >= $this->maxBudgetUsd) {
                throw new SuperAgentException(
                    "Budget exhausted: \${$this->totalCostUsd} >= \${$this->maxBudgetUsd}"
                );
            }

            $assistantMessage = $this->callProvider();
            $this->messages[] = $assistantMessage;

            // Track cost
            if ($assistantMessage->usage) {
                $this->totalCostUsd += CostCalculator::calculate(
                    $this->options['model'] ?? $this->provider->getModel(),
                    $assistantMessage->usage,
                );
            }

            $this->streamingHandler?->emitTurn($assistantMessage, $this->turnCount);

            yield $assistantMessage;

            if (! $assistantMessage->hasToolUse() || $assistantMessage->stopReason !== StopReason::ToolUse) {
                $this->streamingHandler?->emitFinalMessage($assistantMessage);
                return;
            }

            $toolResults = $this->executeTools($assistantMessage);
            $this->messages[] = $toolResults;
        }

        throw new SuperAgentException("Agent loop exceeded max turns ({$this->maxTurns})");
    }

    protected function callProvider(): AssistantMessage
    {
        $options = $this->options;
        if ($this->streamingHandler) {
            $options['streaming_handler'] = $this->streamingHandler;
        }

        $generator = $this->provider->chat(
            $this->messages,
            $this->tools,
            $this->systemPrompt,
            $options,
        );

        $lastMessage = null;
        foreach ($generator as $message) {
            $lastMessage = $message;
        }

        if ($lastMessage === null) {
            throw new SuperAgentException('Provider returned no response');
        }

        return $lastMessage;
    }

    protected function executeTools(AssistantMessage $assistantMessage): ToolResultMessage
    {
        $results = [];

        foreach ($assistantMessage->toolUseBlocks() as $block) {
            $toolName = $block->toolName;
            $toolInput = $block->toolInput ?? [];
            $toolUseId = $block->toolUseId;

            // Permission check
            if (! $this->isToolAllowed($toolName)) {
                $content = "Error: Tool '{$toolName}' is not permitted.";
                $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
                continue;
            }

            if (! isset($this->toolMap[$toolName])) {
                $content = "Error: Unknown tool '{$toolName}'";
                $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
                continue;
            }

            try {
                $tool = $this->toolMap[$toolName];
                $result = $tool->execute($toolInput);
                $content = $result->contentAsString();

                $results[] = [
                    'tool_use_id' => $toolUseId,
                    'content' => $content,
                    'is_error' => $result->isError,
                ];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, $result->isError);
            } catch (\Throwable $e) {
                $content = "Error executing tool '{$toolName}': {$e->getMessage()}";
                $results[] = ['tool_use_id' => $toolUseId, 'content' => $content, 'is_error' => true];
                $this->streamingHandler?->emitToolResult($toolUseId, $toolName, $content, true);
            }
        }

        return ToolResultMessage::fromResults($results);
    }

    protected function isToolAllowed(string $toolName): bool
    {
        if (in_array($toolName, $this->deniedTools, true)) {
            return false;
        }

        if ($this->allowedTools !== null && ! in_array($toolName, $this->allowedTools, true)) {
            return false;
        }

        return true;
    }
}
