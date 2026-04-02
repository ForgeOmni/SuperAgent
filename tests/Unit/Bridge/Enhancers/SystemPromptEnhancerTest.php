<?php

namespace SuperAgent\Tests\Unit\Bridge\Enhancers;

use Orchestra\Testbench\TestCase;
use SuperAgent\Bridge\Enhancers\SystemPromptEnhancer;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

class SystemPromptEnhancerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\SuperAgent\SuperAgentServiceProvider::class];
    }

    public function test_injects_cc_prompt_when_no_existing_prompt(): void
    {
        $enhancer = new SystemPromptEnhancer();

        $messages = [];
        $tools = [];
        $prompt = null;
        $options = [];

        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $this->assertNotNull($prompt);
        $this->assertNotEmpty($prompt);
        // Should contain CC's system rules
        $this->assertStringContainsString('tool', strtolower($prompt));
    }

    public function test_prepends_to_existing_prompt(): void
    {
        $enhancer = new SystemPromptEnhancer();

        $messages = [];
        $tools = [];
        $prompt = 'You are a coding assistant.';
        $options = [];

        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        // Original prompt should still be present
        $this->assertStringContainsString('You are a coding assistant.', $prompt);
        // CC prefix should be prepended
        $this->assertStringContainsString('Client Instructions', $prompt);
    }

    public function test_response_passthrough(): void
    {
        $enhancer = new SystemPromptEnhancer();

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('test')];

        $result = $enhancer->enhanceResponse($msg);
        $this->assertSame($msg, $result);
    }

    public function test_prompt_is_cached_across_calls(): void
    {
        $enhancer = new SystemPromptEnhancer();

        $messages = [];
        $tools = [];
        $prompt1 = null;
        $prompt2 = null;
        $options = [];

        $enhancer->enhanceRequest($messages, $tools, $prompt1, $options);
        $enhancer->enhanceRequest($messages, $tools, $prompt2, $options);

        // Both calls should produce the same prefix
        $this->assertSame($prompt1, $prompt2);
    }
}
