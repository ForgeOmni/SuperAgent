<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use Generator;
use SuperAgent\Conversation\Encoder\OpenAIChatEncoder;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Providers\Capabilities\SupportsReasoningEffort;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * DeepSeek — V4 family (deepseek-v4-pro / deepseek-v4-flash) and the
 * legacy V3 / R1 ids that retire 2026-07-24.
 *
 * Wire format is OpenAI-compatible at `/v1/chat/completions`. The same
 * endpoint exposes an Anthropic-compatible mode at `/anthropic/...` —
 * callers who need that route configure `provider=anthropic` with
 * `base_url=https://api.deepseek.com/anthropic` instead; this provider
 * sticks to the OpenAI shape.
 *
 * Multi-upstream: the same DeepSeek V4 weights are hosted on a handful
 * of inference vendors that relay an OpenAI-compat endpoint. Set
 * `upstream` to one of:
 *
 *   deepseek (default) — api.deepseek.com
 *   beta               — api.deepseek.com/beta (FIM / prefix completions)
 *   nvidia_nim         — integrate.api.nvidia.com/v1
 *   fireworks          — api.fireworks.ai/inference/v1
 *   novita             — api.novita.ai/v3/openai
 *   openrouter         — openrouter.ai/api/v1
 *   sglang             — caller-supplied `base_url` (self-hosted)
 *
 * The `region` key is preserved as an alias for `upstream` for
 * backward compatibility (default / beta / cn → DeepSeek-native).
 *
 * V4 specifics surfaced here:
 *   - Thinking / Non-Thinking are a single-model toggle. We enable it
 *     via `thinking: {type: enabled}` (the same shape Anthropic +
 *     GLM use); DeepSeek's server tolerates the field on V4 and ignores
 *     it on V3 / R1 (R1 is always-thinking; V3 is never-thinking).
 *   - **Reasoning-content replay (V4 §5.1.1 Interleaved Thinking)** —
 *     in V4 thinking mode, every assistant message that carries
 *     `tool_calls` MUST include the `reasoning_content` field on the
 *     wire when replayed in a later request, or the API rejects the
 *     payload with HTTP 400. We preserve the `thinking` ContentBlock
 *     on each AssistantMessage and re-emit it as `reasoning_content`,
 *     and as a final safety net we run a sanitizer that forces a
 *     `(reasoning omitted)` placeholder on any assistant+tool_calls
 *     that slipped through without one (e.g. sessions restored from
 *     disk before this code shipped, sub-agents adding messages by
 *     hand, etc.). Same final-pass sanitizer pattern DeepSeek-TUI
 *     uses to bullet-proof the request.
 *   - Reasoning chain arrives as `delta.reasoning_content` — handled in
 *     `ChatCompletionsProvider::parseSSEStream()` shared logic, which
 *     emits a separate `thinking` ContentBlock at end-of-stream.
 *   - Context cache: automatic, per-account, no opt-in field. Cache
 *     reads come back as `prompt_tokens_details.cached_tokens` (the
 *     standard OpenAI-style shape) — base parser already plumbs that
 *     into `Usage::cacheReadInputTokens`, and `CostCalculator` applies
 *     the 1/10 read price.
 *   - Beta endpoint (`https://api.deepseek.com/beta`) exposes FIM /
 *     prefix completions for code use cases; the dedicated
 *     {@see DeepSeekProvider::completeFim()} helper hits it.
 */
class DeepSeekProvider extends ChatCompletionsProvider implements SupportsThinking, SupportsReasoningEffort
{
    /** @var array<string, string> upstream id → base URL */
    private const UPSTREAM_MAP = [
        'deepseek'   => 'https://api.deepseek.com',
        'default'    => 'https://api.deepseek.com',
        'cn'         => 'https://api.deepseek.com',
        'beta'       => 'https://api.deepseek.com/beta',
        'nvidia_nim' => 'https://integrate.api.nvidia.com/v1',
        'fireworks'  => 'https://api.fireworks.ai/inference/v1',
        'novita'     => 'https://api.novita.ai/v3/openai',
        'openrouter' => 'https://openrouter.ai/api/v1',
    ];

