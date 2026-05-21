<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\UserMessage;

/**
 * Tier-2 semantic loop detector: a Flash-model probe that asks "is this
 * conversation looping without forward progress?" Complements the hash-based
 * heuristics in {@see LoopDetector}, which catch byte-identical repetition but
 * miss semantic loops (model paraphrases the same plan for ten turns without
 * acting).
 *
 * Lifted from gemini-cli's `LoopDetectionService` (services/loopDetectionService.ts):
 * after `LLM_CHECK_AFTER_TURNS` model turns in a single prompt, every
 * `llmCheckInterval` turns we send the recent history to a cheap classifier
 * model and read back a confidence score. If confidence ≥
 * `LLM_CONFIDENCE_THRESHOLD`, a {@see LoopViolation} with `LoopType::LlmDetected`
 * is returned. The check interval narrows as confidence climbs so suspected
 * loops get caught faster.
 *
 * The classifier prompt is the original gemini-cli prompt verbatim — it was
 * carefully tuned to distinguish "batch operation across many files" (NOT a
 * loop) from "same edit retried with same error" (IS a loop) and porting it
 * unchanged preserves that calibration.
 *
 * Wiring is opt-in. Construct one per prompt and call:
 *
 *     $checker = new LlmLoopChecker($flashProvider);
 *     // ... agent loop ...
 *     foreach ($turns as $i => $turn) {
 *         $v = $checker->turnStarted($i, $conversationHistory);
 *         if ($v !== null) { stop with $v; }
 *     }
 *
 * Stateless across prompts: callers should construct a fresh instance per
 * user prompt (same as {@see LoopDetector::reset()}).
 */
final class LlmLoopChecker
{
    /** Skip checks until at least this many turns have elapsed in the prompt. */
    public const LLM_CHECK_AFTER_TURNS = 30;

    /** Default check cadence (in turns) once eligible. Adjusts dynamically. */
    public const DEFAULT_LLM_CHECK_INTERVAL = 10;
    public const MIN_LLM_CHECK_INTERVAL = 5;
    public const MAX_LLM_CHECK_INTERVAL = 15;

    /** Confidence threshold for considering the classifier's "yes" answer. */
    public const CONFIDENCE_THRESHOLD = 0.9;

    /** How many recent history messages to ship to the classifier. */
    public const HISTORY_WINDOW = 20;

    /** Verbatim port of gemini-cli's LOOP_DETECTION_SYSTEM_PROMPT. */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a diagnostic agent that determines whether a conversational AI assistant is stuck in an unproductive loop. Analyze the conversation history (and, if provided, the original user request) to make this determination.

## What constitutes an unproductive state

An unproductive state requires BOTH of the following to be true:
1. The assistant has exhibited a repetitive pattern over at least 5 consecutive model actions (tool calls or text responses, counting only model-role turns).
2. The repetition produces NO net change or forward progress toward the user's goal.

Specific patterns to look for:
- Alternating cycles with no net effect (e.g., edit → build → edit → build with identical edits and identical errors).
- Semantic repetition with identical outcomes (same tool, equivalent args, same result, repeated).
- Stuck reasoning (multiple consecutive text responses restating the same plan without acting).

## What is NOT an unproductive state

- Cross-file batch operations: same tool name, different file paths.
- Incremental same-file edits: different line ranges or different replacement text.
- Sequential reads/searches gathering information across distinct paths.
- Retry with variation: different arguments or a different approach.

Compare arguments, not just tool names. Different file paths, line ranges, or search terms mean distinct work — not a loop.

If the original user request implies a batch or multi-step operation, repetitive tool calls with varying arguments are expected and weigh heavily against flagging a loop.

Return a JSON object matching the schema. Be conservative: only flag loops you are confident about.
PROMPT;

    /** Response JSON shape we instruct the classifier to emit. */
    private const RESPONSE_INSTRUCTION = <<<'TXT'
Respond with a single JSON object (no prose, no fences) of the shape:
{
  "unproductive_state_analysis": "<your reasoning, 1-3 sentences>",
  "unproductive_state_confidence": <number between 0.0 and 1.0>
}
TXT;

    private LLMProvider $provider;
    private int $checkInterval;
    private int $lastCheckTurn = 0;

    /**
     * @param LLMProvider $provider Flash-tier model recommended (e.g. gemini-3-flash-preview,
     *                              claude-haiku-4-5, gpt-5-nano). The probe is one-shot per call;
     *                              cost is dominated by the history window not the prompt.
     * @param int $checkInterval Initial interval. Narrowed dynamically as confidence rises.
     */
    public function __construct(LLMProvider $provider, int $checkInterval = self::DEFAULT_LLM_CHECK_INTERVAL)
    {
        $this->provider = $provider;
        $this->checkInterval = max(self::MIN_LLM_CHECK_INTERVAL, min(self::MAX_LLM_CHECK_INTERVAL, $checkInterval));
    }

