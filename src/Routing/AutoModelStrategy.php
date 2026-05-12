<?php

declare(strict_types=1);

namespace SuperAgent\Routing;

use SuperAgent\Context\TokenEstimator;
use SuperAgent\Evals\ScoreCatalog;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;

/**
 * `/model auto` heuristic — picks DeepSeek V4-Pro vs V4-Flash on each
 * turn based on the actual workload, so callers don't have to think
 * about it.
 *
 * The signal Pro is worth the ~4× price bump for:
 *
 *   1. Long contexts (>= 32K tokens) — Pro's deeper reasoning shines
 *      when the model has to integrate evidence across many turns.
 *   2. Tool-heavy turns — once the agent is in a multi-step tool loop,
 *      Flash often gets confused about what's already been tried;
 *      Pro's working memory is more robust.
 *   3. Explicit max-effort requests — if the caller already asked for
 *      `reasoning_effort=max`, they signaled "spend on this one".
 *   4. Code-shaped intents — system prompts that mention "review",
 *      "audit", "design", "architect", or "plan" benefit from Pro;
 *      "summarize", "extract", "translate" don't.
 *
 * Otherwise we default to Flash. Flash matches the price/quality of
 * the retired `deepseek-chat` and is the right baseline.
 *
 * The strategy is deliberately conservative — false-Pro is more
 * expensive than false-Flash, so we tilt toward Flash unless there's
 * a clear signal to escalate.
 *
 * Usage:
 *
 *   $strategy = new AutoModelStrategy();
 *   $modelId  = $strategy->select($messages, $systemPrompt, $options);
 *   $agent    = new Agent(['provider' => 'deepseek', 'options' => ['model' => $modelId]]);
 *
 * Config knobs (all optional, defaults are sensible):
 *
 *   long_context_threshold_tokens — escalate to Pro at this prompt
 *                                   size or above. Default 32_000.
 *   tool_chain_threshold          — escalate after this many
 *                                   consecutive tool turns. Default 3.
 *   pro_intent_keywords           — system-prompt keywords that
 *                                   trigger Pro escalation.
 */
final class AutoModelStrategy
{
    public const PRO   = 'deepseek-v4-pro';
    public const FLASH = 'deepseek-v4-flash';

    /** @var list<string> */
    private const DEFAULT_PRO_INTENT_KEYWORDS = [
        'review', 'audit', 'design', 'architect', 'plan',
        'debug a complex', 'analyze the codebase', 'find the root cause',
    ];

    /**
     * Map system-prompt intent keywords to eval dimensions. When a system
     * prompt matches one of these, `selectWithScores()` will consult the
     * ScoreCatalog under that dim and prefer the highest-scoring model.
     *
     * Kept deliberately small: false-positive routing to a model with a
     * stale score is worse than just running the default heuristic.
     */
    private const INTENT_TO_DIM = [
        'json'        => 'json_mode',
        'reply with json' => 'json_mode',
        'review'      => 'reasoning',
        'audit'       => 'reasoning',
        'design'      => 'reasoning',
        'architect'   => 'reasoning',
        'plan'        => 'reasoning',
        'write code'  => 'coding',
        'implement'   => 'coding',
        'refactor'    => 'coding',
        'fix the bug' => 'coding',
    ];

    public function __construct(
        private TokenEstimator $tokenEstimator = new TokenEstimator(),
        private int $longContextThresholdTokens = 32_000,
        private int $toolChainThreshold = 3,
        /** @var list<string> */
        private array $proIntentKeywords = self::DEFAULT_PRO_INTENT_KEYWORDS,
        private ?ScoreCatalog $scoreCatalog = null,
    ) {}

