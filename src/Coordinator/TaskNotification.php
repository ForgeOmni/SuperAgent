<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

/**
 * Structured task completion notification for coordinator mode.
 * When a sub-agent completes, the coordinator receives this structured
 * notification instead of raw text, enabling precise result parsing.
 */
class TaskNotification
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $status,       // 'completed', 'failed', 'killed', 'timeout'
        public readonly string $summary,      // Human-readable summary
        public readonly ?string $result = null, // Agent's final text response
        public readonly ?array $usage = null,   // Token usage stats
        public readonly ?float $costUsd = null, // Total cost
        public readonly ?float $durationMs = null,
        public readonly ?string $error = null,  // Error message if failed
        public readonly array $toolsUsed = [],  // List of tools used
        public readonly ?int $turnCount = null,
    ) {}

    /**
     * Render as XML for injection into coordinator conversation.
     */
    public function toXml(): string
    {
        $xml = "<task-notification>\n";
        $xml .= "  <task-id>{$this->escapeXml($this->taskId)}</task-id>\n";
        $xml .= "  <status>{$this->escapeXml($this->status)}</status>\n";
        $xml .= "  <summary>{$this->escapeXml($this->summary)}</summary>\n";

        if ($this->result !== null) {
            $xml .= "  <result>{$this->escapeXml($this->result)}</result>\n";
        }

        if ($this->error !== null) {
            $xml .= "  <error>{$this->escapeXml($this->error)}</error>\n";
        }

        if ($this->usage !== null) {
            $xml .= "  <usage>\n";
            foreach ($this->usage as $key => $value) {
                $xml .= "    <{$key}>{$value}</{$key}>\n";
            }
            $xml .= "  </usage>\n";
        }

        if ($this->costUsd !== null) {
            $xml .= "  <cost_usd>" . number_format($this->costUsd, 6) . "</cost_usd>\n";
        }

        if ($this->durationMs !== null) {
            $xml .= "  <duration_ms>" . round($this->durationMs) . "</duration_ms>\n";
        }

        if (!empty($this->toolsUsed)) {
            $xml .= "  <tools_used>" . implode(', ', $this->toolsUsed) . "</tools_used>\n";
        }

        if ($this->turnCount !== null) {
            $xml .= "  <turn_count>{$this->turnCount}</turn_count>\n";
        }

        $xml .= "</task-notification>";
        return $xml;
    }

    /**
     * Render as compact text for logging.
     */
    public function toText(): string
    {
        $parts = [
            "[{$this->status}] Task {$this->taskId}",
            $this->summary,
        ];
        if ($this->error) {
            $parts[] = "Error: {$this->error}";
        }
        if ($this->costUsd) {
            $parts[] = sprintf('Cost: $%.4f', $this->costUsd);
        }
        if ($this->turnCount) {
            $parts[] = "Turns: {$this->turnCount}";
        }
        return implode(' | ', $parts);
    }

    /**
     * Parse from XML string.
     */
    public static function fromXml(string $xml): ?self
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return null;
        }

        $usage = null;
        if (isset($doc->usage)) {
            $usage = [];
            foreach ($doc->usage->children() as $key => $value) {
                $usage[$key] = (int)(string)$value;
            }
        }

        return new self(
            taskId: (string)($doc->{'task-id'} ?? ''),
            status: (string)($doc->status ?? 'unknown'),
            summary: (string)($doc->summary ?? ''),
            result: isset($doc->result) ? (string)$doc->result : null,
            usage: $usage,
            costUsd: isset($doc->cost_usd) ? (float)(string)$doc->cost_usd : null,
            durationMs: isset($doc->duration_ms) ? (float)(string)$doc->duration_ms : null,
            error: isset($doc->error) ? (string)$doc->error : null,
            toolsUsed: isset($doc->tools_used) ? explode(', ', (string)$doc->tools_used) : [],
            turnCount: isset($doc->turn_count) ? (int)(string)$doc->turn_count : null,
        );
    }

    /**
     * Create from an AgentSpawnResult (or similar result object).
     */
    public static function fromResult(string $taskId, string $status, array $resultData): self
    {
        return new self(
            taskId: $taskId,
            status: $status,
            summary: $resultData['summary'] ?? ($resultData['result'] ?? 'No summary'),
            result: $resultData['result'] ?? null,
            usage: $resultData['usage'] ?? null,
            costUsd: $resultData['cost_usd'] ?? null,
            durationMs: $resultData['duration_ms'] ?? null,
            error: $resultData['error'] ?? null,
            toolsUsed: $resultData['tools_used'] ?? [],
            turnCount: $resultData['turn_count'] ?? null,
        );
    }

    private function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
