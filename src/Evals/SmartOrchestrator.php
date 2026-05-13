<?php

declare(strict_types=1);

namespace SuperAgent\Evals;

use SuperAgent\Config\ConfigRepository;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\CostCalculator;
use SuperAgent\Exceptions\BudgetExceededException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;
use Symfony\Component\Process\Process;

/**
 * "Smart" mode — eval-score-driven task orchestration.
 *
 * Pipeline:
 *   1. Pick the BRAIN model (highest `overall` from ScoreCatalog, or a
 *      configured fallback). The brain handles planning + merging.
 *   2. Brain produces a JSON plan describing complexity, primary dim,
 *      concurrency (serial/parallel), and 1..N subtasks tagged with
 *      difficulty (easy/hard) and dim.
 *   3. For each subtask the orchestrator routes to:
 *        - hard   → `ScoreCatalog::bestModelFor($dim)`  (top by score)
 *        - easy   → `ScoreCatalog::cheapestPassingFor($dim, threshold)`
 *      Both fall back to the brain when scores are missing.
 *   4. Execution depends on the plan's `concurrency` flag:
 *        - serial   → subtasks run one after another in this PHP process;
 *                     each one sees prior outputs as context.
 *        - parallel → real OS-level concurrency. We spawn one
 *                     `superagent _subtask` subprocess per subtask via
 *                     Symfony Process, fire them all at once, and wait
 *                     for completion. Each subprocess sees only the
 *                     original task as context (no sibling outputs).
 *                     Providers' synchronous SSE pipes are sidestepped
 *                     by isolating each call in its own process — N
 *                     curl connections run truly in parallel against
 *                     N endpoints. Falls back to serial-in-process when
 *                     symfony/process is missing or the bin entry can't
 *                     be located.
 *   5. Brain consolidates outputs into a final answer.
 *
 * The full run (plan + outputs + routing decisions + costs) is persisted to
 * `~/.superagent/smart_runs/<ISO>_<shortid>.json` for debugging and replay.
 *
 * Distinct from existing `AutoMode/AutoModeAgent`: that one uses keyword
 * heuristics and runs full sub-Agents in background. SmartOrchestrator is
 * lighter (one HTTP call per subtask, no tool loop) and reads scores from
 * `model_scores.json`.
 *
 * @phpstan-type Plan array{
 *   complexity: string,
 *   primary_dim: string,
 *   concurrency: string,
 *   subtasks: list<array{id:string, prompt:string, difficulty:string, dim:string}>
 * }
 */
class SmartOrchestrator
{
    public const DEFAULT_EASY_THRESHOLD = 0.6;
    public const DEFAULT_MAX_PARALLEL = 4;

    private const KNOWN_DIMS = ['coding', 'reasoning', 'json_mode', 'instruction_following'];

    /** @var callable(array<string, mixed>): void|null */
    private $onEvent;

    /** @var callable(string): void|null */
    private $onMergeDelta;

    /**
     * @param ?float $maxCostUsd  Abort the run when running total cost reaches this ceiling.
     *                            Null = uncapped (legacy behavior). The check fires after the
     *                            plan + each subtask + the merge — it cannot interrupt an
     *                            in-flight HTTP call, so the effective spend can briefly exceed
     *                            the cap by at most one model call.
     * @param int  $maxParallel   Subprocess fan-out ceiling for parallel mode. The orchestrator
     *                            keeps at most `maxParallel` workers alive at once; remaining
     *                            subtasks are queued and started as siblings finish. Set to 0
     *                            for unlimited (matches the pre-guardrail behavior).
     */
    public function __construct(
        private ScoreCatalog $catalog,
        private ?string $brainOverride = null,
        private float $easyThreshold = self::DEFAULT_EASY_THRESHOLD,
        ?callable $onEvent = null,
        private ?string $runLogDir = null,
        private ?float $maxCostUsd = null,
        private int $maxParallel = self::DEFAULT_MAX_PARALLEL,
        ?callable $onMergeDelta = null,
    ) {
        $this->onEvent = $onEvent;
        $this->onMergeDelta = $onMergeDelta;
        $this->runLogDir ??= self::defaultRunLogDir();

        // Validate the brain override eagerly — a typo'd model name should fail at
        // construction time, not after we've already paid for planning.
        if ($this->brainOverride !== null && $this->brainOverride !== '') {
            if (ModelCatalog::model($this->brainOverride) === null) {
                throw new \InvalidArgumentException(
                    "Brain model '{$this->brainOverride}' is not in the catalog. "
                    . "Run `superagent models list` to see available ids."
                );
            }
        }
    }

