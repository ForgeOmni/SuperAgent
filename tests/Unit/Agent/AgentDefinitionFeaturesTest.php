<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent\AgentDefinition;

/**
 * Pins the `AgentDefinition::features()` API shape introduced by
 * Improvement #7. Three rules the downstream wiring depends on:
 *
 *   1. Base class default is `null` — definitions that don't opt in
 *      produce byte-exact chat requests (Compat guarantee).
 *   2. When a subclass returns an array, keys are feature names and
 *      values are feature-spec arrays — shape identical to what
 *      `FeatureDispatcher::apply()` consumes from `$options['features']`.
 *   3. The method is non-abstract so every existing `AgentDefinition`
 *      subclass in the codebase keeps working without modification.
 */
class AgentDefinitionFeaturesTest extends TestCase
{
    public function test_default_features_is_null(): void
    {
        $def = new class extends AgentDefinition {
            public function name(): string { return 'default'; }
            public function description(): string { return ''; }
            public function systemPrompt(): ?string { return null; }
        };

        $this->assertNull($def->features());
    }

    public function test_subclass_can_declare_features(): void
    {
        $def = new class extends AgentDefinition {
            public function name(): string { return 'thinking-agent'; }
            public function description(): string { return ''; }
            public function systemPrompt(): ?string { return null; }
            public function features(): ?array
            {
                return [
                    'thinking' => ['budget' => 4000],
                    'agent_teams' => [
                        'objective' => 'Ship the release',
                        'roles' => [['name' => 'lead']],
                    ],
                ];
            }
        };

        $features = $def->features();
        $this->assertIsArray($features);
        $this->assertArrayHasKey('thinking', $features);
        $this->assertSame(4000, $features['thinking']['budget']);
        $this->assertArrayHasKey('agent_teams', $features);
        $this->assertSame('Ship the release', $features['agent_teams']['objective']);
    }

    public function test_existing_subclasses_still_work_without_features_override(): void
    {
        // Builtin agents predate `features()` and don't override it —
        // they must continue to return null (Compat guarantee).
        foreach ([
            \SuperAgent\Agent\BuiltinAgents\PlanAgent::class,
            \SuperAgent\Agent\BuiltinAgents\ResearcherAgent::class,
            \SuperAgent\Agent\BuiltinAgents\ReviewerAgent::class,
            \SuperAgent\Agent\BuiltinAgents\CodeWriterAgent::class,
            \SuperAgent\Agent\BuiltinAgents\VerificationAgent::class,
        ] as $class) {
            if (! class_exists($class)) {
                continue;  // optional builtins
            }
            $instance = new $class();
            $this->assertNull($instance->features(), "{$class}::features() should default to null");
        }
    }
}
