<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers\Features;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Providers\Features\AgentTeamsAdapter;
use SuperAgent\Providers\KimiProvider;
use SuperAgent\Providers\MiniMaxProvider;

class AgentTeamsAdapterTest extends TestCase
{
    public function test_minimax_gets_scaffold_in_system_message(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        AgentTeamsAdapter::apply($p, [
            'objective' => 'Produce report',
            'roles' => [
                ['name' => 'researcher', 'description' => 'gather'],
                ['name' => 'writer', 'description' => 'draft'],
            ],
        ], $body);

        $this->assertSame('system', $body['messages'][0]['role']);
        $content = $body['messages'][0]['content'];
        $this->assertStringContainsString('## Agent Team', $content);
        $this->assertStringContainsString('Produce report', $content);
        $this->assertStringContainsString('researcher', $content);
        $this->assertStringContainsString('writer', $content);
    }

    public function test_non_minimax_provider_gets_same_scaffold(): void
    {
        // Universal degradation path — non-MiniMax providers receive the
        // same system-prompt scaffold so the request can still proceed.
        $p = new KimiProvider(['api_key' => 'k']);
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        AgentTeamsAdapter::apply($p, [
            'roles' => [['name' => 'planner', 'description' => 'plan']],
        ], $body);

        $this->assertStringContainsString('## Agent Team', $body['messages'][0]['content']);
    }

    public function test_empty_spec_is_noop(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $body = ['messages' => []];
        AgentTeamsAdapter::apply($p, [], $body);
        $this->assertSame(['messages' => []], $body);
    }

    public function test_empty_spec_with_required_throws(): void
    {
        $this->expectException(FeatureNotSupportedException::class);
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $body = ['messages' => []];
        AgentTeamsAdapter::apply($p, ['required' => true], $body);
    }

    public function test_disabled_spec_is_hard_noop(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $body = ['messages' => []];
        $before = $body;
        AgentTeamsAdapter::apply($p, [
            'enabled' => false,
            'required' => true,
            'roles' => [['name' => 'x', 'description' => 'x']],
        ], $body);
        $this->assertSame($before, $body);
    }

    public function test_idempotent_on_repeated_apply(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $body = ['messages' => [['role' => 'user', 'content' => 'hi']]];
        $spec = [
            'objective' => 'Plan',
            'roles' => [['name' => 'lead', 'description' => 'coordinate']],
        ];
        AgentTeamsAdapter::apply($p, $spec, $body);
        $firstContent = $body['messages'][0]['content'];

        AgentTeamsAdapter::apply($p, $spec, $body);
        // Second apply must NOT duplicate the scaffold.
        $this->assertSame($firstContent, $body['messages'][0]['content']);
    }

    public function test_existing_system_message_is_merged_not_replaced(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $body = ['messages' => [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'hi'],
        ]];
        AgentTeamsAdapter::apply($p, [
            'roles' => [['name' => 'x', 'description' => 'x']],
        ], $body);

        $this->assertStringContainsString('You are helpful.', $body['messages'][0]['content']);
        $this->assertStringContainsString('## Agent Team', $body['messages'][0]['content']);
        $this->assertSame('system', $body['messages'][0]['role']);
        // Still only one system message at position 0.
        $this->assertSame('user', $body['messages'][1]['role']);
    }

    public function test_unknown_body_shape_is_safely_ignored(): void
    {
        $p = new MiniMaxProvider(['api_key' => 'k']);
        $body = ['input' => ['messages' => []]];  // DashScope-style, no top-level messages
        $before = $body;
        AgentTeamsAdapter::apply($p, [
            'roles' => [['name' => 'x', 'description' => 'x']],
        ], $body);
        $this->assertSame($before, $body);
    }
}
