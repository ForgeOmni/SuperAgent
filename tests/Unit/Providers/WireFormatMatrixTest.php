<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\BedrockProvider;
use SuperAgent\Providers\GeminiProvider;
use SuperAgent\Providers\GlmProvider;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\MiniMaxProvider;
use SuperAgent\Providers\OllamaProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\OpenAIResponsesProvider;
use SuperAgent\Providers\QwenNativeProvider;
use SuperAgent\Providers\QwenProvider;

/**
 * Same-family round-trip test matrix for the six wire-format families
 * the SDK speaks. The intent is NOT to test cross-family handoff (that
 * lands with the ConversationTranscoder in P1+) — it's to lock down
 * the invariant that EACH provider can re-encode its own conversation
 * history without losing tool-call ids, splitting parallel tool calls
 * apart, or collapsing text + tool_use turns.
 *
 * One canonical "spicy" fixture is fed through every provider:
 *
 *     user
 *     assistant: text + 2x parallel tool_use
 *     tool_results: 2 parallel results
 *     assistant: final text
 *
 * The fixture is deliberately the realistic agent-loop shape — the
 * one the original `convertMessage()` path silently corrupted.
 *
 * Family layout (six entries; sub-classes share their parent's encoder):
 *
 *   A. Anthropic Messages   → AnthropicProvider, BedrockProvider
 *   B. OpenAI Chat Compl.   → OpenAIProvider + 6 subclasses
 *   C. OpenAI Responses     → OpenAIResponsesProvider
 *   D. Google Gemini        → GeminiProvider
 *   E. DashScope (Qwen)     → QwenNativeProvider
 *   F. Ollama               → OllamaProvider  (no tool support today)
 */
class WireFormatMatrixTest extends TestCase
{
    /**
     * @return list<\SuperAgent\Messages\Message>
     */
    private function fixture(): array
    {
        $user = new UserMessage('weather in paris and london?');

        $assistant1 = new AssistantMessage();
        $assistant1->content = [
            ContentBlock::text("I'll check both."),
            ContentBlock::toolUse('call_1', 'get_weather', ['city' => 'Paris']),
            ContentBlock::toolUse('call_2', 'get_weather', ['city' => 'London']),
        ];

        $results = ToolResultMessage::fromResults([
            ['tool_use_id' => 'call_1', 'content' => 'Paris: 21°C sunny'],
            ['tool_use_id' => 'call_2', 'content' => 'London: 14°C rain'],
        ]);

        $assistant2 = new AssistantMessage();
        $assistant2->content = [
            ContentBlock::text('Paris is 21°C sunny, London is 14°C rain.'),
        ];

        return [$user, $assistant1, $results, $assistant2];
    }

    // ── Family A: Anthropic Messages ─────────────────────────────────

    public function test_family_A_anthropic_native_round_trip(): void
    {
        $p = new AnthropicProvider(['api_key' => 'sk-ant-x']);
        $wire = $p->formatMessages($this->fixture());

        $this->assertCount(4, $wire);
        $this->assertSame('user',      $wire[0]['role']);
        $this->assertSame('assistant', $wire[1]['role']);
        $this->assertSame('user',      $wire[2]['role'], 'Anthropic tool results ride on a user message');
        $this->assertSame('assistant', $wire[3]['role']);

        // Parallel tool_use blocks live in ONE assistant message.
        $blocks = $wire[1]['content'];
        $this->assertSame('text', $blocks[0]['type']);
        $this->assertSame("I'll check both.", $blocks[0]['text']);
        $this->assertSame('tool_use', $blocks[1]['type']);
        $this->assertSame('call_1', $blocks[1]['id']);
        $this->assertSame('get_weather', $blocks[1]['name']);
        $this->assertSame(['city' => 'Paris'], $blocks[1]['input']);
        $this->assertSame('tool_use', $blocks[2]['type']);
        $this->assertSame('call_2', $blocks[2]['id']);

        // Parallel tool_results live as TWO blocks in ONE user message.
        $resBlocks = $wire[2]['content'];
        $this->assertSame('tool_result', $resBlocks[0]['type']);
        $this->assertSame('call_1', $resBlocks[0]['tool_use_id']);
        $this->assertSame('Paris: 21°C sunny', $resBlocks[0]['content']);
        $this->assertSame('call_2', $resBlocks[1]['tool_use_id']);
    }

