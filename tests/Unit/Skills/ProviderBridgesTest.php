<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Skills;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Skills\Bridges\KimiSkillBridge;
use SuperAgent\Skills\Bridges\MiniMaxSkillBridge;
use SuperAgent\Skills\MarkdownSkill;
use SuperAgent\Skills\Skill;
use SuperAgent\Skills\SkillInjector;

class ProviderBridgesTest extends TestCase
{
    protected function tearDown(): void
    {
        SkillInjector::resetBridges();
    }

    public function test_kimi_bridge_currently_falls_through(): void
    {
        $provider = new FakeNamedProvider('kimi');
        $options = [];
        $skill = $this->makeSkill('test', 'A test skill', 'Skill body here.');

        // Returning false → universal path takes over → system_prompt gets set.
        $returned = KimiSkillBridge::apply($provider, $skill, $options);
        $this->assertFalse($returned);
        $this->assertArrayNotHasKey('system_prompt', $options);  // bridge didn't touch it

        // End-to-end via SkillInjector: bridge returns false → universal runs.
        SkillInjector::registerBridge('kimi', KimiSkillBridge::class);
        SkillInjector::inject($skill, $options, $provider);
        $this->assertStringContainsString('Skill body here.', $options['system_prompt']);
    }

    public function test_minimax_bridge_injects_contract_framing(): void
    {
        $provider = new FakeNamedProvider('minimax');
        $options = ['system_prompt' => 'You are helpful.'];
        $skill = $this->makeSkill('reviewer', 'Code reviewer skill', 'Focus on correctness.');

        $returned = MiniMaxSkillBridge::apply($provider, $skill, $options);
        $this->assertTrue($returned, 'MiniMax bridge should short-circuit universal path');

        $prompt = $options['system_prompt'];
        $this->assertStringContainsString('You are helpful.', $prompt);
        $this->assertStringContainsString('## Active Skill: reviewer', $prompt);
        $this->assertStringContainsString('behavioural contract', $prompt);
        $this->assertStringContainsString('Focus on correctness.', $prompt);
    }

    public function test_minimax_bridge_is_idempotent(): void
    {
        $provider = new FakeNamedProvider('minimax');
        $options = [];
        $skill = $this->makeSkill('my-skill', 'Desc', 'Body.');

        MiniMaxSkillBridge::apply($provider, $skill, $options);
        $firstPrompt = $options['system_prompt'];
        MiniMaxSkillBridge::apply($provider, $skill, $options);

        $this->assertSame($firstPrompt, $options['system_prompt']);
    }

    public function test_minimax_bridge_empty_body_falls_through(): void
    {
        $provider = new FakeNamedProvider('minimax');
        $options = [];
        $skill = $this->makeSkill('empty', 'Has no body', '');

        $this->assertFalse(MiniMaxSkillBridge::apply($provider, $skill, $options));
        $this->assertArrayNotHasKey('system_prompt', $options);
    }

    public function test_bridges_can_be_registered_with_skill_injector(): void
    {
        SkillInjector::registerBridge('kimi', KimiSkillBridge::class);
        SkillInjector::registerBridge('minimax', MiniMaxSkillBridge::class);

        $bridges = SkillInjector::registeredBridges();
        $this->assertArrayHasKey('kimi', $bridges);
        $this->assertArrayHasKey('minimax', $bridges);
        $this->assertSame(KimiSkillBridge::class, $bridges['kimi']);
    }

    private function makeSkill(string $name, string $desc, string $body): Skill
    {
        return new MarkdownSkill(
            ['name' => $name, 'description' => $desc],
            $body,
        );
    }
}

class FakeNamedProvider implements LLMProvider
{
    public function __construct(private readonly string $name) {}
    public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): Generator { yield from []; }
    public function formatMessages(array $messages): array { return []; }
    public function formatTools(array $tools): array { return []; }
    public function getModel(): string { return 'm'; }
    public function setModel(string $model): void {}
    public function name(): string { return $this->name; }
}
