<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Routing\AutoModelStrategy;

class AutoModelStrategyTest extends TestCase
{
    public function test_default_short_chat_picks_flash(): void
    {
        $s = new AutoModelStrategy();
        $this->assertSame(
            AutoModelStrategy::FLASH,
            $s->select([new UserMessage('hi')], null, []),
        );
    }

    public function test_explicit_max_effort_escalates_to_pro(): void
    {
        $s = new AutoModelStrategy();
        $this->assertSame(
            AutoModelStrategy::PRO,
            $s->select([new UserMessage('hi')], null, ['reasoning_effort' => 'max']),
        );
    }

    public function test_long_context_escalates_to_pro(): void
    {
        $s = new AutoModelStrategy(longContextThresholdTokens: 100);
        // 200 chars × tens of messages → easily over 100 tokens on the
        // ~4 chars/token heuristic.
        $bigText = str_repeat('lorem ipsum dolor sit amet, ', 100);
        $messages = [new UserMessage($bigText)];
        $this->assertSame(
            AutoModelStrategy::PRO,
            $s->select($messages, null, []),
        );
    }

    public function test_tool_chain_depth_escalates_to_pro(): void
    {
        $s = new AutoModelStrategy(toolChainThreshold: 3);
        // Three trailing assistant turns with tool_use blocks =
        // active multi-step tool loop. Flash starts to lose the
        // thread here.
        $a = function (string $id): AssistantMessage {
            $m = new AssistantMessage();
            $m->content = [ContentBlock::toolUse($id, 'agent_grep', ['pattern' => 'foo'])];
            return $m;
        };
        $messages = [
            new UserMessage('do the thing'),
            $a('1'), $a('2'), $a('3'),
        ];
        $this->assertSame(
            AutoModelStrategy::PRO,
            $s->select($messages, null, []),
        );
    }

    public function test_intent_keywords_in_system_prompt_escalate_to_pro(): void
    {
        $s = new AutoModelStrategy();
        $sysPrompt = 'You are a senior engineer. Review the proposed migration carefully.';
        $this->assertSame(
            AutoModelStrategy::PRO,
            $s->select([new UserMessage('here is the diff')], $sysPrompt, []),
        );
    }

    public function test_short_chat_with_neutral_intent_keeps_flash(): void
    {
        $s = new AutoModelStrategy();
        $sysPrompt = 'You are a translator. Translate the following.';
        $this->assertSame(
            AutoModelStrategy::FLASH,
            $s->select([new UserMessage('Hola mundo')], $sysPrompt, []),
        );
    }

    public function test_trailing_tool_chain_depth_helper_is_public_for_telemetry(): void
    {
        $s = new AutoModelStrategy();
        $a = function (string $id): AssistantMessage {
            $m = new AssistantMessage();
            $m->content = [ContentBlock::toolUse($id, 'agent_grep', [])];
            return $m;
        };
        $messages = [new UserMessage('go'), $a('1'), $a('2')];
        $this->assertSame(2, $s->trailingToolChainDepth($messages));
    }
}