    public function test_family_A_bedrock_matches_anthropic_shape(): void
    {
        // Bedrock's anthropic.* models speak the Anthropic Messages
        // protocol verbatim. After the P0 fix, BedrockProvider delegates
        // to Message::toArray(), so its encoding must match
        // AnthropicProvider's exactly.
        $bedrock = $this->makeBedrock();
        $anth    = new AnthropicProvider(['api_key' => 'sk-ant-x']);

        $reflect = new ReflectionMethod(BedrockProvider::class, 'convertMessageToAnthropic');
        $reflect->setAccessible(true);

        $fixture = $this->fixture();
        $bedrockWire = array_map(fn ($m) => $reflect->invoke($bedrock, $m), $fixture);
        $anthWire    = $anth->formatMessages($fixture);

        $this->assertSame($anthWire, $bedrockWire);
    }

    // ── Family B: OpenAI Chat Completions (and all subclasses) ───────

    public function test_family_B_openai_chat_completions_round_trip(): void
    {
        $p = new OpenAIProvider(['api_key' => 'sk-x']);
        $wire = $p->formatMessages($this->fixture());

        // 1 user + 1 assistant + 2 tool + 1 assistant
        $this->assertCount(5, $wire);
        $this->assertSame(['user', 'assistant', 'tool', 'tool', 'assistant'], array_column($wire, 'role'));
        $this->assertCount(2, $wire[1]['tool_calls']);
        $this->assertSame(['call_1', 'call_2'], array_column($wire[1]['tool_calls'], 'id'));
        $this->assertSame('call_1', $wire[2]['tool_call_id']);
        $this->assertSame('call_2', $wire[3]['tool_call_id']);
    }

    /**
     * Sub-classes of ChatCompletionsProvider must produce identical wire
     * for a fixture that doesn't touch any vendor-specific extension
     * (no `$`-prefix builtins, no GLM thinking knob, etc.). If any of
     * them ship divergent message encoding logic in the future, this
     * matrix catches it.
     *
     * @dataProvider chatCompletionsSubclasses
     */
    public function test_family_B_subclasses_produce_identical_wire(string $providerClass): void
    {
        /** @var \SuperAgent\Providers\ChatCompletionsProvider $sub */
        $sub  = new $providerClass(['api_key' => 'sk-x']);
        $base = new OpenAIProvider(['api_key' => 'sk-x']);

        $this->assertSame(
            $base->formatMessages($this->fixture()),
            $sub->formatMessages($this->fixture()),
            "$providerClass diverged from the OpenAI Chat Completions wire shape"
        );
    }

    public static function chatCompletionsSubclasses(): array
    {
        return [
            'kimi'    => [KimiProvider::class],
            'glm'     => [GlmProvider::class],
            'minimax' => [MiniMaxProvider::class],
            'qwen'    => [QwenProvider::class],
        ];
    }

    // ── Family C: OpenAI Responses API ───────────────────────────────

    public function test_family_C_openai_responses_round_trip(): void
    {
        $p = new OpenAIResponsesProvider(['api_key' => 'sk-x']);

        $reflect = new ReflectionMethod(OpenAIResponsesProvider::class, 'convertMessagesToInput');
        $reflect->setAccessible(true);
        /** @var array<int, array<string, mixed>> $wire */
        $wire = $reflect->invoke($p, $this->fixture());

        // user message → 1 entry
        // assistant turn (text + 2 tool_use) → 3 entries (1 message + 2 function_call)
        // 2 tool_results → 2 function_call_output
        // final assistant text → 1 message
        $this->assertCount(7, $wire);

        $this->assertSame('message',       $wire[0]['type']);
        $this->assertSame('user',          $wire[0]['role']);

        $this->assertSame('message',       $wire[1]['type']);
        $this->assertSame('assistant',     $wire[1]['role']);
        $this->assertSame('output_text',   $wire[1]['content'][0]['type']);

        $this->assertSame('function_call', $wire[2]['type']);
        $this->assertSame('call_1',        $wire[2]['call_id']);
        $this->assertSame('get_weather',   $wire[2]['name']);
        $this->assertSame(['city' => 'Paris'], json_decode($wire[2]['arguments'], true));

        $this->assertSame('function_call', $wire[3]['type']);
        $this->assertSame('call_2',        $wire[3]['call_id']);

        $this->assertSame('function_call_output', $wire[4]['type']);
        $this->assertSame('call_1', $wire[4]['call_id']);
        $this->assertSame('Paris: 21°C sunny', $wire[4]['output']);

        $this->assertSame('function_call_output', $wire[5]['type']);
        $this->assertSame('call_2', $wire[5]['call_id']);

        $this->assertSame('message',   $wire[6]['type']);
        $this->assertSame('assistant', $wire[6]['role']);
    }