    public static function defaultRunLogDir(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return rtrim($home, "/\\") . DIRECTORY_SEPARATOR . '.superagent' . DIRECTORY_SEPARATOR . 'smart_runs';
    }

    /**
     * Pick the brain model.
     *   1. explicit constructor override wins
     *   2. otherwise highest `overall` score in the catalog
     *   3. otherwise `superagent.default_model` config, namespaced to the default provider
     *   4. otherwise `claude-opus-4-7` as a last-resort hardcode (it'll fail loudly
     *      if not configured, which is the right behavior)
     */
    public function pickBrain(): string
    {
        if ($this->brainOverride !== null && $this->brainOverride !== '') {
            return $this->brainOverride;
        }
        $best = $this->catalog->bestByOverall();
        if ($best !== null) {
            return $best;
        }
        $cfg = ConfigRepository::getInstance();
        $configured = $cfg->get('superagent.default_model');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        return 'claude-opus-4-7';
    }

    /**
     * End-to-end run. Returns the merged final answer plus full route trace.
     *
     * @return array{
     *   final: string,
     *   plan: Plan,
     *   brain: string,
     *   subtask_results: list<array<string, mixed>>,
     *   total_cost_usd: float,
     *   total_latency_ms: int,
     *   run_log_path: ?string
     * }
     */
    public function run(string $task): array
    {
        $brain = $this->pickBrain();
        $this->emit(['type' => 'brain_picked', 'model' => $brain]);

        $brainProvider = $this->buildProvider($brain);

        $this->emit(['type' => 'planning_started']);
        [$plan, $planRaw, $planUsage] = $this->plan($brainProvider, $task);
        $this->emit(['type' => 'plan', 'plan' => $plan]);

        $totalCost = $this->costOf($brain, $planUsage);
        $startedAll = microtime(true);
        $this->assertBudget($totalCost, 'planning');

        if (
            $plan['concurrency'] === 'parallel'
            && count($plan['subtasks']) > 1
            && self::canSpawnSubprocesses()
        ) {
            $subtaskResults = $this->executeParallel($task, $plan, $brain, $totalCost);
        } else {
            $subtaskResults = $this->executeSerial($task, $plan, $brain, $totalCost);
        }
        foreach ($subtaskResults as $r) {
            $totalCost += (float) ($r['cost_usd'] ?? 0.0);
        }

        // Skip merge when there's only one subtask — the output is already the answer.
        if (count($subtaskResults) === 1) {
            $final = $subtaskResults[0]['output'];
            $this->emit(['type' => 'merge_skipped', 'reason' => 'single subtask']);
        } else {
            $this->assertBudget($totalCost, 'pre-merge');
            $this->emit(['type' => 'merging_started']);
            [$final, $mergeUsage] = $this->merge($brainProvider, $task, $plan, $subtaskResults);
            $totalCost += $this->costOf($brain, $mergeUsage);
        }

        $totalLatency = (int) round((microtime(true) - $startedAll) * 1000);

        $result = [
            'final'             => $final,
            'plan'              => $plan,
            'brain'             => $brain,
            'subtask_results'   => $subtaskResults,
            'total_cost_usd'    => round($totalCost, 6),
            'total_latency_ms'  => $totalLatency,
            'run_log_path'      => null,
        ];

        $logPath = $this->persistRun($task, $result, ['plan_raw' => $planRaw]);
        $result['run_log_path'] = $logPath;
        $this->emit(['type' => 'run_persisted', 'path' => $logPath]);

        return $result;
    }

    /**
     * Produce a plan without executing subtasks. Useful for `--dry-run`.
     *
     * @return Plan
     */
    public function planOnly(string $task): array
    {
        $brain = $this->pickBrain();
        [$plan] = $this->plan($this->buildProvider($brain), $task);
        return $plan;
    }

