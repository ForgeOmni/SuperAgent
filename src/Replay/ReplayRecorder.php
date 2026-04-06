<?php

declare(strict_types=1);

namespace SuperAgent\Replay;

final class ReplayRecorder
{
    private ReplayTrace $trace;
    private int $stepCounter = 0;
    private int $snapshotInterval;

    public function __construct(
        string $sessionId,
        int $snapshotInterval = 5,
    ) {
        $this->trace = new ReplayTrace(
            sessionId: $sessionId,
            startedAt: date('c'),
        );
        $this->snapshotInterval = $snapshotInterval;
    }

    public function recordLlmCall(
        string $agentId,
        string $model,
        array $messages,
        string $responseContent,
        array $usage,
        float $durationMs = 0.0,
    ): void {
        $this->trace->addEvent(new ReplayEvent(
            step: ++$this->stepCounter,
            type: ReplayEvent::TYPE_LLM_CALL,
            agentId: $agentId,
            timestamp: date('c'),
            durationMs: $durationMs,
            data: [
                'model' => $model,
                'message_count' => count($messages),
                'response_preview' => mb_substr($responseContent, 0, 500),
                'usage' => $usage,
            ],
        ));

        $this->trace->totalCost += $this->estimateCostFromUsage($usage);
    }

    public function recordToolCall(
        string $agentId,
        string $toolName,
        string $toolId,
        array $input,
        string $output,
        float $durationMs,
        bool $isError = false,
    ): void {
        $this->trace->addEvent(new ReplayEvent(
            step: ++$this->stepCounter,
            type: ReplayEvent::TYPE_TOOL_CALL,
            agentId: $agentId,
            timestamp: date('c'),
            durationMs: $durationMs,
            data: [
                'tool_name' => $toolName,
                'tool_id' => $toolId,
                'input' => $input,
                'output_preview' => mb_substr($output, 0, 1000),
                'output_length' => strlen($output),
                'is_error' => $isError,
            ],
        ));
    }

    public function recordAgentSpawn(
        string $agentId,
        string $parentId,
        string $role,
        array $config = [],
    ): void {
        $this->trace->addEvent(new ReplayEvent(
            step: ++$this->stepCounter,
            type: ReplayEvent::TYPE_AGENT_SPAWN,
            agentId: $agentId,
            timestamp: date('c'),
            durationMs: 0.0,
            data: [
                'parent_id' => $parentId,
                'role' => $role,
                'config' => $config,
            ],
        ));

        $this->trace->agents[$agentId] = [
            'id' => $agentId,
            'parent_id' => $parentId,
            'role' => $role,
            'spawned_at' => date('c'),
            'spawned_at_step' => $this->stepCounter,
        ];
    }

    public function recordAgentMessage(
        string $agentId,
        string $from,
        string $to,
        string $content,
    ): void {
        $this->trace->addEvent(new ReplayEvent(
            step: ++$this->stepCounter,
            type: ReplayEvent::TYPE_AGENT_MESSAGE,
            agentId: $agentId,
            timestamp: date('c'),
            durationMs: 0.0,
            data: [
                'from' => $from,
                'to' => $to,
                'content_preview' => mb_substr($content, 0, 500),
                'content_length' => strlen($content),
            ],
        ));
    }

    public function recordStateSnapshot(
        string $agentId,
        array $messages,
        int $turnCount,
        float $cost,
        array $activeAgents = [],
    ): void {
        $this->trace->addEvent(new ReplayEvent(
            step: ++$this->stepCounter,
            type: ReplayEvent::TYPE_STATE_SNAPSHOT,
            agentId: $agentId,
            timestamp: date('c'),
            durationMs: 0.0,
            data: [
                'message_count' => count($messages),
                'turn_count' => $turnCount,
                'cost' => $cost,
                'active_agents' => $activeAgents,
                'messages_serialized' => $this->serializeMessages($messages),
            ],
        ));

        $this->trace->totalTurns = max($this->trace->totalTurns, $turnCount);
        $this->trace->totalCost = max($this->trace->totalCost, $cost);
    }

    /**
     * Check if a state snapshot should be taken at the given turn.
     */
    public function shouldSnapshot(int $turnCount): bool
    {
        return $this->snapshotInterval > 0 && ($turnCount % $this->snapshotInterval === 0);
    }

    public function getTrace(): ReplayTrace
    {
        return $this->trace;
    }

    public function finalize(): ReplayTrace
    {
        $this->trace->endedAt = date('c');
        return $this->trace;
    }

    public function getCurrentStep(): int
    {
        return $this->stepCounter;
    }

    private function estimateCostFromUsage(array $usage): float
    {
        $inputTokens = $usage['input_tokens'] ?? $usage['inputTokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? $usage['outputTokens'] ?? 0;

        // Rough estimate: $3/M input, $15/M output (Claude Sonnet-range pricing)
        return ($inputTokens * 3.0 / 1_000_000) + ($outputTokens * 15.0 / 1_000_000);
    }

    private function serializeMessages(array $messages): array
    {
        return array_map(function ($msg) {
            if (is_array($msg)) {
                return [
                    'role' => $msg['role'] ?? 'unknown',
                    'content_preview' => mb_substr(
                        is_string($msg['content'] ?? null) ? $msg['content'] : json_encode($msg['content'] ?? ''),
                        0,
                        200,
                    ),
                ];
            }
            if (is_object($msg) && method_exists($msg, 'toArray')) {
                $arr = $msg->toArray();
                return [
                    'role' => $arr['role'] ?? 'unknown',
                    'content_preview' => mb_substr(
                        is_string($arr['content'] ?? null) ? $arr['content'] : json_encode($arr['content'] ?? ''),
                        0,
                        200,
                    ),
                ];
            }
            return ['role' => 'unknown', 'content_preview' => '(unserializable)'];
        }, $messages);
    }
}
