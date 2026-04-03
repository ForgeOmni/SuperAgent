<?php

namespace SuperAgent\Tests\Unit\Guardrails;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\Rules\RuleAction;

class GuardrailsConfigTest extends TestCase
{
    public function test_from_array_minimal(): void
    {
        $config = GuardrailsConfig::fromArray([
            'groups' => [
                'test' => [
                    'rules' => [
                        [
                            'name' => 'rule1',
                            'conditions' => ['tool' => ['name' => 'Bash']],
                            'action' => 'deny',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('1.0', $config->getVersion());
        $this->assertSame('first_match', $config->getEvaluationMode());
        $this->assertCount(1, $config->getGroups());
        $this->assertEmpty($config->validate());
    }

    public function test_defaults_are_parsed(): void
    {
        $config = GuardrailsConfig::fromArray([
            'version' => '2.0',
            'defaults' => [
                'evaluation' => 'all_matching',
                'default_action' => 'deny',
            ],
            'groups' => [],
        ]);

        $this->assertSame('2.0', $config->getVersion());
        $this->assertSame('all_matching', $config->getEvaluationMode());
    }

    public function test_groups_sorted_by_priority(): void
    {
        $config = GuardrailsConfig::fromArray([
            'groups' => [
                'low' => [
                    'priority' => 10,
                    'rules' => [
                        ['name' => 'r1', 'conditions' => ['tool' => ['name' => 'X']], 'action' => 'deny'],
                    ],
                ],
                'high' => [
                    'priority' => 100,
                    'rules' => [
                        ['name' => 'r2', 'conditions' => ['tool' => ['name' => 'X']], 'action' => 'deny'],
                    ],
                ],
            ],
        ]);

        $groups = $config->getGroups();
        $this->assertSame('high', $groups[0]->name);
        $this->assertSame('low', $groups[1]->name);
    }

    public function test_validate_invalid_evaluation_mode(): void
    {
        $config = GuardrailsConfig::fromArray([
            'defaults' => ['evaluation' => 'invalid_mode'],
            'groups' => [],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('evaluation mode', $errors[0]);
    }

    public function test_validate_duplicate_rule_names(): void
    {
        $config = GuardrailsConfig::fromArray([
            'groups' => [
                'test' => [
                    'rules' => [
                        ['name' => 'dup', 'conditions' => ['tool' => ['name' => 'X']], 'action' => 'deny'],
                        ['name' => 'dup', 'conditions' => ['tool' => ['name' => 'Y']], 'action' => 'allow'],
                    ],
                ],
            ],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Duplicate rule name', $errors[0]);
    }

    public function test_validate_downgrade_model_missing_param(): void
    {
        $config = GuardrailsConfig::fromArray([
            'groups' => [
                'test' => [
                    'rules' => [
                        [
                            'name' => 'downgrade',
                            'conditions' => ['session' => ['budget_pct' => ['gt' => 80]]],
                            'action' => 'downgrade_model',
                        ],
                    ],
                ],
            ],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('target_model', $errors[0]);
    }

    public function test_invalid_action_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid action');

        GuardrailsConfig::fromArray([
            'groups' => [
                'test' => [
                    'rules' => [
                        ['name' => 'bad', 'conditions' => ['tool' => ['name' => 'X']], 'action' => 'explode'],
                    ],
                ],
            ],
        ]);
    }

    public function test_missing_rule_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have a name');

        GuardrailsConfig::fromArray([
            'groups' => [
                'test' => [
                    'rules' => [
                        ['conditions' => ['tool' => ['name' => 'X']], 'action' => 'deny'],
                    ],
                ],
            ],
        ]);
    }

    public function test_resolve_template_vars(): void
    {
        $result = GuardrailsConfig::resolveTemplateVars(
            '{{cwd}}/src/file.php',
            ['cwd' => '/home/user/project']
        );
        $this->assertSame('/home/user/project/src/file.php', $result);
    }

    public function test_from_yaml_file_not_found_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GuardrailsConfig::fromYamlFile('/nonexistent/guardrails.yaml');
    }
}
