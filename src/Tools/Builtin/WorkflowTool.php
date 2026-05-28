<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Create and run reusable workflows that chain tools/agents into a pipeline.
 *
 * Two workflow shapes are supported:
 *
 *   - **static**  — a fixed, ordered list of steps (the original behavior).
 *   - **dynamic** — Opus 4.8-style workflows whose shape is decided at run
 *     time by a `strategy` plus runtime `guards`. Strategies:
 *       • `sequential`  — steps in order.
 *       • `pipeline`    — each step's output feeds the next (no barrier).
 *       • `parallel` / `fan_out` — steps run concurrently in one wave.
 *       • `loop_until`  — body repeats until `until` holds (capped by
 *                         `max_iterations`).
 *       • `self_paced`  — the model picks the next action each iteration,
 *                         bounded by `max_iterations` / `budget_usd` / `until`.
 *
 * A dynamic workflow can be **planned** offline (`planWorkflow()` expands it
 * into the wave/iteration schedule with no side effects) or **executed** for
 * real by bridging to the existing {@see \SuperAgent\Pipeline\PipelineEngine}
 * via {@see toPipelineConfig()}. The bridge only runs when a host injects an
 * agent runner through {@see setPipelineRunner()}; without one, `run` returns
 * the dry-run expansion so the tool stays useful (and testable) offline.
 */
class WorkflowTool extends Tool
{
    public const TYPE_STATIC = 'static';
    public const TYPE_DYNAMIC = 'dynamic';

    public const STRATEGIES = [
        'sequential', 'pipeline', 'parallel', 'fan_out', 'loop_until', 'self_paced',
    ];

    /** Default iteration cap for loop_until / self_paced when no guard is set. */
    private const DEFAULT_MAX_ITERATIONS = 5;

    /**
     * Optional agent runner used to execute dynamic workflows for real via the
     * PipelineEngine. Signature: fn(AgentStep $step, PipelineContext $ctx): string
     *
     * @var callable|null
     */
    private $pipelineRunner = null;

    public function name(): string
    {
        return 'workflow';
    }

    public function description(): string
    {
        return 'Create and manage reusable static or dynamic (Opus 4.8-style) workflows '
            . 'that combine multiple tools, agents and steps.';
    }

    public function category(): string
    {
        return 'automation';
    }