    /**
     * Track the resolved upstream so reasoning-effort shape and
     * sanitizer behavior can pivot on it. Set in regionToBaseUrl()
     * (which the parent constructor calls before our own ctor body
     * runs, so we resolve it lazily on first use).
     */
    private string $upstream = 'deepseek';

    /**
     * Accepts `upstream` as a peer of `region`. When both are set,
     * `region` wins (mirrors the parent's precedence: an explicit
     * `region` is treated as the most specific config). When only
     * `upstream` is provided, copy it into `region` so the parent
     * constructor can resolve the base URL without further changes.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        if (! isset($config['region']) && isset($config['upstream']) && is_string($config['upstream'])) {
            $config['region'] = $config['upstream'];
        }
        parent::__construct($config);
    }

    /**
     * V4 wires thinking through the same `thinking: {type: enabled}` field
     * Anthropic and GLM use. DeepSeek's docs don't expose an explicit token
     * budget yet — the server controls it server-side based on the model
     * tier (V4-Pro thinks more aggressively than V4-Flash). Budget is
     * advisory; we still pass `enabled` so the model emits its
     * reasoning_content channel for callers that want it.
     */
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        return ['thinking' => ['type' => 'enabled']];
    }

    /**
     * Three tiers, normalised:
     *
     *   off  → disable thinking outright
     *   high → standard thinking budget
     *   max  → V4-Pro "think harder"; expensive, deepest CoT
     *
     * NVIDIA NIM nests its switches under `chat_template_kwargs`;
     * everyone else accepts the top-level `reasoning_effort` +
     * `thinking` pair. Unknown values return [] so a misconfigured
     * caller doesn't poison the request.
     */
    public function reasoningEffortFragment(string $effort): array
    {
        $normalised = strtolower(trim($effort));
        $isNim = $this->resolvedUpstream() === 'nvidia_nim';

        return match ($normalised) {
            'off', 'disabled', 'none', 'false' => $isNim
                ? ['chat_template_kwargs' => ['thinking' => false]]
                : ['thinking' => ['type' => 'disabled']],
            'low', 'minimal', 'medium', 'mid', 'high', '' => $isNim
                ? ['chat_template_kwargs' => ['thinking' => true, 'reasoning_effort' => 'high']]
                : ['reasoning_effort' => 'high', 'thinking' => ['type' => 'enabled']],
            'max', 'xhigh', 'highest' => $isNim
                ? ['chat_template_kwargs' => ['thinking' => true, 'reasoning_effort' => 'max']]
                : ['reasoning_effort' => 'max', 'thinking' => ['type' => 'enabled']],
            default => [],
        };
    }

    protected function providerName(): string
    {
        return 'deepseek';
    }

    protected function defaultRegion(): string
    {
        return 'default';
    }

    /**
     * Region key doubles as upstream selector. `default`/`cn`/`beta`
     * stay DeepSeek-native; the rest pick a relay vendor that hosts
     * the same V4 weights behind an OpenAI-compat endpoint. SGLang
     * (self-hosted) requires the caller to pass `base_url` directly —
     * we don't have a fixed URL for it.
     */
    protected function regionToBaseUrl(string $region): string
    {
        $key = strtolower(trim($region));
        $this->upstream = $key;

        if (isset(self::UPSTREAM_MAP[$key])) {
            return self::UPSTREAM_MAP[$key];
        }

        if ($key === 'sglang') {
            throw new ProviderException(
                "upstream 'sglang' requires an explicit base_url config "
                . "(self-hosted SGLang has no fixed endpoint)",
                'deepseek',
            );
        }

        throw new ProviderException(
            "Unknown upstream/region '{$region}' for deepseek (expected one of: "
            . implode(', ', array_keys(self::UPSTREAM_MAP)) . ', sglang)',
            'deepseek',
        );
    }

    /**
     * Default to V4-Flash. V4-Pro is more capable but also ~4x the cost;
     * Flash matches the price/quality the legacy `deepseek-chat` users
     * already paid for and is what DeepSeek currently routes the retired
     * `deepseek-chat` / `deepseek-reasoner` aliases to.
     */
    protected function defaultModel(): string
    {
        return 'deepseek-v4-flash';
    }

    /**
     * Override the encoder so V4 thinking mode's `reasoning_content`
     * survives the round-trip. The base OpenAIChatEncoder drops
     * thinking blocks (correct for OpenAI proper); we re-attach them
     * to the assistant wire message as `reasoning_content` so the
     * Interleaved-Thinking replay rule is honored.
     *
     * @param Message[] $messages
     * @return list<array<string, mixed>>
     */
    public function formatMessages(array $messages): array
    {
        $encoder = new OpenAIChatEncoder();
        $out = [];
        foreach ($messages as $m) {
            $entries = $encoder->encode([$m]);
            if ($m instanceof AssistantMessage && $entries !== []) {
                $reasoning = $this->extractReasoning($m);
                if ($reasoning !== '') {
                    // The encoder produces exactly one entry per
                    // AssistantMessage — attach reasoning_content there.
                    $entries[0]['reasoning_content'] = $reasoning;
                }
            }
            foreach ($entries as $e) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    protected function customizeRequestBody(array &$body, array $options): void
    {
        // Direct `thinking` knob — same shape FeatureDispatcher would
        // produce, but exposed as a top-level option for callers who
        // don't go through the features API.
        if (isset($options['thinking']) && $options['thinking'] !== false) {
            $body['thinking'] = is_array($options['thinking'])
                ? $options['thinking']
                : ['type' => 'enabled'];
        }

        // Reasoning effort — three-tier dial that overlays both
        // `thinking` and `reasoning_effort` (and shapes them per
        // upstream). When provided as an option, it wins over a
        // bare `thinking` toggle above.
        if (isset($options['reasoning_effort']) && is_string($options['reasoning_effort'])) {
            $fragment = $this->reasoningEffortFragment($options['reasoning_effort']);
            foreach ($fragment as $k => $v) {
                $body[$k] = $v;
            }
        }

        // Final-pass sanitizer: V4 thinking mode rejects any assistant
        // message with `tool_calls` that lacks `reasoning_content`.
        // After all other body assembly is done — formatMessages,
        // FeatureDispatcher, extra_body — we walk the messages array
        // and force a placeholder onto any assistant+tool_calls that
        // slipped through without one. Bullet-proofs sessions restored
        // from disk pre-fix, sub-agents that hand-build messages, and
        // future code paths we haven't thought of.
        $modelId = (string) ($body['model'] ?? $this->model);
        if ($this->shouldReplayReasoningContent($modelId, $options)) {
            $this->sanitizeThinkingModeMessages($body);
        }
    }

    /**
     * Walk an AssistantMessage's content blocks and concatenate every
     * `thinking` block into a single string. Returns '' when there
     * is no reasoning to replay.
     */
    private function extractReasoning(AssistantMessage $m): string
    {
        $parts = [];
        foreach ($m->content as $b) {
            if ($b->type === 'thinking' && $b->thinking !== null && $b->thinking !== '') {
                $parts[] = $b->thinking;
            }
        }
        return implode('', $parts);
    }

    /**
     * Force a non-empty `reasoning_content` onto every assistant
     * message in the request that carries `tool_calls`. Mirrors
     * DeepSeek-TUI's `sanitize_thinking_mode_messages` pass — last
     * line of defense before the request hits the wire.
     *
     * @param array<string, mixed> $body
     */
    private function sanitizeThinkingModeMessages(array &$body): void
    {
        if (! isset($body['messages']) || ! is_array($body['messages'])) {
            return;
        }
        foreach ($body['messages'] as &$msg) {
            if (! is_array($msg)) {
                continue;
            }
            if (($msg['role'] ?? null) !== 'assistant') {
                continue;
            }
            // Only enforce when the message has tool_calls — DeepSeek
            // accepts plain assistant text without reasoning_content.
            if (empty($msg['tool_calls'])) {
                continue;
            }
            $existing = $msg['reasoning_content'] ?? null;
            $hasReasoning = is_string($existing) && trim($existing) !== '';
            if (! $hasReasoning) {
                $msg['reasoning_content'] = '(reasoning omitted)';
            }
        }
        unset($msg);
    }

    /**
     * Sanitizer gate: fire only on models that require interleaved
     * thinking AND when the caller hasn't explicitly disabled it.
     * Mirrors the TUI's `should_replay_reasoning_content`.
     *
     * @param array<string, mixed> $options
     */
    private function shouldReplayReasoningContent(string $model, array $options): bool
    {
        $effort = $options['reasoning_effort'] ?? null;
        if (is_string($effort)) {
            $normalised = strtolower(trim($effort));
            if (in_array($normalised, ['off', 'disabled', 'none', 'false'], true)) {
                return false;
            }
        }
        return self::requiresReasoningContent($model);
    }

    /**
     * V3.2 / V4 / R-series / `*-reasoning` / `*-thinking` model ids
     * trigger the reasoning_content replay rule. Older non-reasoning
     * V3 / V2 ids do not. Public so other call sites (compactor,
     * model resolver) can ask the same question.
     */
    public static function requiresReasoningContent(string $model): bool
    {
        $lower = strtolower($model);
        if (strpos($lower, 'deepseek-v3.2') !== false) return true;
        if (strpos($lower, 'deepseek-v4') !== false) return true;
        if (strpos($lower, 'reasoner') !== false) return true;
        if (strpos($lower, '-reasoning') !== false) return true;
        if (strpos($lower, '-thinking') !== false) return true;
        // R-series: deepseek-r1, deepseek-r1-distill-*, etc.
        if (preg_match('/deepseek-r\d/', $lower) === 1) return true;
        return false;
    }

    /**
     * Resolve the upstream key. The parent constructor calls
     * regionToBaseUrl() which sets `$this->upstream`; if our ctor
     * hasn't run yet, fall back to 'deepseek'.
     */
    private function resolvedUpstream(): string
    {
        return $this->upstream ?: 'deepseek';
    }

    // ── FIM (Fill-In-the-Middle) — beta endpoint ─────────────────

    /**
     * Hit DeepSeek's FIM / prefix-completion endpoint at
     * `/beta/v1/completions`. The wire shape mirrors OpenAI's legacy
     * Completions API plus a `suffix` field — server fills in code
     * between `prompt` and `suffix`.
     *
     * Returns the assembled completion text. Streaming is not
     * exposed here; FIM is normally fast enough that callers want
     * the synchronous shape, and the SSE parser in the parent class
     * is built around chat-completions deltas anyway.
     *
     * Requires `region: 'beta'` (or `upstream: 'beta'`); raises
     * otherwise rather than silently routing to a wrong endpoint.
     *
     * @param array<string, mixed> $options
     */
    public function completeFim(string $prefix, string $suffix = '', array $options = []): string
    {
        if ($this->resolvedUpstream() !== 'beta') {
            throw new ProviderException(
                "completeFim() requires region/upstream='beta'; got '"
                . $this->resolvedUpstream() . "'. Construct a separate "
                . "DeepSeekProvider with ['region' => 'beta'] for FIM use.",
                'deepseek',
            );
        }

        $body = [
            'model'       => $options['model'] ?? $this->model,
            'prompt'      => $prefix,
            'suffix'      => $suffix,
            'max_tokens'  => (int) ($options['max_tokens'] ?? 256),
            'temperature' => (float) ($options['temperature'] ?? 0.0),
            'stream'      => false,
        ];
        if (isset($options['top_p'])) {
            $body['top_p'] = (float) $options['top_p'];
        }
        if (isset($options['stop'])) {
            $body['stop'] = $options['stop'];
        }

        $response = $this->client->post('v1/completions', ['json' => $body]);
        $payload = json_decode((string) $response->getBody(), true);
        if (! is_array($payload) || ! isset($payload['choices'][0])) {
            throw new ProviderException(
                'FIM response missing choices[0]',
                'deepseek',
            );
        }
        // Legacy completions shape ships text on `text`; some relays
        // proxy it through `message.content`. Accept both.
        $choice = $payload['choices'][0];
        return (string) ($choice['text']
            ?? $choice['message']['content']
            ?? '');
    }
}
