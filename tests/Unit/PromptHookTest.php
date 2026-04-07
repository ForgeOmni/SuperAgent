<?php

declare(strict_types=1);

namespace Tests\Unit;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Hooks\AgentHook;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookResult;
use SuperAgent\Hooks\HookType;
use SuperAgent\Hooks\PromptHook;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

class PromptHookTest extends TestCase
{
    private function makeInput(
        HookEvent $event = HookEvent::PRE_TOOL_USE,
        string $sessionId = 'sess-123',
        string $cwd = '/project',
        array $data = [],
    ): HookInput {
        return new HookInput(
            hookEvent: $event,
            sessionId: $sessionId,
            cwd: $cwd,
            gitRepoRoot: '/project',
            additionalData: $data,
        );
    }

    private function createMockProvider(string $responseText): LLMProvider
    {
        $message = new AssistantMessage();
        $message->content = [ContentBlock::text($responseText)];

        $provider = $this->createMock(LLMProvider::class);

        $provider->method('chat')
            ->willReturnCallback(function () use ($message): Generator {
                yield $message;
            });

        $provider->method('getModel')->willReturn('test-model');
        $provider->method('setModel');

        return $provider;
    }

    // --- PromptHook tests ---

    public function testPromptHookGetType(): void
    {
        $hook = new PromptHook(prompt: 'Is this safe?');

        $this->assertEquals(HookType::PROMPT, $hook->getType());
    }

    public function testPromptHookIsNotAsync(): void
    {
        $hook = new PromptHook(prompt: 'Is this safe?');

        $this->assertFalse($hook->isAsync());
    }

    public function testPromptHookNoProviderSkips(): void
    {
        $hook = new PromptHook(prompt: 'Is this safe?');
        $input = $this->makeInput();

        $result = $hook->execute($input);

        $this->assertTrue($result->continue);
    }

    public function testPromptHookOkTrueContinues(): void
    {
        $provider = $this->createMockProvider('{"ok": true}');
        $hook = new PromptHook(prompt: 'Is this safe?', provider: $provider);

        $input = $this->makeInput();
        $result = $hook->execute($input);

        $this->assertTrue($result->continue);
    }

    public function testPromptHookOkFalseStopsWithReason(): void
    {
        $provider = $this->createMockProvider('{"ok": false, "reason": "Dangerous command detected"}');
        $hook = new PromptHook(prompt: 'Is this safe?', provider: $provider);

        $input = $this->makeInput();
        $result = $hook->execute($input);

        $this->assertFalse($result->continue);
        $this->assertEquals('Dangerous command detected', $result->stopReason);
    }

    public function testPromptHookOkFalseWithoutReasonUsesDefault(): void
    {
        $provider = $this->createMockProvider('{"ok": false}');
        $hook = new PromptHook(prompt: 'Is this safe?', provider: $provider);

        $input = $this->makeInput();
        $result = $hook->execute($input);

        $this->assertFalse($result->continue);
        $this->assertEquals('Prompt hook validation failed', $result->stopReason);
    }

    public function testPromptHookArgumentsInjection(): void
    {
        $capturedPrompt = null;
        $message = new AssistantMessage();
        $message->content = [ContentBlock::text('{"ok": true}')];

        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')
            ->willReturnCallback(function (array $messages) use ($message, &$capturedPrompt): Generator {
                $capturedPrompt = $messages[0]->content ?? '';
                yield $message;
            });
        $provider->method('getModel')->willReturn('test-model');

        $hook = new PromptHook(
            prompt: 'Validate these args: $ARGUMENTS',
            provider: $provider,
        );

        $input = $this->makeInput(data: ['tool_name' => 'Bash', 'command' => 'rm -rf /']);
        $hook->execute($input);

        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString('Bash', $capturedPrompt);
        $this->assertStringContainsString('rm -rf', $capturedPrompt);
    }

    public function testPromptHookBlockOnFailureStopsOnException(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')
            ->willThrowException(new \RuntimeException('API timeout'));
        $provider->method('getModel')->willReturn('test-model');

        $hook = new PromptHook(
            prompt: 'test',
            provider: $provider,
            blockOnFailure: true,
        );

        $input = $this->makeInput();
        $result = $hook->execute($input);

        $this->assertFalse($result->continue);
        $this->assertStringContainsString('Prompt hook failed', $result->stopReason);
    }

    public function testPromptHookNoBlockOnFailureContinuesOnException(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')
            ->willThrowException(new \RuntimeException('API timeout'));
        $provider->method('getModel')->willReturn('test-model');

        $hook = new PromptHook(
            prompt: 'test',
            provider: $provider,
            blockOnFailure: false,
        );

        $input = $this->makeInput();
        $result = $hook->execute($input);

        $this->assertTrue($result->continue);
    }

    public function testPromptHookMatchesWithNoMatcher(): void
    {
        $hook = new PromptHook(prompt: 'test');

        $this->assertTrue($hook->matches('Bash'));
        $this->assertTrue($hook->matches('Read'));
        $this->assertTrue($hook->matches(null));
    }