    /**
     * Re-execute a previously persisted plan without re-asking the brain.
     *
     * Useful for A/B routing experiments — change `$brainOverride` or
     * `$easyThreshold` between runs and replay the same plan to compare
     * outputs without paying for planning again. Returns the same shape as
     * `run()`.
     *
     * @param Plan $plan
     * @return array{
     *   final: string,
     *   plan: Plan,
     *   brain: string,
     *   subtask_results: list<array<string, mixed>>,
     *   total_cost_usd: float,
     *   total_latency_ms: int,
     *   run_log_path: ?string
     * }
     */
    public function replayFromPlan(string $task, array $plan): array
    {
        $brain = $this->pickBrain();
        $this->emit(['type' => 'brain_picked', 'model' => $brain]);
        $this->emit(['type' => 'plan_replayed', 'plan' => $plan]);

        $brainProvider = $this->buildProvider($brain);
        $totalCost = 0.0;
        $startedAll = microtime(true);

        if (
            $plan['concurrency'] === 'parallel'
            && count($plan['subtasks']) > 1
            && self::canSpawnSubprocesses()
        ) {
            $subtaskResults = $this->executeParallel($task, $plan, $brain, $totalCost);
        } else {
            $subtaskResults = $this->executeSerial($task, $plan, $brain, $totalCost);
        }
        foreach ($subtaskResults as $r) {
            $totalCost += (float) ($r['cost_usd'] ?? 0.0);
        }

        if (count($subtaskResults) === 1) {
            $final = $subtaskResults[0]['output'];
            $this->emit(['type' => 'merge_skipped', 'reason' => 'single subtask']);
        } else {
            $this->assertBudget($totalCost, 'pre-merge');
            $this->emit(['type' => 'merging_started']);
            [$final, $mergeUsage] = $this->merge($brainProvider, $task, $plan, $subtaskResults);
            $totalCost += $this->costOf($brain, $mergeUsage);
        }

        $totalLatency = (int) round((microtime(true) - $startedAll) * 1000);
        $result = [
            'final'             => $final,
            'plan'              => $plan,
            'brain'             => $brain,
            'subtask_results'   => $subtaskResults,
            'total_cost_usd'    => round($totalCost, 6),
            'total_latency_ms'  => $totalLatency,
            'run_log_path'      => null,
        ];
        $logPath = $this->persistRun($task, $result, ['plan_raw' => '(replayed)']);
        $result['run_log_path'] = $logPath;
        $this->emit(['type' => 'run_persisted', 'path' => $logPath]);
        return $result;
    }

    // --- Internals ------------------------------------------------------

    /**
     * Ask the brain for a plan. We try once at the model's default temperature,
     * and — if the result doesn't parse into a valid Plan — retry once with a
     * sharper "JSON only, no prose" reminder before falling back to a single-
     * subtask plan. The retry has caught real cases where the brain prepends a
     * sentence of preamble despite the system prompt's instructions.
     *
     * @return array{0: Plan, 1: string, 2: ?Usage}
     */
    private function plan(LLMProvider $brain, string $task): array
    {
        $system = $this->plannerSystemPrompt();
        $user = $this->plannerUserPrompt($task);

        [$rawFirst, $usageFirst] = $this->callPlanner($brain, $system, $user);
        $plan = $this->tryParsePlan($rawFirst);
        if ($plan !== null) {
            return [$plan, $rawFirst, $usageFirst];
        }

        $this->emit(['type' => 'plan_retry', 'reason' => 'first response did not parse']);
        [$rawSecond, $usageSecond] = $this->callPlanner(
            $brain,
            $system . "\n\nREMINDER: Reply with ONLY the JSON object — no preamble, no markdown fences, no trailing commentary.",
            $user,
        );
        $plan = $this->tryParsePlan($rawSecond);
        if ($plan !== null) {
            // Combine usage across both attempts so the cost ledger is honest.
            return [$plan, $rawSecond, $this->mergeUsage($usageFirst, $usageSecond)];
        }

        // Last resort — a single-subtask plan so the run still completes.
        return [$this->fallbackPlan($task), $rawSecond, $this->mergeUsage($usageFirst, $usageSecond)];
    }

