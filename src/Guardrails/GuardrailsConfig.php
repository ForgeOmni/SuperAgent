<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

use InvalidArgumentException;
use SuperAgent\Guardrails\Conditions\ConditionFactory;
use SuperAgent\Guardrails\Rules\Rule;
use SuperAgent\Guardrails\Rules\RuleAction;
use SuperAgent\Guardrails\Rules\RuleGroup;
use Symfony\Component\Yaml\Yaml;

class GuardrailsConfig
{
    /** @var RuleGroup[] */
    private array $groups = [];

    private string $evaluationMode = 'first_match';

    private string $defaultAction = 'ask';

    private string $version = '1.0';

    private function __construct() {}

    /**
     * Load guardrails configuration from a YAML file.
     */
    public static function fromYamlFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("Guardrails config file not found: {$path}");
        }

        $data = Yaml::parseFile($path);

        if (!is_array($data)) {
            throw new InvalidArgumentException("Guardrails config file must contain a YAML mapping: {$path}");
        }

        return self::fromArray($data);
    }

    /**
     * Load guardrails configuration from an array.
     */
    public static function fromArray(array $data): self
    {
        $config = new self();

        $config->version = (string) ($data['version'] ?? '1.0');

        if (isset($data['defaults'])) {
            $config->evaluationMode = $data['defaults']['evaluation'] ?? 'first_match';
            $config->defaultAction = $data['defaults']['default_action'] ?? 'ask';
        }

        $conditionFactory = new ConditionFactory();

        if (isset($data['groups']) && is_array($data['groups'])) {
            foreach ($data['groups'] as $groupName => $groupData) {
                $config->groups[] = self::parseGroup($groupName, $groupData, $conditionFactory);
            }

            // Sort groups by priority descending
            usort($config->groups, fn (RuleGroup $a, RuleGroup $b) => $b->priority <=> $a->priority);
        }

        return $config;
    }

    /**
     * Merge multiple config files (later files take precedence for same group names).
     *
     * @param string[] $paths
     */
    public static function fromYamlFiles(array $paths): self
    {
        $merged = [];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $data = Yaml::parseFile($path);
            if (!is_array($data)) {
                continue;
            }

            // Merge groups: later files override same-named groups
            if (isset($data['groups'])) {
                $merged['groups'] = array_merge($merged['groups'] ?? [], $data['groups']);
            }

            // Last file's defaults win
            if (isset($data['defaults'])) {
                $merged['defaults'] = $data['defaults'];
            }

            if (isset($data['version'])) {
                $merged['version'] = $data['version'];
            }
        }

        return self::fromArray($merged);
    }

    /**
     * Validate the configuration and return any errors.
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];

        if (!in_array($this->evaluationMode, ['first_match', 'all_matching'], true)) {
            $errors[] = "Invalid evaluation mode: '{$this->evaluationMode}'. Must be 'first_match' or 'all_matching'.";
        }

        $groupNames = [];
        foreach ($this->groups as $group) {
            if (in_array($group->name, $groupNames, true)) {
                $errors[] = "Duplicate group name: '{$group->name}'";
            }
            $groupNames[] = $group->name;

            $ruleNames = [];
            foreach ($group->rules as $rule) {
                if (in_array($rule->name, $ruleNames, true)) {
                    $errors[] = "Duplicate rule name '{$rule->name}' in group '{$group->name}'";
                }
                $ruleNames[] = $rule->name;

                // Validate action-specific params
                if ($rule->action === RuleAction::DOWNGRADE_MODEL && empty($rule->params['target_model'])) {
                    $errors[] = "Rule '{$rule->name}' uses 'downgrade_model' action but missing 'target_model' param";
                }

                if ($rule->action === RuleAction::PAUSE && !isset($rule->params['duration_seconds'])) {
                    $errors[] = "Rule '{$rule->name}' uses 'pause' action but missing 'duration_seconds' param";
                }
            }
        }

        return $errors;
    }

    /**
     * @return RuleGroup[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    public function getEvaluationMode(): string
    {
        return $this->evaluationMode;
    }

    public function getDefaultAction(): string
    {
        return $this->defaultAction;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Resolve template variables in a string (e.g., {{cwd}}).
     */
    public static function resolveTemplateVars(string $value, array $vars): string
    {
        foreach ($vars as $key => $replacement) {
            $value = str_replace('{{' . $key . '}}', $replacement, $value);
        }

        return $value;
    }

    private static function parseGroup(string $name, array $data, ConditionFactory $factory): RuleGroup
    {
        $rules = [];

        if (isset($data['rules']) && is_array($data['rules'])) {
            foreach ($data['rules'] as $ruleData) {
                $rules[] = self::parseRule($ruleData, $factory);
            }
        }

        return new RuleGroup(
            name: $name,
            rules: $rules,
            priority: (int) ($data['priority'] ?? 0),
            enabled: (bool) ($data['enabled'] ?? true),
            description: $data['description'] ?? null,
        );
    }

    private static function parseRule(array $data, ConditionFactory $factory): Rule
    {
        if (!isset($data['name'])) {
            throw new InvalidArgumentException('Each rule must have a name');
        }

        if (!isset($data['conditions'])) {
            throw new InvalidArgumentException("Rule '{$data['name']}' must have conditions");
        }

        if (!isset($data['action'])) {
            throw new InvalidArgumentException("Rule '{$data['name']}' must have an action");
        }

        $action = RuleAction::tryFrom($data['action']);
        if ($action === null) {
            throw new InvalidArgumentException(
                "Rule '{$data['name']}' has invalid action: '{$data['action']}'. "
                . 'Valid actions: ' . implode(', ', array_column(RuleAction::cases(), 'value'))
            );
        }

        return new Rule(
            name: $data['name'],
            condition: $factory->fromArray($data['conditions']),
            action: $action,
            message: $data['message'] ?? null,
            description: $data['description'] ?? null,
            params: $data['params'] ?? [],
        );
    }
}