    /**
     * Score-aware variant of `select()`. When the eval catalog has data for
     * the inferred intent dimension, return its top-scoring model; otherwise
     * fall back to the original heuristic (`select()`).
     *
     * Callers wire this in by:
     *   $modelId = (new AutoModelStrategy(scoreCatalog: ScoreCatalog::default()))
     *       ->selectWithScores($messages, $systemPrompt, $options);
     *
     * The catalog is consulted only when:
     *   - a catalog was injected,
     *   - we can infer an eval dim from `$options['eval_dim']` or the system
     *     prompt's keywords, and
     *   - that dim has at least one scored model on disk.
     *
     * @param Message[]              $messages
     * @param array<string, mixed>   $options
     */
    public function selectWithScores(array $messages, ?string $systemPrompt = null, array $options = []): string
    {
        if ($this->scoreCatalog !== null) {
            $dim = $this->inferDim($options, $systemPrompt);
            if ($dim !== null) {
                $best = $this->scoreCatalog->bestModelFor($dim);
                if ($best !== null) {
                    return $best;
                }
            }
        }
        return $this->select($messages, $systemPrompt, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function inferDim(array $options, ?string $systemPrompt): ?string
    {
        $explicit = $options['eval_dim'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        if ($systemPrompt === null) {
            return null;
        }
        $lower = strtolower($systemPrompt);
        foreach (self::INTENT_TO_DIM as $kw => $dim) {
            if (strpos($lower, $kw) !== false) {
                return $dim;
            }
        }
        return null;
    }

    /**
     * @param Message[]              $messages    full conversation so far
     * @param string|null            $systemPrompt
     * @param array<string, mixed>   $options     same shape Agent passes
     *                                            to provider->chat()
     * @return string                model id (`deepseek-v4-pro` |
     *                                          `deepseek-v4-flash`)
     */
    public function select(array $messages, ?string $systemPrompt = null, array $options = []): string
    {
        // Signal 3 first — explicit request always wins, no point
        // running the other heuristics if the caller already decided.
        $effort = strtolower(trim((string) ($options['reasoning_effort'] ?? '')));
        if (in_array($effort, ['max', 'xhigh', 'highest'], true)) {
            return self::PRO;
        }

        // Signal 1 — long context.
        if ($this->estimateTokens($messages, $systemPrompt) >= $this->longContextThresholdTokens) {
            return self::PRO;
        }

        // Signal 2 — tool chain depth. Look at the trailing run of
        // assistant messages with tool_use blocks.
        if ($this->trailingToolChainDepth($messages) >= $this->toolChainThreshold) {
            return self::PRO;
        }

        // Signal 4 — intent keywords in system prompt.
        if ($systemPrompt !== null && $this->hasProIntent($systemPrompt)) {
            return self::PRO;
        }

        return self::FLASH;
    }

    /**
     * Public accessor so callers can compute the same depth signal
     * for telemetry without re-walking the message list.
     *
     * @param Message[] $messages
     */
    public function trailingToolChainDepth(array $messages): int
    {
        $depth = 0;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            if ($m instanceof AssistantMessage && $m->hasToolUse()) {
                $depth++;
                continue;
            }
            // Tool result messages are part of the same chain — keep walking.
            if (! $m instanceof AssistantMessage) {
                continue;
            }
            // Plain assistant text breaks the chain.
            break;
        }
        return $depth;
    }

    private function hasProIntent(string $systemPrompt): bool
    {
        $lower = strtolower($systemPrompt);
        foreach ($this->proIntentKeywords as $kw) {
            if (strpos($lower, strtolower($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Approximate the prompt size. Falls back to a character-based
     * estimate when TokenEstimator can't process the message shape.
     *
     * @param Message[] $messages
     */
    private function estimateTokens(array $messages, ?string $systemPrompt): int
    {
        $bytes = strlen($systemPrompt ?? '');
        foreach ($messages as $m) {
            $array = $m->toArray();
            $bytes += strlen(json_encode($array, JSON_UNESCAPED_UNICODE) ?: '');
        }
        // ~4 chars per token, the same rough heuristic codex / TUI use.
        return (int) ($bytes / 4);
    }
}