    public function testPromptHookMatchesWithMatcher(): void
    {
        $hook = new PromptHook(prompt: 'test', matcher: 'Bash*');

        $this->assertTrue($hook->matches('Bash'));
        $this->assertTrue($hook->matches('BashExtended'));
        $this->assertFalse($hook->matches('Read'));
        $this->assertFalse($hook->matches(null));
    }

    public function testPromptHookOnceSkipsSecondExecution(): void
    {
        $provider = $this->createMockProvider('{"ok": true}');
        $hook = new PromptHook(prompt: 'test', provider: $provider, once: true);

        $input = $this->makeInput();

        // First execution succeeds
        $first = $hook->execute($input);
        $this->assertTrue($first->continue);

        // Second execution is skipped
        $second = $hook->execute($input);
        $this->assertTrue($second->continue);
        $this->assertStringContainsString('already executed', $second->systemMessage);
    }

    public function testPromptHookSetProvider(): void
    {
        $hook = new PromptHook(prompt: 'test');

        // Without provider, should skip
        $input = $this->makeInput();
        $result1 = $hook->execute($input);
        $this->assertTrue($result1->continue);

        // Set provider, should now execute
        $provider = $this->createMockProvider('{"ok": true}');
        $hook->setProvider($provider);

        $result2 = $hook->execute($input);
        $this->assertTrue($result2->continue);
    }

    public function testPromptHookParsesSimpleYesResponse(): void
    {
        $provider = $this->createMockProvider('yes');
        $hook = new PromptHook(prompt: 'test', provider: $provider);

        $result = $hook->execute($this->makeInput());
        $this->assertTrue($result->continue);
    }

    public function testPromptHookParsesSimpleNoResponse(): void
    {
        $provider = $this->createMockProvider('no');
        $hook = new PromptHook(prompt: 'test', provider: $provider);

        $result = $hook->execute($this->makeInput());
        $this->assertFalse($result->continue);
    }

    public function testPromptHookGetConditionReturnsMatcher(): void
    {
        $hook = new PromptHook(prompt: 'test', matcher: 'Bash*');

        $this->assertEquals('Bash*', $hook->getCondition());
    }

    // --- AgentHook tests ---

    public function testAgentHookGetType(): void
    {
        $hook = new AgentHook(prompt: 'Deep analysis');

        $this->assertEquals(HookType::AGENT, $hook->getType());
    }

    public function testAgentHookNoProviderSkips(): void
    {
        $hook = new AgentHook(prompt: 'Deep analysis');
        $input = $this->makeInput();

        $result = $hook->execute($input);
        $this->assertTrue($result->continue);
    }

    public function testAgentHookOkTrueContinues(): void
    {
        $provider = $this->createMockProvider('{"ok": true}');
        $hook = new AgentHook(prompt: 'Validate deeply', provider: $provider);

        $result = $hook->execute($this->makeInput());
        $this->assertTrue($result->continue);
    }

    public function testAgentHookOkFalseStops(): void
    {
        $provider = $this->createMockProvider('{"ok": false, "reason": "Security violation"}');
        $hook = new AgentHook(prompt: 'Validate deeply', provider: $provider);

        $result = $hook->execute($this->makeInput());
        $this->assertFalse($result->continue);
        $this->assertEquals('Security violation', $result->stopReason);
    }

    public function testAgentHookDefaultBlockOnFailure(): void
    {
        $hook = new AgentHook(prompt: 'test');

        $reflection = new \ReflectionClass($hook);
        $prop = $reflection->getProperty('blockOnFailure');
        $prop->setAccessible(true);

        $this->assertTrue($prop->getValue($hook));
    }

    public function testAgentHookDefaultTimeout60(): void
    {
        $hook = new AgentHook(prompt: 'test');

        $reflection = new \ReflectionClass($hook);
        $prop = $reflection->getProperty('timeout');
        $prop->setAccessible(true);

        $this->assertEquals(60, $prop->getValue($hook));
    }

    public function testAgentHookMatchesWithMatcher(): void
    {
        $hook = new AgentHook(prompt: 'test', matcher: 'Write');

        $this->assertTrue($hook->matches('Write'));
        $this->assertFalse($hook->matches('Read'));
        $this->assertFalse($hook->matches(null));
    }

    public function testAgentHookSetProvider(): void
    {
        $hook = new AgentHook(prompt: 'test');

        $provider = $this->createMockProvider('{"ok": true}');
        $hook->setProvider($provider);

        $result = $hook->execute($this->makeInput());
        $this->assertTrue($result->continue);
    }

    public function testAgentHookBlocksOnException(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')
            ->willThrowException(new \RuntimeException('Network error'));
        $provider->method('getModel')->willReturn('test-model');

        $hook = new AgentHook(prompt: 'test', provider: $provider, blockOnFailure: true);

        $result = $hook->execute($this->makeInput());
        $this->assertFalse($result->continue);
        $this->assertStringContainsString('Agent hook failed', $result->stopReason);
    }

    public function testAgentHookContinuesOnExceptionWhenNotBlocking(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')
            ->willThrowException(new \RuntimeException('Network error'));
        $provider->method('getModel')->willReturn('test-model');

        $hook = new AgentHook(prompt: 'test', provider: $provider, blockOnFailure: false);

        $result = $hook->execute($this->makeInput());
        $this->assertTrue($result->continue);
    }
}