    /**
     * @return array{0: string, 1: ?Usage}
     */
    private function callPlanner(LLMProvider $brain, string $system, string $user): array
    {
        $messages = [new UserMessage($user)];
        $final = null;
        foreach ($brain->chat($messages, [], $system, ['max_tokens' => 1500]) as $chunk) {
            if ($chunk instanceof AssistantMessage) {
                $final = $chunk;
            }
        }
        return [$final?->text() ?? '', $final?->usage];
    }

    /**
     * Strict parse: returns null when the input fails the Plan schema. Use
     * this in places where we want to *decide* whether to retry — unlike
     * `parsePlan()` which always returns a Plan by silently substituting a
     * fallback.
     *
     * @return Plan|null
     */
    private function tryParsePlan(string $raw): ?array
    {
        $decoded = json_decode(trim($raw), true);
        if (! is_array($decoded) && preg_match('/(\{.*\})/s', $raw, $m)) {
            $decoded = json_decode($m[1], true);
        }
        if (! is_array($decoded)) {
            return null;
        }
        $rawSubs = is_array($decoded['subtasks'] ?? null) ? $decoded['subtasks'] : [];
        $hasValidSubtask = false;
        foreach ($rawSubs as $st) {
            if (is_array($st) && trim((string) ($st['prompt'] ?? '')) !== '') {
                $hasValidSubtask = true;
                break;
            }
        }
        if (! $hasValidSubtask) {
            return null;
        }
        // Delegate normalisation to the legacy parser, now that we know it'll succeed.
        return $this->parsePlan($raw, '');
    }

