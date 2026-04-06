<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Agent;
use SuperAgent\AgentResult;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

/**
 * Executes E2E scenarios against an Agent and validates results.
 *
 * Usage:
 *   $runner = new ScenarioRunner(['api_key' => '...']);
 *   $result = $runner->run($scenario);
 *   // or
 *   $results = $runner->runAll([$scenario1, $scenario2], ['tag' => 'core']);
 *
 * The runner handles:
 *   1. Temp workspace creation and scenario setup()
 *   2. Agent creation with configured tools
 *   3. Prompt execution
 *   4. Tool usage tracking
 *   5. Multi-dimensional validation (tools, text, custom)
 *   6. Cleanup
 */
class ScenarioRunner
{
    private array $agentConfig;
    private ?string $workspaceDir;

    /** @var array<string, int> Global tool call counts across scenarios */
    private array $globalToolCounts = [];

    /** @var ScenarioResult[] */
    private array $results = [];

    public function __construct(
        array $agentConfig = [],
        ?string $workspaceDir = null,
    ) {
        $this->agentConfig = $agentConfig;
        $this->workspaceDir = $workspaceDir;
    }

    /**
     * Run a single scenario.
     */
    public function run(Scenario $scenario): ScenarioResult
    {
        $start = microtime(true);
        $workspace = null;

        try {
            // 1. Create workspace
            $workspace = $this->createWorkspace($scenario->name);

            // 2. Run setup
            if ($scenario->setup !== null) {
                ($scenario->setup)($workspace);
            }

            // 3. Build tools with tracking
            $toolsUsed = [];
            $tools = $this->buildTrackedTools($scenario->tools, $toolsUsed);

            // 4. Create agent
            $agent = $this->createAgent($scenario, $tools, $workspace);

            // 5. Execute prompt
            $agentResult = $agent->prompt($scenario->prompt);

            $durationMs = (microtime(true) - $start) * 1000;

            // 6. Validate
            $failures = $this->validate($scenario, $agentResult, $toolsUsed, $workspace);

            $result = empty($failures)
                ? ScenarioResult::pass($scenario->name, $agentResult, $toolsUsed, $durationMs)
                : ScenarioResult::fail($scenario->name, $failures, $agentResult, $toolsUsed, $durationMs);

        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            $result = ScenarioResult::error($scenario->name, $e->getMessage(), $durationMs);
        } finally {
            // 7. Cleanup workspace
            if ($workspace !== null && $workspace !== $this->workspaceDir) {
                $this->removeWorkspace($workspace);
            }
        }

        $this->results[] = $result;
        return $result;
    }

    /**
     * Run multiple scenarios, optionally filtered by tag.
     *
     * @param Scenario[] $scenarios
     * @return ScenarioResult[]
     */
    public function runAll(array $scenarios, array $filters = []): array
    {
        $results = [];
        $tagFilter = $filters['tag'] ?? null;

        foreach ($scenarios as $scenario) {
            if ($tagFilter !== null && !$scenario->hasTag($tagFilter)) {
                continue;
            }
            $results[] = $this->run($scenario);
        }

        return $results;
    }

    /**
     * Get summary of all results.
     */
    public function summary(): array
    {
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $totalDuration = 0.0;

        foreach ($this->results as $r) {
            if ($r->error !== null) {
                $errors++;
            } elseif ($r->passed) {
                $passed++;
            } else {
                $failed++;
            }
            $totalDuration += $r->durationMs;
        }

        return [
            'total' => count($this->results),
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
            'total_duration_ms' => round($totalDuration, 2),
            'results' => array_map(fn($r) => $r->toArray(), $this->results),
        ];
    }

    /**
     * Get all results.
     *
     * @return ScenarioResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Clear accumulated results.
     */
    public function clearResults(): void
    {
        $this->results = [];
        $this->globalToolCounts = [];
    }

    // ── Internal ──────────────────────────────────────────────────

    private function createAgent(Scenario $scenario, array $tools, string $workspace): Agent
    {
        $config = array_merge($this->agentConfig, [
            'tools' => $tools,
            'max_turns' => $scenario->maxTurns,
        ]);

        return new Agent($config);
    }

    /**
     * Wrap scenario tools with call-tracking closures.
     *
     * @param  array $scenarioTools  Tool instances or ClosureTool-like configs
     * @param  array &$toolsUsed    Populated with [toolName => callCount]
     * @return array  Wrapped tool instances
     */
    private function buildTrackedTools(array $scenarioTools, array &$toolsUsed): array
    {
        $wrapped = [];

        foreach ($scenarioTools as $tool) {
            if ($tool instanceof \SuperAgent\Contracts\ToolInterface) {
                // Wrap existing tool with tracking
                $name = $tool->name();
                $toolsUsed[$name] = 0;

                $wrapped[] = new ClosureTool(
                    toolName: $name,
                    toolDescription: $tool->description(),
                    toolInputSchema: $tool->inputSchema(),
                    handler: function (array $input) use ($tool, $name, &$toolsUsed): ToolResult {
                        $toolsUsed[$name] = ($toolsUsed[$name] ?? 0) + 1;
                        $this->globalToolCounts[$name] = ($this->globalToolCounts[$name] ?? 0) + 1;
                        return $tool->execute($input);
                    },
                    readOnly: $tool->isReadOnly(),
                );
            } else {
                $wrapped[] = $tool;
            }
        }

        return $wrapped;
    }

    /**
     * Run all validation checks on a scenario result.
     *
     * @return string[] List of failure messages (empty = passed)
     */
    private function validate(
        Scenario $scenario,
        AgentResult $result,
        array $toolsUsed,
        string $workspace,
    ): array {
        $failures = [];

        // Check required tools were used
        foreach ($scenario->requiredTools as $toolName) {
            if (!isset($toolsUsed[$toolName]) || $toolsUsed[$toolName] === 0) {
                $failures[] = "Required tool '{$toolName}' was not called";
            }
        }

        // Check expected text in final output
        if ($scenario->expectedText !== null) {
            $text = $result->text();
            if (!str_contains($text, $scenario->expectedText)) {
                $preview = mb_substr($text, 0, 200);
                $failures[] = "Expected text '{$scenario->expectedText}' not found in output: \"{$preview}\"";
            }
        }

        // Run custom validation closure
        if ($scenario->validate !== null) {
            try {
                $customResult = ($scenario->validate)(
                    $workspace,
                    $result,
                    $toolsUsed,
                );

                if ($customResult === false) {
                    $failures[] = "Custom validation returned false";
                } elseif (is_string($customResult)) {
                    $failures[] = $customResult;
                } elseif (is_array($customResult)) {
                    foreach ($customResult as $msg) {
                        $failures[] = (string) $msg;
                    }
                }
            } catch (\Throwable $e) {
                $failures[] = "Custom validation threw: {$e->getMessage()}";
            }
        }

        return $failures;
    }

    private function createWorkspace(string $scenarioName): string
    {
        if ($this->workspaceDir !== null) {
            $dir = $this->workspaceDir . '/' . $this->sanitize($scenarioName);
        } else {
            $dir = sys_get_temp_dir() . '/superagent_e2e_' . $this->sanitize($scenarioName) . '_' . uniqid();
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function removeWorkspace(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }

    private function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    }
}
