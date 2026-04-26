<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SuperAgent\Agent;
use SuperAgent\Conversation\HandoffPolicy;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\OpenAIProvider;

/**
 * Exercises Agent::switchProvider — the public entry point for
 * mid-conversation provider handoff. The actual wire-format
 * translation is covered by the Transcoder tests; this class focuses
 * on the policy-driven message-list mutations and on the swap
 * semantics (atomic on success, untouched on construction failure).
 */
class AgentSwitchProviderTest extends TestCase
{
    private function agentOnOpenAI(): Agent
    {
        return new Agent([
            'provider' => new OpenAIProvider(['api_key' => 'sk-test']),
        ]);
    }

    private function setMessages(Agent $agent, array $messages): void
    {
        $rp = new ReflectionProperty(Agent::class, 'messages');
        $rp->setAccessible(true);
        $rp->setValue($agent, $messages);
    }

    private function getMessages(Agent $agent): array
    {
        return $agent->getMessages();
    }

    public function test_switch_to_anthropic_preserves_history_and_swaps_provider(): void
    {
        $agent = $this->agentOnOpenAI();
        $this->setMessages($agent, [
            new UserMessage('hello'),
        ]);

        $agent->switchProvider('anthropic', ['api_key' => 'sk-ant-x']);

        $this->assertInstanceOf(AnthropicProvider::class, $agent->getProvider());

        $messages = $this->getMessages($agent);
        $this->assertCount(2, $messages, 'user + handoff marker');
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(SystemMessage::class, $messages[1]);
        $this->assertStringContainsString('handoff',  $messages[1]->content);
        $this->assertStringContainsString('openai',   $messages[1]->content);
        $this->assertStringContainsString('anthropic', $messages[1]->content);
    }

    public function test_switch_drops_thinking_blocks_by_default(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::thinking('private chain-of-thought'),
            ContentBlock::text('public answer'),
        ];

        $agent = $this->agentOnOpenAI();
        $this->setMessages($agent, [
            new UserMessage('hi'),
            $assistant,
        ]);

        $agent->switchProvider('kimi', ['api_key' => 'sk-x']);

        $this->assertInstanceOf(KimiProvider::class, $agent->getProvider());

        // Locate the assistant message in the new history (before the
        // handoff marker) and confirm thinking got stripped.
        $messages = $this->getMessages($agent);
        $assistantOut = null;
        foreach ($messages as $m) {
            if ($m instanceof AssistantMessage) {
                $assistantOut = $m;
                break;
            }
        }
        $this->assertNotNull($assistantOut);
        $types = array_map(fn (ContentBlock $b) => $b->type, $assistantOut->content);
        $this->assertNotContains('thinking', $types);
        $this->assertContains('text',        $types);
    }

    public function test_preserve_all_policy_keeps_thinking_and_skips_marker(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::thinking('keep me'),
            ContentBlock::text('hi'),
        ];

        $agent = $this->agentOnOpenAI();
        $this->setMessages($agent, [
            new UserMessage('hi'),
            $assistant,
        ]);

        $agent->switchProvider('anthropic', ['api_key' => 'sk-ant-x'], HandoffPolicy::preserveAll());

        $messages = $this->getMessages($agent);
        $this->assertCount(2, $messages, 'no handoff marker appended');
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $types = array_map(fn (ContentBlock $b) => $b->type, $messages[1]->content);
        $this->assertContains('thinking', $types, 'thinking preserved under preserveAll');
    }

    public function test_fresh_start_policy_collapses_history_to_latest_user(): void
    {
        $oldAssistant = new AssistantMessage();
        $oldAssistant->content = [ContentBlock::text('old answer')];

        $agent = $this->agentOnOpenAI();
        $this->setMessages($agent, [
            new UserMessage('first question'),
            $oldAssistant,
            new UserMessage('second question'),
        ]);

        $agent->switchProvider('anthropic', ['api_key' => 'sk-ant-x'], HandoffPolicy::freshStart());

        $messages = $this->getMessages($agent);
        // latest user + handoff marker
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertSame('second question', $messages[0]->content);
        $this->assertInstanceOf(SystemMessage::class, $messages[1]);
    }

    public function test_switch_records_token_status_against_new_models_window(): void
    {
        $agent = $this->agentOnOpenAI();
        $this->setMessages($agent, [
            new UserMessage(str_repeat('lorem ipsum ', 200)),
        ]);

        $this->assertNull($agent->lastHandoffTokenStatus(), 'no handoff yet → null');

        $agent->switchProvider('anthropic', ['api_key' => 'sk-ant-x']);

        $status = $agent->lastHandoffTokenStatus();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('tokens', $status);
        $this->assertArrayHasKey('window', $status);
        $this->assertArrayHasKey('fits',   $status);
        $this->assertArrayHasKey('model',  $status);
        $this->assertGreaterThan(0, $status['tokens']);
        $this->assertGreaterThan(0, $status['window']);
        $this->assertTrue($status['fits'], 'modest history should fit under any 200K-window model');
    }

    public function test_thinking_blocks_are_captured_into_anthropic_artifacts_on_drop(): void
    {
        $assistant = new AssistantMessage();
        $assistant->content = [
            ContentBlock::thinking('the secret reasoning'),
            ContentBlock::text('answer'),
        ];

        $agent = $this->agentOnOpenAI();
        $this->setMessages($agent, [new UserMessage('hi'), $assistant]);

        // default policy drops thinking → should land in metadata
        $agent->switchProvider('kimi', ['api_key' => 'sk-x']);

        $messages = $this->getMessages($agent);
        $assistantOut = null;
        foreach ($messages as $m) {
            if ($m instanceof AssistantMessage) {
                $assistantOut = $m;
                break;
            }
        }
        $this->assertNotNull($assistantOut);

        $stashed = \SuperAgent\Conversation\ProviderArtifacts::get(
            $assistantOut->metadata,
            'anthropic',
            'thinking'
        );
        $this->assertIsArray($stashed);
        $this->assertSame('the secret reasoning', $stashed[0]['thinking']);
    }

    public function test_failed_provider_construction_leaves_agent_untouched(): void
    {
        $agent = $this->agentOnOpenAI();
        $original = $agent->getProvider();
        $this->setMessages($agent, [new UserMessage('keep me')]);

        try {
            // Missing api_key → ProviderException during construction.
            $agent->switchProvider('kimi', []);
            $this->fail('switchProvider should have thrown');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($original, $agent->getProvider(),
            'provider must NOT be replaced when the new one fails to construct');
        $this->assertCount(1, $this->getMessages($agent),
            'message history must NOT be mutated when the swap fails');
    }
}