    private function mergeUsage(?Usage $a, ?Usage $b): ?Usage
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        $cacheCreate = ($a->cacheCreationInputTokens ?? 0) + ($b->cacheCreationInputTokens ?? 0);
        $cacheRead = ($a->cacheReadInputTokens ?? 0) + ($b->cacheReadInputTokens ?? 0);
        return new Usage(
            $a->inputTokens + $b->inputTokens,
            $a->outputTokens + $b->outputTokens,
            $cacheCreate > 0 ? $cacheCreate : null,
            $cacheRead > 0 ? $cacheRead : null,
        );
    }

    private function plannerSystemPrompt(): string
    {
        return <<<TXT
You are a task planner. Analyze the user task and produce a structured execution plan.

Reply with ONLY a JSON object — no prose, no markdown fences.

Schema:
{
  "complexity": "simple" | "complex",
  "primary_dim": "coding" | "reasoning" | "json_mode" | "instruction_following",
  "concurrency": "serial" | "parallel",
  "subtasks": [
    {
      "id": "1",
      "prompt": "<self-contained instructions for this subtask>",
      "difficulty": "easy" | "hard",
      "dim": "<one of the primary_dim values>"
    }
  ]
}

Rules:
- SIMPLE task: return exactly ONE subtask whose prompt is the full original task.
- COMPLEX task: produce 2-5 subtasks. Mark a subtask "easy" only if it's genuinely
  routine (formatting, glue, mechanical extraction). Reserve "hard" for parts that
  require careful reasoning, architecture decisions, or domain knowledge.
- Use "serial" concurrency when later subtasks depend on the output of earlier
  ones. Use "parallel" only when subtasks are truly independent — they will be
  given the original task as context but NOT each other's outputs.
- Subtask prompts MUST be self-contained — a fresh model with no chat history
  will read them.
TXT;
    }

    private function plannerUserPrompt(string $task): string
    {
        return "USER TASK:\n" . $task;
    }

    /**
     * Robust JSON extraction + schema coercion. Defaults the plan to a
     * single-subtask "simple" plan when parsing fails — better to fall back
     * to a clean single-shot than crash the whole orchestrator.
     *
     * @return Plan
     */
    private function parsePlan(string $raw, string $task): array
    {
        $decoded = json_decode(trim($raw), true);
        if (! is_array($decoded) && preg_match('/(\{.*\})/s', $raw, $m)) {
            $decoded = json_decode($m[1], true);
        }
        if (! is_array($decoded)) {
            return $this->fallbackPlan($task);
        }

        $complexity = $this->oneOf($decoded['complexity'] ?? null, ['simple', 'complex'], 'simple');
        $primaryDim = $this->oneOf($decoded['primary_dim'] ?? null, self::KNOWN_DIMS, 'reasoning');
        $concurrency = $this->oneOf($decoded['concurrency'] ?? null, ['serial', 'parallel'], 'serial');

        $subtasks = [];
        $rawSubs = is_array($decoded['subtasks'] ?? null) ? $decoded['subtasks'] : [];
        foreach ($rawSubs as $i => $st) {
            if (! is_array($st)) {
                continue;
            }
            $prompt = trim((string) ($st['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $subtasks[] = [
                'id'         => (string) ($st['id'] ?? (string) ($i + 1)),
                'prompt'     => $prompt,
                'difficulty' => $this->oneOf($st['difficulty'] ?? null, ['easy', 'hard'], 'hard'),
                'dim'        => $this->oneOf($st['dim'] ?? null, self::KNOWN_DIMS, $primaryDim),
            ];
        }
        if (empty($subtasks)) {
            return $this->fallbackPlan($task);
        }

        return [
            'complexity'  => $complexity,
            'primary_dim' => $primaryDim,
            'concurrency' => $concurrency,
            'subtasks'    => $subtasks,
        ];
    }

    /** @return Plan */
    private function fallbackPlan(string $task): array
    {
        return [
            'complexity'  => 'simple',
            'primary_dim' => 'reasoning',
            'concurrency' => 'serial',
            'subtasks'    => [[
                'id' => '1', 'prompt' => $task, 'difficulty' => 'hard', 'dim' => 'reasoning',
            ]],
        ];
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($v, $allowed, true) ? $v : $default;
    }

    /**
     * Sequential in-process execution. Each subtask sees prior outputs as
     * context — this is what `concurrency=serial` semantically means.
     *
     * `$runningCost` is the cost accrued *before* this method runs (planning,
     * usually). We add each subtask's cost into it and let `assertBudget()`
     * tear the run down with a `BudgetExceededException` if the cap is hit —
     * caller catches that and surfaces a partial-results error.
     *
     * @param Plan $plan
     * @return list<array<string, mixed>>
     */
    private function executeSerial(string $task, array $plan, string $brain, float $runningCost): array
    {
        $results = [];
        $priorOutputs = [];
        foreach ($plan['subtasks'] as $st) {
            $modelId = $this->routeSubtask($st, $brain);
            $this->emit([
                'type' => 'subtask_routed', 'id' => $st['id'], 'model' => $modelId,
                'difficulty' => $st['difficulty'], 'dim' => $st['dim'],
            ]);

            $stPrompt = $this->renderSubtaskPrompt($st, $task, $priorOutputs);

            $started = microtime(true);
            try {
                [$output, $usage] = $this->oneShot($this->buildProvider($modelId), $stPrompt);
            } catch (\Throwable $e) {
                $output = '[subtask failed: ' . $e->getMessage() . ']';
                $usage = null;
            }
            $latency = (int) round((microtime(true) - $started) * 1000);
            $cost = $this->costOf($modelId, $usage);

            $results[] = [
                'id' => $st['id'], 'prompt' => $st['prompt'], 'difficulty' => $st['difficulty'],
                'dim' => $st['dim'], 'model' => $modelId, 'output' => $output,
                'latency_ms' => $latency, 'cost_usd' => $cost,
            ];
            $priorOutputs[] = ['id' => $st['id'], 'output' => $output];
            $this->emit(['type' => 'subtask_done', 'id' => $st['id'], 'latency_ms' => $latency, 'cost_usd' => $cost]);

            $runningCost += $cost;
            $this->assertBudget($runningCost, "subtask {$st['id']}");
        }
        return $results;
    }

    /**
     * Real OS-level concurrent execution.
     *
     * Each subtask becomes a `superagent _subtask` subprocess fed via stdin.
     * Subtasks are dispatched through a sliding window of size `$maxParallel`
     * — we start up to that many workers immediately, then start a new one
     * each time an existing one finishes. This gives bounded fan-out: 50
     * subtasks with maxParallel=4 won't hammer the provider with 50 concurrent
     * curls. Setting `$maxParallel` to 0 disables the cap (legacy behavior).
     *
     * Parallel subprocesses don't see each other's outputs — only the
     * original task — matching the documented semantics of
     * `concurrency=parallel`. The brain merges everything at the end.
     *
     * @param Plan $plan
     * @return list<array<string, mixed>>
     */
    private function executeParallel(string $task, array $plan, string $brain, float $runningCost): array
    {
        $bin = self::findBinEntry();
        $php = self::findPhpBinary();
        if ($bin === null || $php === null) {
            $this->emit(['type' => 'parallel_fallback', 'reason' => 'bin/superagent or php binary not found']);
            return $this->executeSerial($task, $plan, $brain, $runningCost);
        }

        // Pre-route every subtask up front so we don't recompute on each batch.
        $stByStId = [];
        $modelByStId = [];
        foreach ($plan['subtasks'] as $st) {
            $modelId = $this->routeSubtask($st, $brain);
            $stByStId[$st['id']] = $st;
            $modelByStId[$st['id']] = $modelId;
            $this->emit([
                'type' => 'subtask_routed', 'id' => $st['id'], 'model' => $modelId,
                'difficulty' => $st['difficulty'], 'dim' => $st['dim'],
            ]);
        }

        $queue = array_map(fn ($st) => $st['id'], $plan['subtasks']);
        $cap = $this->maxParallel > 0 ? $this->maxParallel : PHP_INT_MAX;
        $results = [];
        /** @var array<string, array{process: Process, started: float}> */
        $running = [];

        $startOne = function (string $stId) use (&$running, $php, $bin, $stByStId, $modelByStId, $task): void {
            $st = $stByStId[$stId];
            $modelId = $modelByStId[$stId];
            $stPrompt = $this->renderSubtaskPrompt($st, $task, []);
            $payload = json_encode([
                'model' => $modelId, 'prompt' => $stPrompt, 'system' => null, 'max_tokens' => 4000,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                $this->emit(['type' => 'subtask_error', 'id' => $stId, 'error' => 'failed to encode subtask payload']);
                return;
            }
            $process = new Process([$php, $bin, '_subtask']);
            $process->setInput($payload);
            $process->setTimeout(null);
            $process->start();
            $running[$stId] = ['process' => $process, 'started' => microtime(true)];
        };

        // Prime the window.
        while (! empty($queue) && count($running) < $cap) {
            $startOne(array_shift($queue));
        }
        $this->emit(['type' => 'parallel_started', 'count' => count($running), 'cap' => $this->maxParallel]);

        while (! empty($running)) {
            // Poll all running processes for completion. usleep avoids burning CPU
            // — 50ms is a good balance between latency and load.
            $finishedStId = null;
            foreach ($running as $stId => $bundle) {
                if ($bundle['process']->isTerminated()) {
                    $finishedStId = $stId;
                    break;
                }
            }
            if ($finishedStId === null) {
                usleep(50_000);
                continue;
            }

            $bundle = $running[$finishedStId];
            unset($running[$finishedStId]);
            $proc = $bundle['process'];
            $latency = (int) round((microtime(true) - $bundle['started']) * 1000);
            $stdout = $proc->getOutput();
            $stderr = $proc->getErrorOutput();
            $st = $stByStId[$finishedStId];
            $modelId = $modelByStId[$finishedStId];

            $decoded = json_decode(trim($stdout), true);
            if (! is_array($decoded) || ! ($decoded['ok'] ?? false)) {
                $err = is_array($decoded)
                    ? (string) ($decoded['error'] ?? 'subprocess returned non-ok envelope')
                    : ('subprocess output unparseable; stderr: ' . trim($stderr));
                $results[$finishedStId] = [
                    'id' => $finishedStId, 'prompt' => $st['prompt'], 'difficulty' => $st['difficulty'],
                    'dim' => $st['dim'], 'model' => $modelId,
                    'output' => '[subtask failed: ' . $err . ']',
                    'latency_ms' => $latency, 'cost_usd' => 0.0,
                ];
                $this->emit(['type' => 'subtask_error', 'id' => $finishedStId, 'error' => $err]);
            } else {
                $cost = (float) ($decoded['cost_usd'] ?? 0.0);
                $results[$finishedStId] = [
                    'id' => $finishedStId, 'prompt' => $st['prompt'], 'difficulty' => $st['difficulty'],
                    'dim' => $st['dim'], 'model' => $modelId,
                    'output' => (string) ($decoded['output'] ?? ''),
                    'latency_ms' => (int) ($decoded['latency_ms'] ?? $latency),
                    'cost_usd' => $cost,
                ];
                $this->emit([
                    'type' => 'subtask_done', 'id' => $finishedStId,
                    'latency_ms' => (int) ($decoded['latency_ms'] ?? $latency),
                    'cost_usd' => $cost,
                ]);
                $runningCost += $cost;
            }

            // Budget check: if we've blown the cap, kill the rest and bail with a
            // partial-results error. The caller surfaces this as a normal failure.
            try {
                $this->assertBudget($runningCost, "subtask {$finishedStId}");
            } catch (BudgetExceededException $e) {
                foreach ($running as $remStId => $remBundle) {
                    try { $remBundle['process']->stop(0); } catch (\Throwable) {}
                    $this->emit(['type' => 'subtask_cancelled', 'id' => $remStId, 'reason' => 'budget exceeded']);
                }
                throw $e;
            }

            // Slide the window forward.
            if (! empty($queue)) {
                $startOne(array_shift($queue));
            }
        }

        // Restore the plan's subtask order — finishes come in arbitrary order.
        $ordered = [];
        foreach ($plan['subtasks'] as $st) {
            if (isset($results[$st['id']])) {
                $ordered[] = $results[$st['id']];
            }
        }
        return $ordered;
    }

    /**
     * Symfony Process is the only hard requirement for parallel execution.
     * Listed as a dev/suggest dep — gracefully fall back to serial if missing.
     */
    private static function canSpawnSubprocesses(): bool
    {
        return class_exists(Process::class);
    }

    /**
     * Locate the `bin/superagent` entry. In standalone installs this lives
     * at the package root; when installed as a Composer dep, the vendor/bin
     * symlink works too — but we point at the package's own script so
     * autoloading is consistent.
     */
    private static function findBinEntry(): ?string
    {
        $candidates = [
            // Inside the package (standalone or vendored).
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'superagent',
            // The current entry script the parent process was launched with.
            $_SERVER['SCRIPT_FILENAME'] ?? '',
            $_SERVER['argv'][0] ?? '',
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    private static function findPhpBinary(): ?string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }
        return null;
    }

    /**
     * Pick the model for a subtask, with sensible fallbacks. When the catalog
     * is empty (no evals run yet) the brain handles every subtask itself —
     * smart mode degrades gracefully into "run task on the brain".
     *
     * @param array{difficulty:string, dim:string} $st
     */
    private function routeSubtask(array $st, string $brain): string
    {
        $picked = $st['difficulty'] === 'hard'
            ? $this->catalog->bestModelFor($st['dim'])
            : $this->catalog->cheapestPassingFor($st['dim'], $this->easyThreshold);
        if ($picked !== null) {
            return $picked;
        }
        // Easy fell through threshold? Try the dim's top scorer.
        $fallback = $this->catalog->bestModelFor($st['dim']);
        return $fallback ?? $brain;
    }

    /**
     * @param array{id:string, prompt:string, difficulty:string, dim:string} $st
     * @param list<array{id:string, output:string}> $priorOutputs
     */
    private function renderSubtaskPrompt(array $st, string $originalTask, array $priorOutputs): string
    {
        $parts = [];
        $parts[] = "ORIGINAL TASK (for context):\n" . $originalTask;
        if (! empty($priorOutputs)) {
            $parts[] = "PRIOR SUBTASK OUTPUTS:";
            foreach ($priorOutputs as $p) {
                $parts[] = "[" . $p['id'] . "]\n" . $p['output'];
            }
        }
        $parts[] = "YOUR SUBTASK (id=" . $st['id'] . "):\n" . $st['prompt'];
        return implode("\n\n", $parts);
    }

    /**
     * @param list<array<string, mixed>> $subtaskResults
     * @return array{0: string, 1: ?Usage}
     */
    private function merge(LLMProvider $brain, string $task, array $plan, array $subtaskResults): array
    {
        $system = <<<TXT
You are consolidating multi-part outputs into a single coherent answer.
Integrate the parts naturally — don't preserve "Part N" headers unless they
genuinely help the reader. If parts conflict, prefer the harder-difficulty
output. Match the format the user originally asked for.
TXT;

        $parts = ["ORIGINAL USER TASK:\n" . $task, "PLAN: " . json_encode([
            'complexity' => $plan['complexity'],
            'primary_dim' => $plan['primary_dim'],
            'concurrency' => $plan['concurrency'],
        ], JSON_UNESCAPED_SLASHES)];
        foreach ($subtaskResults as $sr) {
            $parts[] = sprintf(
                "[Part %s] difficulty=%s, dim=%s, model=%s\n%s",
                $sr['id'], $sr['difficulty'], $sr['dim'], $sr['model'], $sr['output'],
            );
        }
        $parts[] = 'FINAL ANSWER:';

        $messages = [new UserMessage(implode("\n\n", $parts))];
        $final = null;
        $lastEmitted = '';
        foreach ($brain->chat($messages, [], $system, ['max_tokens' => 4000]) as $chunk) {
            if ($chunk instanceof AssistantMessage) {
                $final = $chunk;
                // Emit only the delta since the previous chunk so callers can
                // render a streaming view. Providers yield cumulative messages,
                // so we diff against the last emitted prefix.
                if ($this->onMergeDelta !== null) {
                    $full = $chunk->text();
                    if (str_starts_with($full, $lastEmitted) && strlen($full) > strlen($lastEmitted)) {
                        $delta = substr($full, strlen($lastEmitted));
                        ($this->onMergeDelta)($delta);
                        $lastEmitted = $full;
                    }
                }
            }
        }
        return [$final?->text() ?? '', $final?->usage];
    }

    /**
     * @throws BudgetExceededException when `$maxCostUsd` is set and `$spent` is over it.
     */
    private function assertBudget(float $spent, string $stage): void
    {
        if ($this->maxCostUsd === null) {
            return;
        }
        if ($spent > $this->maxCostUsd) {
            $this->emit([
                'type' => 'budget_exceeded', 'stage' => $stage,
                'spent_usd' => round($spent, 6), 'cap_usd' => $this->maxCostUsd,
            ]);
            throw new BudgetExceededException($spent, $this->maxCostUsd);
        }
    }

    /**
     * @return array{0: string, 1: ?Usage}
     */
    private function oneShot(LLMProvider $provider, string $prompt): array
    {
        $messages = [new UserMessage($prompt)];
        $final = null;
        foreach ($provider->chat($messages, [], null, ['max_tokens' => 4000]) as $chunk) {
            if ($chunk instanceof AssistantMessage) {
                $final = $chunk;
            }
        }
        return [$final?->text() ?? '', $final?->usage];
    }

    protected function buildProvider(string $modelId): LLMProvider
    {
        $entry = ModelCatalog::model($modelId);
        $provider = is_array($entry) ? (string) ($entry['provider'] ?? '') : '';
        if ($provider === '') {
            throw new \RuntimeException("Model '{$modelId}' is not in the catalog — cannot resolve provider");
        }
        $config = ConfigRepository::getInstance()->get("superagent.providers.{$provider}", []);
        $config = is_array($config) ? $config : [];
        $config['model'] = $modelId;
        return ProviderRegistry::create($provider, $config);
    }

    private function costOf(string $modelId, ?Usage $usage): float
    {
        if ($usage === null) {
            return 0.0;
        }
        try {
            return (float) CostCalculator::calculate($modelId, $usage);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $extra
     */
    private function persistRun(string $task, array $result, array $extra): ?string
    {
        try {
            if (! is_dir($this->runLogDir) && ! @mkdir($this->runLogDir, 0775, true) && ! is_dir($this->runLogDir)) {
                return null;
            }
            $stamp = date('Y-m-d_His');
            $short = substr(bin2hex(random_bytes(3)), 0, 6);
            $path = $this->runLogDir . DIRECTORY_SEPARATOR . $stamp . '_' . $short . '.json';
            $payload = [
                'task'             => $task,
                'brain'            => $result['brain'],
                'plan'             => $result['plan'],
                'subtask_results'  => $result['subtask_results'],
                'final'            => $result['final'],
                'total_cost_usd'   => $result['total_cost_usd'],
                'total_latency_ms' => $result['total_latency_ms'],
                'ran_at'           => date('c'),
            ] + $extra;
            @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($event);
        }
    }
}
