<?php

namespace SuperAgent\Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Guardrails\GuardrailsResult;
use SuperAgent\Guardrails\Rules\RuleAction;
use SuperAgent\Guardrails\Context\RateTracker;
use SuperAgent\Guardrails\Context\RuntimeContext;

class GuardrailsEngineTest extends TestCase
{
    private function makeContext(array $overrides = []): RuntimeContext
    {
        return new RuntimeContext(
            toolName: $overrides['toolName'] ?? 'Bash',
            toolInput: $overrides['toolInput'] ?? ['command' => 'ls -la'],
            toolContent: $overrides['toolContent'] ?? '/home/user/project',
            sessionCostUsd: $overrides['sessionCostUsd'] ?? 0.50,
            budgetPct: $overrides['budgetPct'] ?? 10.0,
            turnCount: $overrides['turnCount'] ?? 3,
            maxTurns: $overrides['maxTurns'] ?? 50,
            modelName: $overrides['modelName'] ?? 'claude-sonnet-4-20250514',
            cwd: $overrides['cwd'] ?? '/home/user/project',
            rateTracker: $overrides['rateTracker'] ?? new RateTracker(),
        );
    }

    private function makeEngine(array $config): GuardrailsEngine
    {
        return new GuardrailsEngine(GuardrailsConfig::fromArray($config));
    }

    public function test_no_match_returns_unmatched_result(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'security' => [
                    'priority' => 100,
                    'rules' => [
                        [
                            'name' => 'block_git',
                            'conditions' => ['tool_content' => ['contains' => '.git/']],
                            'action' => 'deny',
                            'message' => 'Git blocked',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $engine->evaluate($this->makeContext());
        $this->assertFalse($result->matched);
    }

    public function test_deny_rule_matches(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'security' => [
                    'priority' => 100,
                    'rules' => [
                        [
                            'name' => 'block_git',
                            'conditions' => ['tool_content' => ['contains' => '.git/']],
                            'action' => 'deny',
                            'message' => 'Git access blocked',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $engine->evaluate($this->makeContext(['toolContent' => '/repo/.git/config']));
        $this->assertTrue($result->matched);
        $this->assertSame(RuleAction::DENY, $result->action);
        $this->assertSame('Git access blocked', $result->message);
        $this->assertSame('security', $result->groupName);
    }

    public function test_priority_ordering(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'low_priority' => [
                    'priority' => 10,
                    'rules' => [
                        [
                            'name' => 'allow_bash',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'allow',
                            'message' => 'Allowed',
                        ],
                    ],
                ],
                'high_priority' => [
                    'priority' => 100,
                    'rules' => [
                        [
                            'name' => 'deny_bash',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'deny',
                            'message' => 'Denied',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $engine->evaluate($this->makeContext());
        $this->assertSame(RuleAction::DENY, $result->action);
        $this->assertSame('high_priority', $result->groupName);
    }

    public function test_disabled_group_is_skipped(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'disabled_group' => [
                    'enabled' => false,
                    'priority' => 100,
                    'rules' => [
                        [
                            'name' => 'deny_all',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'deny',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $engine->evaluate($this->makeContext());
        $this->assertFalse($result->matched);
    }

    public function test_cost_based_rule(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'cost' => [
                    'rules' => [
                        [
                            'name' => 'cost_limit',
                            'conditions' => ['session' => ['cost_usd' => ['gt' => 5.0]]],
                            'action' => 'deny',
                            'message' => 'Too expensive',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($engine->evaluate($this->makeContext())->matched);
        $this->assertTrue($engine->evaluate($this->makeContext(['sessionCostUsd' => 6.0]))->matched);
    }

    public function test_composite_condition_all_of(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'composite' => [
                    'rules' => [
                        [
                            'name' => 'bash_and_expensive',
                            'conditions' => [
                                'all_of' => [
                                    ['tool' => ['name' => 'Bash']],
                                    ['session' => ['cost_usd' => ['gt' => 1.0]]],
                                ],
                            ],
                            'action' => 'ask',
                        ],
                    ],
                ],
            ],
        ]);

        // Cost under threshold
        $this->assertFalse($engine->evaluate($this->makeContext())->matched);

        // Cost over threshold + correct tool
        $result = $engine->evaluate($this->makeContext(['sessionCostUsd' => 2.0]));
        $this->assertTrue($result->matched);
        $this->assertSame(RuleAction::ASK, $result->action);
    }

    public function test_all_matching_mode(): void
    {
        $engine = $this->makeEngine([
            'defaults' => ['evaluation' => 'all_matching'],
            'groups' => [
                'group1' => [
                    'priority' => 100,
                    'rules' => [
                        [
                            'name' => 'rule_a',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'warn',
                        ],
                    ],
                ],
                'group2' => [
                    'priority' => 50,
                    'rules' => [
                        [
                            'name' => 'rule_b',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'log',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $engine->evaluate($this->makeContext());
        $this->assertTrue($result->matched);
        $this->assertCount(2, $result->allMatched);
    }

    public function test_result_converts_to_permission_decision(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'security' => [
                    'rules' => [
                        [
                            'name' => 'deny_test',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'deny',
                            'message' => 'Blocked',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $engine->evaluate($this->makeContext());
        $decision = $result->toPermissionDecision();
        $this->assertNotNull($decision);
        $this->assertSame('deny', $decision->behavior->value);
    }

    public function test_warn_action_returns_null_permission(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'alerts' => [
                    'rules' => [
                        [
                            'name' => 'warn_test',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'warn',
                            'message' => 'Be careful',
                        ],
                    ],
                ],
            ],
        ]);

        $result = $engine->evaluate($this->makeContext());
        $this->assertTrue($result->matched);
        $this->assertNull($result->toPermissionDecision());
    }

    public function test_statistics(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'g1' => [
                    'enabled' => true,
                    'rules' => [
                        ['name' => 'r1', 'conditions' => ['tool' => ['name' => 'X']], 'action' => 'deny'],
                        ['name' => 'r2', 'conditions' => ['tool' => ['name' => 'Y']], 'action' => 'allow'],
                    ],
                ],
                'g2' => [
                    'enabled' => false,
                    'rules' => [
                        ['name' => 'r3', 'conditions' => ['tool' => ['name' => 'Z']], 'action' => 'deny'],
                    ],
                ],
            ],
        ]);

        $stats = $engine->getStatistics();
        $this->assertSame(2, $stats['groups']);
        $this->assertSame(3, $stats['rules']);
        $this->assertSame(1, $stats['enabled_groups']);
    }

    public function test_reload_replaces_rules(): void
    {
        $engine = $this->makeEngine([
            'groups' => [
                'old' => [
                    'rules' => [
                        ['name' => 'old_rule', 'conditions' => ['tool' => ['name' => 'Bash']], 'action' => 'deny'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($engine->evaluate($this->makeContext())->matched);

        // Reload with no matching rules
        $engine->reload(GuardrailsConfig::fromArray([
            'groups' => [
                'new' => [
                    'rules' => [
                        ['name' => 'new_rule', 'conditions' => ['tool' => ['name' => 'Write']], 'action' => 'deny'],
                    ],
                ],
            ],
        ]));

        $this->assertFalse($engine->evaluate($this->makeContext())->matched);
    }
}
