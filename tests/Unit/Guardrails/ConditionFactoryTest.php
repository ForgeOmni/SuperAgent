<?php

namespace SuperAgent\Tests\Unit\Guardrails;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperAgent\Guardrails\Conditions\AgentCondition;
use SuperAgent\Guardrails\Conditions\AllOfCondition;
use SuperAgent\Guardrails\Conditions\AnyOfCondition;
use SuperAgent\Guardrails\Conditions\ConditionFactory;
use SuperAgent\Guardrails\Conditions\NotCondition;
use SuperAgent\Guardrails\Conditions\RateCondition;
use SuperAgent\Guardrails\Conditions\SessionCondition;
use SuperAgent\Guardrails\Conditions\ToolContentCondition;
use SuperAgent\Guardrails\Conditions\ToolInputCondition;
use SuperAgent\Guardrails\Conditions\ToolNameCondition;
use SuperAgent\Guardrails\Context\RateTracker;
use SuperAgent\Guardrails\Context\RuntimeContext;

class ConditionFactoryTest extends TestCase
{
    private ConditionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ConditionFactory();
    }

    private function makeContext(array $overrides = []): RuntimeContext
    {
        return new RuntimeContext(
            toolName: $overrides['toolName'] ?? 'Bash',
            toolInput: $overrides['toolInput'] ?? ['command' => 'ls -la'],
            toolContent: $overrides['toolContent'] ?? '/home/user/project',
            sessionCostUsd: $overrides['sessionCostUsd'] ?? 1.50,
            turnCount: $overrides['turnCount'] ?? 5,
            maxTurns: $overrides['maxTurns'] ?? 50,
            modelName: $overrides['modelName'] ?? 'claude-sonnet-4-20250514',
            elapsedMs: $overrides['elapsedMs'] ?? 30000.0,
            cwd: $overrides['cwd'] ?? '/home/user/project',
            rateTracker: $overrides['rateTracker'] ?? new RateTracker(),
        );
    }

    public function test_parse_tool_name_exact(): void
    {
        $condition = $this->factory->fromArray(['tool' => ['name' => 'Bash']]);
        $this->assertInstanceOf(ToolNameCondition::class, $condition);
        $this->assertTrue($condition->evaluate($this->makeContext()));
        $this->assertFalse($condition->evaluate($this->makeContext(['toolName' => 'Read'])));
    }

    public function test_parse_tool_name_any_of(): void
    {
        $condition = $this->factory->fromArray(['tool' => ['name' => ['any_of' => ['Bash', 'Read']]]]);
        $this->assertInstanceOf(ToolNameCondition::class, $condition);
        $this->assertTrue($condition->evaluate($this->makeContext()));
        $this->assertTrue($condition->evaluate($this->makeContext(['toolName' => 'Read'])));
        $this->assertFalse($condition->evaluate($this->makeContext(['toolName' => 'Write'])));
    }

    public function test_parse_tool_content(): void
    {
        $condition = $this->factory->fromArray(['tool_content' => ['contains' => '.git/']]);
        $this->assertInstanceOf(ToolContentCondition::class, $condition);
        $this->assertFalse($condition->evaluate($this->makeContext()));
        $this->assertTrue($condition->evaluate($this->makeContext(['toolContent' => '/repo/.git/config'])));
    }

    public function test_parse_tool_input(): void
    {
        $condition = $this->factory->fromArray(['tool_input' => ['field' => 'command', 'matches' => 'rm -rf *']]);
        $this->assertFalse($condition->evaluate($this->makeContext()));
        $this->assertTrue($condition->evaluate($this->makeContext(['toolInput' => ['command' => 'rm -rf /tmp']])));
    }

    public function test_parse_session_cost(): void
    {
        $condition = $this->factory->fromArray(['session' => ['cost_usd' => ['gt' => 2.0]]]);
        $this->assertFalse($condition->evaluate($this->makeContext()));
        $this->assertTrue($condition->evaluate($this->makeContext(['sessionCostUsd' => 3.0])));
    }

    public function test_parse_agent(): void
    {
        $condition = $this->factory->fromArray(['agent' => ['turn_count' => ['gt' => 40]]]);
        $this->assertFalse($condition->evaluate($this->makeContext()));
        $this->assertTrue($condition->evaluate($this->makeContext(['turnCount' => 45])));
    }

    public function test_parse_all_of(): void
    {
        $condition = $this->factory->fromArray([
            'all_of' => [
                ['tool' => ['name' => 'Bash']],
                ['session' => ['cost_usd' => ['gt' => 1.0]]],
            ],
        ]);
        $this->assertInstanceOf(AllOfCondition::class, $condition);
        $this->assertTrue($condition->evaluate($this->makeContext()));
        $this->assertFalse($condition->evaluate($this->makeContext(['toolName' => 'Read'])));
    }

    public function test_parse_any_of(): void
    {
        $condition = $this->factory->fromArray([
            'any_of' => [
                ['tool_content' => ['contains' => '.git/']],
                ['tool_content' => ['contains' => '.env']],
            ],
        ]);
        $this->assertInstanceOf(AnyOfCondition::class, $condition);
        $this->assertFalse($condition->evaluate($this->makeContext()));
        $this->assertTrue($condition->evaluate($this->makeContext(['toolContent' => '/app/.env'])));
    }

    public function test_parse_not(): void
    {
        $condition = $this->factory->fromArray([
            'not' => ['tool' => ['name' => 'Bash']],
        ]);
        $this->assertInstanceOf(NotCondition::class, $condition);
        $this->assertFalse($condition->evaluate($this->makeContext()));
        $this->assertTrue($condition->evaluate($this->makeContext(['toolName' => 'Read'])));
    }

    public function test_parse_rate(): void
    {
        $condition = $this->factory->fromArray([
            'rate' => ['window_seconds' => 60, 'max_calls' => 2],
        ]);
        $this->assertInstanceOf(RateCondition::class, $condition);

        $tracker = new RateTracker();
        $tracker->record('Bash');
        $tracker->record('Bash');
        $this->assertTrue($condition->evaluate($this->makeContext(['rateTracker' => $tracker])));
    }

    public function test_multiple_top_level_keys_combined_with_and(): void
    {
        $condition = $this->factory->fromArray([
            'tool' => ['name' => 'Bash'],
            'session' => ['cost_usd' => ['gt' => 1.0]],
        ]);
        $this->assertInstanceOf(AllOfCondition::class, $condition);
        $this->assertTrue($condition->evaluate($this->makeContext()));
    }

    public function test_empty_config_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->fromArray([]);
    }

    public function test_unknown_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->fromArray(['unknown_key' => 'value']);
    }

    public function test_tool_missing_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory->fromArray(['tool' => ['type' => 'bash']]);
    }
}