    /**
     * Inject the agent runner that backs real dynamic-workflow execution.
     * When set, `run` on a dynamic workflow drives the PipelineEngine; when
     * absent it falls back to a dry-run plan expansion.
     */
    public function setPipelineRunner(?callable $runner): void
    {
        $this->pipelineRunner = $runner;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'run', 'plan', 'get', 'list', 'delete'],
                    'description' => 'Workflow action: create, run, plan, get, list, or delete.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Workflow name.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Workflow description.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => [self::TYPE_STATIC, self::TYPE_DYNAMIC],
                    'description' => 'Workflow shape (default static). Dynamic workflows use strategy + guards.',
                ],
                'strategy' => [
                    'type' => 'string',
                    'enum' => self::STRATEGIES,
                    'description' => 'Dynamic execution strategy: sequential, pipeline, parallel, fan_out, loop_until, self_paced.',
                ],
                'guards' => [
                    'type' => 'object',
                    'description' => 'Dynamic guards: max_iterations (int), budget_usd (float), until (condition string).',
                    'properties' => [
                        'max_iterations' => ['type' => 'integer'],
                        'budget_usd' => ['type' => 'number'],
                        'until' => ['type' => 'string'],
                    ],
                ],
                'steps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'tool' => ['type' => 'string'],
                            'agent' => ['type' => 'string'],
                            'prompt' => ['type' => 'string'],
                            'input' => ['type' => 'object'],
                            'model' => ['type' => 'string'],
                            'when' => ['type' => 'string'],
                            'depends_on' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                    'description' => 'Workflow steps (tool steps, agent steps, or nested parallel/loop steps).',
                ],
                'workflow_id' => [
                    'type' => 'integer',
                    'description' => 'Workflow ID (for run/plan/get/delete actions).',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Parameters to pass when running a workflow. Set "execute": false to force a dry-run plan.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create':
                return $this->createWorkflow($input);
            case 'run':
                return $this->runWorkflow($input);
            case 'plan':
                return $this->planAction($input);
            case 'get':
                return $this->getWorkflow($input);
            case 'list':
                return $this->listWorkflows();
            case 'delete':
                return $this->deleteWorkflow($input);
            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    private function createWorkflow(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $steps = $input['steps'] ?? [];
        $type = $input['type'] ?? self::TYPE_STATIC;

        if (empty($name)) {
            return ToolResult::error('Workflow name is required.');
        }

        if (empty($steps)) {
            return ToolResult::error('Workflow must have at least one step.');
        }

        if (! in_array($type, [self::TYPE_STATIC, self::TYPE_DYNAMIC], true)) {
            return ToolResult::error("Invalid workflow type: {$type}. Use 'static' or 'dynamic'.");
        }

        $strategy = null;
        $guards = [];
        if ($type === self::TYPE_DYNAMIC) {
            $strategy = $input['strategy'] ?? 'sequential';
            if (! in_array($strategy, self::STRATEGIES, true)) {
                return ToolResult::error(
                    "Invalid strategy: {$strategy}. One of: " . implode(', ', self::STRATEGIES)
                );
            }
            $guards = $this->normalizeGuards($input['guards'] ?? [], $strategy);
        }

        $workflowId = $this->state()->nextId($this->name());

        $this->state()->putIn($this->name(), 'workflows', $workflowId, [
            'id' => $workflowId,
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'strategy' => $strategy,
            'guards' => $guards,
            'steps' => $steps,
            'created_at' => date('Y-m-d H:i:s'),
            'run_count' => 0,
            'last_run' => null,
        ]);

        $payload = [
            'message' => 'Workflow created',
            'workflow_id' => $workflowId,
            'name' => $name,
            'type' => $type,
            'steps' => count($steps),
        ];
        if ($type === self::TYPE_DYNAMIC) {
            $payload['strategy'] = $strategy;
            $payload['guards'] = $guards;
        }

        return ToolResult::success($payload);
    }

    private function runWorkflow(array $input): ToolResult
    {
        $workflowId = $input['workflow_id'] ?? null;
        $parameters = $input['parameters'] ?? [];

        if ($workflowId === null) {
            return ToolResult::error('Workflow ID is required.');
        }

        $workflows = $this->state()->get($this->name(), 'workflows', []);

        if (!isset($workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        $workflow = $workflows[$workflowId];
        $workflow['run_count']++;
        $workflow['last_run'] = date('Y-m-d H:i:s');
        $this->state()->putIn($this->name(), 'workflows', $workflowId, $workflow);

        // Dynamic workflows either execute via the PipelineEngine bridge (when a
        // runner is injected and execution wasn't explicitly disabled) or return
        // the dry-run expansion so the call is still meaningful offline.
        if (($workflow['type'] ?? self::TYPE_STATIC) === self::TYPE_DYNAMIC) {
            // Mode is caller-chosen, not environment-forced: `execute` true/false
            // overrides the default (which executes when a runner is available).
            $wantExecute = $parameters['execute'] ?? ($this->pipelineRunner !== null);
            if ($wantExecute && $this->pipelineRunner !== null) {
                return $this->executeViaPipeline($workflow, $parameters);
            }
            $requestedButUnavailable = ($parameters['execute'] ?? false) === true
                && $this->pipelineRunner === null;
            return ToolResult::success([
                'message' => $requestedButUnavailable
                    ? 'Execution requested but no agent runner is configured — showing plan instead'
                    : 'Workflow planned (dry run)',
                'workflow_id' => $workflowId,
                'name' => $workflow['name'],
                'mode' => 'plan',
                'plan' => $this->expand($workflow),
            ]);
        }

        // Static workflows keep the original simulated step trace.
        $results = [];
        foreach ($workflow['steps'] as $index => $step) {
            $tool = $step['tool'] ?? ($step['agent'] ?? 'step');
            $results[] = [
                'step' => $index + 1,
                'tool' => $tool,
                'status' => 'simulated',
                'message' => "Would execute {$tool}",
            ];
        }

        return ToolResult::success([
            'message' => 'Workflow executed',
            'workflow_id' => $workflowId,
            'name' => $workflow['name'],
            'steps_executed' => count($workflow['steps']),
            'results' => $results,
        ]);
    }

    /**
     * Expand a workflow into its planned schedule without side effects.
     */
    private function planAction(array $input): ToolResult
    {
        $workflowId = $input['workflow_id'] ?? null;
        if ($workflowId === null) {
            return ToolResult::error('Workflow ID is required.');
        }
        $workflows = $this->state()->get($this->name(), 'workflows', []);
        if (!isset($workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        return ToolResult::success([
            'workflow_id' => $workflowId,
            'name' => $workflows[$workflowId]['name'],
            'plan' => $this->expand($workflows[$workflowId]),
        ]);
    }

    /**
     * Public planner: expand a stored workflow into a structured schedule.
     *
     * @return array<string, mixed>|null null when the id is unknown.
     */
    public function planWorkflow(int $workflowId): ?array
    {
        $workflows = $this->state()->get($this->name(), 'workflows', []);
        if (!isset($workflows[$workflowId])) {
            return null;
        }
        return $this->expand($workflows[$workflowId]);
    }

    /**
     * Translate a stored dynamic (or static) workflow into a PipelineConfig
     * array that {@see \SuperAgent\Pipeline\PipelineConfig::fromArray()} accepts.
     * This is the bridge that lets the real PipelineEngine run a workflow.
     *
     * @return array<string, mixed>|null null when the id is unknown.
     */
    public function toPipelineConfig(int $workflowId): ?array
    {
        $workflows = $this->state()->get($this->name(), 'workflows', []);
        if (!isset($workflows[$workflowId])) {
            return null;
        }
        $workflow = $workflows[$workflowId];

        $pipelineName = 'wf-' . $workflowId;
        return [
            'version' => '1.0',
            'pipelines' => [
                $pipelineName => [
                    'description' => $workflow['description'] ?? '',
                    'steps' => $this->buildPipelineSteps($workflow),
                ],
            ],
        ];
    }

    // ── Dynamic expansion / translation ───────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function expand(array $workflow): array
    {
        $type = $workflow['type'] ?? self::TYPE_STATIC;
        $steps = $workflow['steps'] ?? [];
        $labels = array_map([$this, 'stepLabel'], $steps);

        if ($type === self::TYPE_STATIC) {
            return [
                'type' => self::TYPE_STATIC,
                'strategy' => 'sequential',
                'waves' => array_map(static fn ($l, $i) => sprintf('%d. %s', $i + 1, $l), $labels, array_keys($labels)),
                'estimated_steps' => count($steps),
            ];
        }

        $strategy = $workflow['strategy'] ?? 'sequential';
        $guards = $workflow['guards'] ?? [];
        $maxIter = (int) ($guards['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS);
        $until = $guards['until'] ?? null;
        $budget = $guards['budget_usd'] ?? null;
        $n = count($steps);

        $out = ['type' => self::TYPE_DYNAMIC, 'strategy' => $strategy, 'guards' => $guards];

        switch ($strategy) {
            case 'parallel':
            case 'fan_out':
                $out['waves'] = ['Wave 1 (parallel): ' . implode(' · ', $labels)];
                $out['concurrency'] = $n;
                $out['estimated_steps'] = $n;
                break;

            case 'loop_until':
                $out['waves'] = array_map(static fn ($l, $i) => sprintf('body[%d] %s', $i + 1, $l), $labels, array_keys($labels));
                $out['loop'] = sprintf(
                    'repeat body (%d step%s) up to %d iteration%s%s',
                    $n, $n === 1 ? '' : 's', $maxIter, $maxIter === 1 ? '' : 's',
                    $until ? ", exit when: {$until}" : ''
                );
                $out['estimated_steps'] = $n * $maxIter;
                break;

            case 'self_paced':
                $out['waves'] = array_map(static fn ($l) => "action: {$l}", $labels);
                $out['loop'] = sprintf(
                    'model selects next action each iteration; up to %d iteration%s%s%s',
                    $maxIter, $maxIter === 1 ? '' : 's',
                    $budget !== null ? sprintf(', budget $%.2f', (float) $budget) : '',
                    $until ? ", exit when: {$until}" : ''
                );
                $out['estimated_steps'] = $maxIter;
                break;

            case 'pipeline':
                $out['waves'] = array_map(
                    static fn ($l, $i) => sprintf('%d. %s%s', $i + 1, $l, $i + 1 < count($labels) ? ' → next' : ''),
                    $labels, array_keys($labels)
                );
                $out['estimated_steps'] = $n;
                break;

            case 'sequential':
            default:
                $out['waves'] = array_map(static fn ($l, $i) => sprintf('%d. %s', $i + 1, $l), $labels, array_keys($labels));
                $out['estimated_steps'] = $n;
                break;
        }

        return $out;
    }

    /**
     * Build the PipelineEngine `steps` array for a workflow, honoring its strategy.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPipelineSteps(array $workflow): array
    {
        $steps = $workflow['steps'] ?? [];
        $normalized = [];
        foreach ($steps as $i => $step) {
            $normalized[] = $this->normalizeStep($step, $i);
        }

        $type = $workflow['type'] ?? self::TYPE_STATIC;
        if ($type === self::TYPE_STATIC) {
            return $normalized;
        }

        $strategy = $workflow['strategy'] ?? 'sequential';
        $guards = $workflow['guards'] ?? [];
        $maxIter = (int) ($guards['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS);
        $until = $guards['until'] ?? null;

        switch ($strategy) {
            case 'parallel':
            case 'fan_out':
                return [[
                    'name' => 'fan-out',
                    'parallel' => $normalized,
                    'wait_all' => true,
                ]];

            case 'loop_until':
            case 'self_paced':
                $loop = ['max_iterations' => max(1, $maxIter), 'steps' => $normalized];
                if ($until !== null && $until !== '') {
                    $loop['exit_when'] = [$until];
                }
                return [[
                    'name' => $strategy === 'self_paced' ? 'self-paced-loop' : 'loop',
                    'loop' => $loop,
                ]];

            case 'pipeline':
                // Chain each step onto the previous one's output.
                $prev = null;
                foreach ($normalized as &$ns) {
                    if ($prev !== null) {
                        $ns['depends_on'] = array_values(array_unique(array_merge($ns['depends_on'] ?? [], [$prev])));
                        $ns['input_from'] = array_values(array_unique(array_merge($ns['input_from'] ?? [], [$prev])));
                    }
                    $prev = $ns['name'];
                }
                unset($ns);
                return $normalized;

            case 'sequential':
            default:
                return $normalized;
        }
    }

    /**
     * Normalize a workflow step into a PipelineEngine step definition.
     * Tool steps are wrapped as agent steps so the agent runner can invoke them.
     *
     * @return array<string, mixed>
     */
    private function normalizeStep(array $step, int $index): array
    {
        $name = $step['name'] ?? null;

        // Nested control-flow steps pass through (recursing into their bodies).
        if (isset($step['parallel']) && is_array($step['parallel'])) {
            return [
                'name' => $name ?? ('parallel-' . ($index + 1)),
                'parallel' => array_map(fn ($s, $i) => $this->normalizeStep((array) $s, $i), $step['parallel'], array_keys($step['parallel'])),
                'wait_all' => (bool) ($step['wait_all'] ?? true),
            ];
        }
        if (isset($step['loop']) && is_array($step['loop'])) {
            $body = $step['loop']['steps'] ?? [];
            return [
                'name' => $name ?? ('loop-' . ($index + 1)),
                'loop' => [
                    'max_iterations' => max(1, (int) ($step['loop']['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS)),
                    'exit_when' => (array) ($step['loop']['exit_when'] ?? []),
                    'steps' => array_map(fn ($s, $i) => $this->normalizeStep((array) $s, $i), $body, array_keys($body)),
                ],
            ];
        }

        $out = ['name' => $name ?? $this->stepLabel($step, $index)];

        if (isset($step['agent'])) {
            $out['agent'] = (string) $step['agent'];
            $out['prompt'] = (string) ($step['prompt'] ?? $step['description'] ?? $out['name']);
        } else {
            // Tool step → agent wrapper that invokes the tool.
            $tool = (string) ($step['tool'] ?? 'general');
            $out['agent'] = 'general';
            $inputJson = isset($step['input']) ? json_encode($step['input'], JSON_UNESCAPED_UNICODE) : '{}';
            $out['prompt'] = (string) ($step['prompt'] ?? "Use the `{$tool}` tool with input: {$inputJson}");
        }

        if (isset($step['model'])) {
            $out['model'] = (string) $step['model'];
        }
        if (isset($step['system_prompt'])) {
            $out['system_prompt'] = (string) $step['system_prompt'];
        }
        if (!empty($step['when'])) {
            $out['when'] = (string) $step['when'];
        }
        if (!empty($step['depends_on'])) {
            $out['depends_on'] = (array) $step['depends_on'];
        }

        return $out;
    }

    /**
     * Execute a dynamic workflow for real via the PipelineEngine bridge.
     */
    private function executeViaPipeline(array $workflow, array $parameters): ToolResult
    {
        $configArray = $this->toPipelineConfig((int) $workflow['id']);
        if ($configArray === null) {
            return ToolResult::error('Workflow vanished before execution.');
        }

        try {
            $config = \SuperAgent\Pipeline\PipelineConfig::fromArray($configArray);
            $engine = new \SuperAgent\Pipeline\PipelineEngine($config);
            $engine->setAgentRunner($this->pipelineRunner);
            $result = $engine->run('wf-' . $workflow['id'], is_array($parameters) ? $parameters : []);
        } catch (\Throwable $e) {
            return ToolResult::error('Workflow execution failed: ' . $e->getMessage());
        }

        return ToolResult::success([
            'message' => $result->isSuccessful() ? 'Workflow executed' : 'Workflow finished with errors',
            'workflow_id' => $workflow['id'],
            'name' => $workflow['name'],
            'status' => $result->status->value,
            'duration_ms' => $result->totalDurationMs,
            'error' => $result->error,
            'summary' => $result->getSummary(),
        ]);
    }

    /**
     * @param array<string, mixed> $guards
     * @return array<string, mixed>
     */
    private function normalizeGuards(array $guards, string $strategy): array
    {
        $out = [];
        if (isset($guards['max_iterations'])) {
            $out['max_iterations'] = max(1, (int) $guards['max_iterations']);
        } elseif (in_array($strategy, ['loop_until', 'self_paced'], true)) {
            $out['max_iterations'] = self::DEFAULT_MAX_ITERATIONS;
        }
        if (isset($guards['budget_usd'])) {
            $out['budget_usd'] = (float) $guards['budget_usd'];
        }
        if (isset($guards['until']) && $guards['until'] !== '') {
            $out['until'] = (string) $guards['until'];
        }
        return $out;
    }

    private function stepLabel(array $step, int $index = 0): string
    {
        if (!empty($step['name'])) {
            return (string) $step['name'];
        }
        if (!empty($step['agent'])) {
            return 'agent:' . $step['agent'];
        }
        if (!empty($step['tool'])) {
            return 'tool:' . $step['tool'];
        }
        if (isset($step['parallel'])) {
            return 'parallel(' . count((array) $step['parallel']) . ')';
        }
        if (isset($step['loop'])) {
            return 'loop';
        }
        return 'step-' . ($index + 1);
    }

    private function getWorkflow(array $input): ToolResult
    {
        $workflowId = $input['workflow_id'] ?? null;

        if ($workflowId === null) {
            return ToolResult::error('Workflow ID is required.');
        }

        $workflows = $this->state()->get($this->name(), 'workflows', []);

        if (!isset($workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        return ToolResult::success($workflows[$workflowId]);
    }

    private function listWorkflows(): ToolResult
    {
        $workflows = $this->state()->get($this->name(), 'workflows', []);
        $summary = [];

        foreach ($workflows as $workflow) {
            $row = [
                'id' => $workflow['id'],
                'name' => $workflow['name'],
                'description' => $workflow['description'],
                'type' => $workflow['type'] ?? self::TYPE_STATIC,
                'steps' => count($workflow['steps']),
                'run_count' => $workflow['run_count'],
                'created_at' => $workflow['created_at'],
            ];
            if (($workflow['type'] ?? self::TYPE_STATIC) === self::TYPE_DYNAMIC) {
                $row['strategy'] = $workflow['strategy'] ?? null;
            }
            $summary[] = $row;
        }

        return ToolResult::success([
            'count' => count($summary),
            'workflows' => $summary,
        ]);
    }

    private function deleteWorkflow(array $input): ToolResult
    {
        $workflowId = $input['workflow_id'] ?? null;

        if ($workflowId === null) {
            return ToolResult::error('Workflow ID is required.');
        }

        $workflows = $this->state()->get($this->name(), 'workflows', []);

        if (!isset($workflows[$workflowId])) {
            return ToolResult::error("Workflow {$workflowId} not found.");
        }

        $name = $workflows[$workflowId]['name'];
        $this->state()->removeFrom($this->name(), 'workflows', $workflowId);

        return ToolResult::success([
            'message' => 'Workflow deleted',
            'workflow_id' => $workflowId,
            'name' => $name,
        ]);
    }

    public function clearWorkflows(): void
    {
        $this->state()->clearTool($this->name());
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
