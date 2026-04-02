<?php

namespace SuperAgent\Tests\Unit\Bridge\Enhancers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Bridge\Enhancers\CostTrackingEnhancer;
use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;

class CostTrackingEnhancerTest extends TestCase
{
    public function test_tracks_cost_in_metadata(): void
    {
        $enhancer = new CostTrackingEnhancer(maxBudgetUsd: 0.0);

        $messages = [];
        $tools = [];
        $prompt = null;
        $options = ['model' => 'gpt-4o'];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hi')];
        $msg->stopReason = StopReason::EndTurn;
        $msg->usage = new Usage(1000, 500);

        $result = $enhancer->enhanceResponse($msg);

        $this->assertArrayHasKey('bridge_request_cost_usd', $result->metadata);
        $this->assertArrayHasKey('bridge_total_cost_usd', $result->metadata);
        $this->assertGreaterThan(0, $result->metadata['bridge_request_cost_usd']);
    }

    public function test_accumulates_cost(): void
    {
        $enhancer = new CostTrackingEnhancer(maxBudgetUsd: 0.0);

        // First request
        $messages = [];
        $tools = [];
        $prompt = null;
        $options = ['model' => 'gpt-4o'];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $msg1 = new AssistantMessage();
        $msg1->content = [ContentBlock::text('Hi')];
        $msg1->usage = new Usage(1000, 500);
        $enhancer->enhanceResponse($msg1);

        $cost1 = $enhancer->getTotalCostUsd();

        // Second request
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $msg2 = new AssistantMessage();
        $msg2->content = [ContentBlock::text('Hello')];
        $msg2->usage = new Usage(1000, 500);
        $enhancer->enhanceResponse($msg2);

        $this->assertGreaterThan($cost1, $enhancer->getTotalCostUsd());
    }

    public function test_budget_enforcement(): void
    {
        $enhancer = new CostTrackingEnhancer(maxBudgetUsd: 0.001);

        // Simulate spending over budget
        $messages = [];
        $tools = [];
        $prompt = null;
        $options = ['model' => 'gpt-4o'];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hi')];
        $msg->usage = new Usage(100000, 50000);
        $enhancer->enhanceResponse($msg);

        // Next request should throw
        $this->expectException(\SuperAgent\Exceptions\SuperAgentException::class);
        $this->expectExceptionMessage('budget exhausted');
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);
    }

    public function test_reset(): void
    {
        $enhancer = new CostTrackingEnhancer(maxBudgetUsd: 0.0);

        $messages = [];
        $tools = [];
        $prompt = null;
        $options = ['model' => 'gpt-4o'];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hi')];
        $msg->usage = new Usage(1000, 500);
        $enhancer->enhanceResponse($msg);

        $this->assertGreaterThan(0, $enhancer->getTotalCostUsd());

        $enhancer->reset();
        $this->assertSame(0.0, $enhancer->getTotalCostUsd());
    }
}
