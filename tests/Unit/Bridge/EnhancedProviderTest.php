<?php

namespace SuperAgent\Tests\Unit\Bridge;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\EnhancedProvider;
use SuperAgent\Bridge\Enhancers\EnhancerInterface;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;

class EnhancedProviderTest extends TestCase
{
    private function mockProvider(AssistantMessage $response): LLMProvider
    {
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')->willReturnCallback(
            function () use ($response): Generator {
                yield $response;
            }
        );
        $provider->method('name')->willReturn('mock');
        $provider->method('getModel')->willReturn('test-model');
        $provider->method('formatMessages')->willReturn([]);
        $provider->method('formatTools')->willReturn([]);

        return $provider;
    }

    private function makeAssistantMessage(string $text): AssistantMessage
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text($text)];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(100, 50);

        return $msg;
    }

    public function test_passes_through_without_enhancers(): void
    {
        $response = $this->makeAssistantMessage('Hello');
        $enhanced = new EnhancedProvider($this->mockProvider($response));

        $results = iterator_to_array($enhanced->chat([], [], null, []));

        $this->assertCount(1, $results);
        $this->assertSame('Hello', $results[0]->text());
    }

    public function test_enhancer_modifies_system_prompt(): void
    {
        $response = $this->makeAssistantMessage('Modified');

        $enhancer = new class implements EnhancerInterface {
            public function enhanceRequest(array &$messages, array &$tools, ?string &$systemPrompt, array &$options): void
            {
                $systemPrompt = 'ENHANCED: ' . ($systemPrompt ?? '');
            }

            public function enhanceResponse(AssistantMessage $message): AssistantMessage
            {
                return $message;
            }
        };

        $capturedPrompt = null;
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')->willReturnCallback(
            function ($messages, $tools, $systemPrompt) use ($response, &$capturedPrompt): Generator {
                $capturedPrompt = $systemPrompt;
                yield $response;
            }
        );
        $provider->method('name')->willReturn('mock');
        $provider->method('getModel')->willReturn('test-model');

        $enhanced = new EnhancedProvider($provider, [$enhancer]);
        iterator_to_array($enhanced->chat([], [], 'original', []));

        $this->assertSame('ENHANCED: original', $capturedPrompt);
    }

    public function test_enhancer_modifies_response(): void
    {
        $response = $this->makeAssistantMessage('Original');

        $enhancer = new class implements EnhancerInterface {
            public function enhanceRequest(array &$messages, array &$tools, ?string &$systemPrompt, array &$options): void
            {
            }

            public function enhanceResponse(AssistantMessage $message): AssistantMessage
            {
                $modified = new AssistantMessage();
                $modified->content = [ContentBlock::text('Modified: ' . $message->text())];
                $modified->stopReason = $message->stopReason;
                $modified->usage = $message->usage;
                return $modified;
            }
        };

        $enhanced = new EnhancedProvider($this->mockProvider($response), [$enhancer]);
        $results = iterator_to_array($enhanced->chat([], [], null, []));

        $this->assertSame('Modified: Original', $results[0]->text());
    }

    public function test_multiple_enhancers_applied_in_order(): void
    {
        $response = $this->makeAssistantMessage('Base');

        $enhancerA = new class implements EnhancerInterface {
            public function enhanceRequest(array &$messages, array &$tools, ?string &$systemPrompt, array &$options): void
            {
                $systemPrompt = ($systemPrompt ?? '') . '-A';
            }

            public function enhanceResponse(AssistantMessage $message): AssistantMessage
            {
                return $message;
            }
        };

        $enhancerB = new class implements EnhancerInterface {
            public function enhanceRequest(array &$messages, array &$tools, ?string &$systemPrompt, array &$options): void
            {
                $systemPrompt = ($systemPrompt ?? '') . '-B';
            }

            public function enhanceResponse(AssistantMessage $message): AssistantMessage
            {
                return $message;
            }
        };

        $capturedPrompt = null;
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('chat')->willReturnCallback(
            function ($messages, $tools, $systemPrompt) use ($response, &$capturedPrompt): Generator {
                $capturedPrompt = $systemPrompt;
                yield $response;
            }
        );
        $provider->method('name')->willReturn('mock');
        $provider->method('getModel')->willReturn('test-model');

        $enhanced = new EnhancedProvider($provider, [$enhancerA, $enhancerB]);
        iterator_to_array($enhanced->chat([], [], 'start', []));

        $this->assertSame('start-A-B', $capturedPrompt);
    }

    public function test_name_prefixed_with_enhanced(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('name')->willReturn('openai');

        $enhanced = new EnhancedProvider($provider);

        $this->assertSame('enhanced_openai', $enhanced->name());
    }

    public function test_add_enhancer(): void
    {
        $provider = $this->createMock(LLMProvider::class);
        $provider->method('name')->willReturn('mock');

        $enhanced = new EnhancedProvider($provider);
        $this->assertCount(0, $enhanced->getEnhancers());

        $enhancer = $this->createMock(EnhancerInterface::class);
        $enhanced->addEnhancer($enhancer);
        $this->assertCount(1, $enhanced->getEnhancers());
    }
}