    /**
     * Call at the start of each model turn. Returns a violation when the
     * classifier is confident the conversation is looping; otherwise null.
     *
     * @param int            $turnNumber 1-based count of model turns in this prompt.
     * @param array<Message> $history    Conversation history (UserMessage/AssistantMessage/ToolResultMessage).
     * @param string|null    $userPrompt Original user request for context, optional.
     */
    public function turnStarted(int $turnNumber, array $history, ?string $userPrompt = null): ?LoopViolation
    {
        if ($turnNumber < self::LLM_CHECK_AFTER_TURNS) {
            return null;
        }
        if ($turnNumber - $this->lastCheckTurn < $this->checkInterval) {
            return null;
        }
        $this->lastCheckTurn = $turnNumber;

        $confidence = $this->probe($history, $userPrompt);
        if ($confidence === null) {
            return null;
        }

        // Tighten interval as confidence rises; loosen when calm.
        $this->checkInterval = $this->adjustInterval($confidence);

        if ($confidence >= self::CONFIDENCE_THRESHOLD) {
            return new LoopViolation(
                LoopType::LlmDetected,
                sprintf(
                    'LLM loop check flagged conversation as unproductive (confidence %.2f, model=%s)',
                    $confidence,
                    $this->provider->getModel(),
                ),
                [
                    'confidence' => $confidence,
                    'classifier_model' => $this->provider->getModel(),
                    'turn' => $turnNumber,
                ],
            );
        }

        return null;
    }

    /**
     * Run the classifier probe. Returns confidence in [0,1] or null on parse failure.
     *
     * @param array<Message> $history
     */
    private function probe(array $history, ?string $userPrompt): ?float
    {
        $transcript = $this->formatTranscript(array_slice($history, -self::HISTORY_WINDOW));
        if ($transcript === '') {
            return null;
        }

        $userBody = $userPrompt !== null && $userPrompt !== ''
            ? "Original user request:\n{$userPrompt}\n\n---\n\nRecent transcript:\n{$transcript}\n\n" . self::RESPONSE_INSTRUCTION
            : "Recent transcript:\n{$transcript}\n\n" . self::RESPONSE_INSTRUCTION;

        try {
            $stream = $this->provider->chat(
                messages: [new UserMessage($userBody)],
                tools: [],
                systemPrompt: self::SYSTEM_PROMPT,
                options: ['temperature' => 0.0, 'max_tokens' => 512],
            );

            $text = '';
            foreach ($stream as $msg) {
                if ($msg instanceof AssistantMessage) {
                    $text .= $msg->text();
                }
            }
        } catch (\Throwable $e) {
            // Classifier failure must not crash the host loop; treat as inconclusive.
            return null;
        }

        return $this->extractConfidence($text);
    }

    /**
     * Parse the classifier JSON. Tolerant of surrounding prose / code fences.
     */
    private function extractConfidence(string $text): ?float
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Strip code fences if present.
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $text = $m[1];
        } else {
            // Find the outermost JSON object.
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $text = substr($text, $start, $end - $start + 1);
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            return null;
        }

        $conf = $decoded['unproductive_state_confidence'] ?? null;
        if (! is_numeric($conf)) {
            return null;
        }
        return max(0.0, min(1.0, (float) $conf));
    }

    /**
     * @param array<Message> $history
     */
    private function formatTranscript(array $history): string
    {
        $lines = [];
        foreach ($history as $m) {
            if ($m instanceof UserMessage) {
                $body = is_string($m->content) ? $m->content : json_encode($m->content, JSON_UNESCAPED_UNICODE);
                $lines[] = '[user] ' . $this->truncate((string) $body, 500);
            } elseif ($m instanceof AssistantMessage) {
                $textBody = $m->text();
                if ($textBody !== '') {
                    $lines[] = '[assistant] ' . $this->truncate($textBody, 500);
                }
                foreach ($m->toolUseBlocks() as $block) {
                    $args = is_array($block->toolInput ?? null)
                        ? json_encode($block->toolInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : '{}';
                    $lines[] = sprintf('[tool_call] %s %s', (string) $block->toolName, $this->truncate((string) $args, 400));
                }
            }
        }
        return implode("\n", $lines);
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }

    /**
     * Higher confidence → check more often; lower → check less often.
     * Matches gemini-cli's adjustment logic.
     */
    private function adjustInterval(float $confidence): int
    {
        if ($confidence >= 0.7) {
            return self::MIN_LLM_CHECK_INTERVAL;
        }
        if ($confidence <= 0.3) {
            return self::MAX_LLM_CHECK_INTERVAL;
        }
        return self::DEFAULT_LLM_CHECK_INTERVAL;
    }

    /** Test helper. */
    public function lastCheckTurn(): int
    {
        return $this->lastCheckTurn;
    }

    /** Test helper. */
    public function checkInterval(): int
    {
        return $this->checkInterval;
    }
}
