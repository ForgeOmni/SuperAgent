<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Skills;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Skills\MarkdownSkill;
use SuperAgent\Skills\Skill;
use SuperAgent\Skills\SkillInjector;

class SkillInjectorTest extends TestCase
{
    protected function tearDown(): void
    {
        SkillInjector::resetBridges();
    }

    public function test_inject_into_empty_options_sets_system_prompt(): void
    {
        $skill = $this->makeSkill('greet', 'Say hello', 'Greet the user warmly.');
        $options = [];
        SkillInjector::inject($skill, $options);

        $this->assertArrayHasKey('system_prompt', $options);
        $this->assertStringContainsString('## Skill: greet', $options['system_prompt']);
        $this->assertStringContainsString('Say hello', $options['system_prompt']);
        $this->assertStringContainsString('Greet the user warmly', $options['system_prompt']);
    }

    public function test_inject_appends_to_existing_system_prompt(): void
    {
        $skill = $this->makeSkill('greet', 'Say hello', 'Greet the user.');
        $options = ['system_prompt' => 'You are helpful.'];
        SkillInjector::inject($skill, $options);

        $this->assertStringContainsString('You are helpful.', $options['system_prompt']);
        $this->assertStringContainsString('## Skill: greet', $options['system_prompt']);
        // Existing content comes before injected block.
        $pos1 = strpos($options['system_prompt'], 'You are helpful');
        $pos2 = strpos($options['system_prompt'], '## Skill: greet');
        $this->assertLessThan($pos2, $pos1);
    }

    public function test_inject_is_idempotent_on_same_skill(): void
    {
        $skill = $this->makeSkill('greet', 'Say hello', 'Greet the user.');
        $options = ['system_prompt' => 'Base.'];
        SkillInjector::inject($skill, $options);
        $after1 = $options['system_prompt'];
        SkillInjector::inject($skill, $options);
        $this->assertSame($after1, $options['system_prompt']);
    }

    public function test_inject_stacks_distinct_skills(): void
    {
        $a = $this->makeSkill('refactor', 'Code refactoring', 'Refactor rules.');
        $b = $this->makeSkill('review', 'Code review', 'Review rules.');
        $options = [];
        SkillInjector::inject($a, $options);
        SkillInjector::inject($b, $options);

        $this->assertStringContainsString('## Skill: refactor', $options['system_prompt']);
        $this->assertStringContainsString('## Skill: review', $options['system_prompt']);
    }

    public function test_empty_skill_body_is_skipped(): void
    {
        $skill = $this->makeSkill('empty', 'Empty', '');
        $options = [];
        SkillInjector::inject($skill, $options);
        $this->assertArrayNotHasKey('system_prompt', $options);
    }

    public function test_provider_bridge_wins_when_registered(): void
    {
        SkillInjector::registerBridge('test-provider', TestSkillBridge::class);

        $provider = new TestBridgeProvider('test-provider');
        $skill = $this->makeSkill('handy', 'Handy', 'Handy body.');
        $options = ['system_prompt' => 'Base.'];

        SkillInjector::inject($skill, $options, $provider);

        // Bridge path set `native_skill_id` and returned true → universal
        // path must have been skipped, so system prompt stays "Base."
        $this->assertSame('Base.', $options['system_prompt']);
        $this->assertSame('handy', $options['native_skill_id']);
    }

    public function test_bridge_returning_false_falls_through_to_universal(): void
    {
        SkillInjector::registerBridge('test-provider', FallthroughSkillBridge::class);

        $provider = new TestBridgeProvider('test-provider');
        $skill = $this->makeSkill('handy', 'Handy', 'Handy body.');
        $options = [];

        SkillInjector::inject($skill, $options, $provider);

        $this->assertStringContainsString('## Skill: handy', $options['system_prompt']);
    }

    public function test_no_bridge_for_provider_uses_universal(): void
    {
        SkillInjector::registerBridge('other-provider', TestSkillBridge::class);

        $provider = new TestBridgeProvider('test-provider');  // different name
        $skill = $this->makeSkill('handy', 'Handy', 'Handy body.');
        $options = [];

        SkillInjector::inject($skill, $options, $provider);

        $this->assertStringContainsString('## Skill: handy', $options['system_prompt']);
    }

    public function test_register_bridge_rejects_class_without_apply(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SkillInjector::registerBridge('x', \stdClass::class);
    }

    private function makeSkill(string $name, string $desc, string $body): Skill
    {
        return new MarkdownSkill(
            ['name' => $name, 'description' => $desc, 'category' => 'test'],
            $body,
        );
    }
}

/**
 * Bridge that short-circuits the universal path and tags options with a
 * `native_skill_id` so the test can assert we went through the bridge.
 */
class TestSkillBridge
{
    public static function apply(LLMProvider $provider, Skill $skill, array &$options): bool
    {
        $options['native_skill_id'] = $skill->name();
        return true;
    }
}

class FallthroughSkillBridge
{
    public static function apply(LLMProvider $provider, Skill $skill, array &$options): bool
    {
        return false;
    }
}

class TestBridgeProvider implements LLMProvider
{
    public function __construct(private readonly string $name) {}
    public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): Generator { yield from []; }
    public function formatMessages(array $messages): array { return []; }
    public function formatTools(array $tools): array { return []; }
    public function getModel(): string { return ''; }
    public function setModel(string $model): void {}
    public function name(): string { return $this->name; }
}