    // ── Family D: Google Gemini ──────────────────────────────────────

    public function test_family_D_gemini_round_trip(): void
    {
        $p = new GeminiProvider(['api_key' => 'AIza-x']);
        $wire = $p->formatMessages($this->fixture());

        $this->assertCount(4, $wire);
        $this->assertSame('user',  $wire[0]['role']);
        $this->assertSame('model', $wire[1]['role'], 'Gemini uses "model", not "assistant"');
        $this->assertSame('user',  $wire[2]['role'], 'tool results in Gemini are functionResponse parts on a user turn');
        $this->assertSame('model', $wire[3]['role']);

        // Assistant turn: 1 text part + 2 functionCall parts.
        $aParts = $wire[1]['parts'];
        $this->assertSame("I'll check both.", $aParts[0]['text']);
        $this->assertSame('get_weather', $aParts[1]['functionCall']['name']);
        $this->assertSame(['city' => 'Paris'], $aParts[1]['functionCall']['args']);
        $this->assertSame('get_weather', $aParts[2]['functionCall']['name']);
        $this->assertSame(['city' => 'London'], $aParts[2]['functionCall']['args']);

        // Tool result turn: 2 functionResponse parts. Critically, Gemini
        // matches results to calls by NAME — so even though we passed
        // tool_use_ids in, the converter must resolve each id back to its
        // recorded tool name.
        $rParts = $wire[2]['parts'];
        $this->assertSame('get_weather', $rParts[0]['functionResponse']['name']);
        $this->assertSame('get_weather', $rParts[1]['functionResponse']['name']);
    }

    // ── Family E: DashScope (Qwen native) ────────────────────────────

    public function test_family_E_qwen_native_round_trip(): void
    {
        $p = new QwenNativeProvider(['api_key' => 'sk-x']);
        $wire = $p->formatMessages($this->fixture());

        $this->assertCount(5, $wire);
        $this->assertSame(['user', 'assistant', 'tool', 'tool', 'assistant'], array_column($wire, 'role'));
        $this->assertCount(2, $wire[1]['tool_calls']);
        $this->assertSame('call_1', $wire[1]['tool_calls'][0]['id']);
        $this->assertSame('get_weather', $wire[1]['tool_calls'][0]['function']['name']);
        $this->assertSame(['city' => 'Paris'], json_decode($wire[1]['tool_calls'][0]['function']['arguments'], true));
        $this->assertSame('call_1', $wire[2]['tool_call_id']);
        $this->assertSame('Paris: 21°C sunny', $wire[2]['content']);
    }

    // ── Family F: Ollama ─────────────────────────────────────────────

    public function test_family_F_ollama_text_only_round_trip(): void
    {
        // Ollama's converter currently does NOT round-trip tool calls —
        // its convertMessage() only emits text content for assistant
        // and stringified content for everyone else. Until the P4
        // transcoder rewrite lands, the only invariant we can lock is
        // that text-only conversations survive intact.
        $user      = new UserMessage('hi');
        $assistant = new AssistantMessage();
        $assistant->content = [ContentBlock::text('hello')];
        $system    = new SystemMessage('be brief');

        $p = new OllamaProvider(['base_url' => 'http://localhost:11434']);
        $wire = $p->formatMessages([$system, $user, $assistant]);

        $this->assertCount(3, $wire);
        $this->assertSame('be brief',  $wire[0]['content']);
        $this->assertSame('hi',        $wire[1]['content']);
        $this->assertSame('assistant', $wire[2]['role']);
        $this->assertSame('hello',     $wire[2]['content']);
    }

    /**
     * BedrockProvider's constructor reaches into the AWS SDK to build
     * a Guzzle client. Stubbing all of that here is more friction than
     * value — we only need the protected convertMessageToAnthropic()
     * method, which doesn't touch the client. Spin one up via
     * ReflectionClass without invoking __construct.
     */
    private function makeBedrock(): BedrockProvider
    {
        return (new \ReflectionClass(BedrockProvider::class))
            ->newInstanceWithoutConstructor();
    }
}
