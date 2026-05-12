<?php

declare(strict_types=1);

namespace SuperAgent\Evals;

use SuperAgent\Config\ConfigRepository;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\CostCalculator;
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
final class SmartOrchestrator
{
    public const DEFAULT_EASY_THRESHOLD = 0.6;

    private const KNOWN_DIMS = ['coding', 'reasoning', 'json_mode', 'instruction_following'];

    /** @var callable(array<string, mixed>): void|null */
    private $onEvent;

    public function __construct(
        private ScoreCatalog $catalog,
        private ?string $brainOverride = null,
        private float $easyThreshold = self::DEFAULT_EASY_THRESHOLD,
        ?callable $onEvent = null,
        private ?string $runLogDir = null,
    ) {
        $this->onEvent = $onEvent;
        $this->runLogDir ??= self::defaultRunLogDir();
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

        if (
            $plan['concurrency'] === 'parallel'
            && count($plan['subtasks']) > 1
            && self::canSpawnSubprocesses()
        ) {
            $subtaskResults = $this->executeParallel($task, $plan, $brain);
        } else {
            $subtaskResults = $this->executeSerial($task, $plan, $brain);
        }
        foreach ($subtaskResults as $r) {
            $totalCost += (float) ($r['cost_usd'] ?? 0.0);
        }

        // Skip merge when there's only one subtask — the output is already the answer.
        if (count($subtaskResults) === 1) {
            $final = $subtaskResults[0]['output'];
            $this->emit(['type' => 'merge_skipped', 'reason' => 'single subtask']);
        } else {
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

    // --- Internals ------------------------------------------------------

    /**
     * @return array{0: Plan, 1: string, 2: ?Usage}
     */
    private function plan(LLMProvider $brain, string $task): array
    {
        $system = $this->plannerSystemPrompt();
        $user = $this->plannerUserPrompt($task);

        $messages = [new UserMessage($user)];
        $final = null;
        foreach ($brain->chat($messages, [], $system, ['max_tokens' => 1500]) as $chunk) {
            if ($chunk instanceof AssistantMessage) {
                $final = $chunk;
            }
        }
        $raw = $final?->text() ?? '';
        $plan = $this->parsePlan($raw, $task);
        return [$plan, $raw, $final?->usage];
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
     * @param Plan $plan
     * @return list<array<string, mixed>>
     */
    private function executeSerial(string $task, array $plan, string $brain): array
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
        }
        return $results;
    }

    /**
     * Real OS-level concurrent execution.
     *
     * Each subtask becomes a `superagent _subtask` subprocess fed via stdin.
     * We `start()` all of them, then poll until every Process reports
     * isTerminated(). That gives genuine parallel HTTP — N curl streams run
     * concurrently, not serially. Wall-clock latency on N subtasks of equal
     * duration drops from ~N×t to ~max(t) plus a small (~150ms) PHP boot
     * cost per worker.
     *
     * Parallel subprocesses don't see each other's outputs — only the
     * original task — matching the documented semantics of
     * `concurrency=parallel`. The brain merges everything at the end.
     *
     * @param Plan $plan
     * @return list<array<string, mixed>>
     */
    private function executeParallel(string $task, array $plan, string $brain): array
    {
        $bin = self::findBinEntry();
        $php = self::findPhpBinary();
        if ($bin === null || $php === null) {
            $this->emit(['type' => 'parallel_fallback', 'reason' => 'bin/superagent or php binary not found']);
            return $this->executeSerial($task, $plan, $brain);
        }

        $processes = [];
        $modelByStId = [];
        $promptByStId = [];
        $stByStId = [];
        foreach ($plan['subtasks'] as $st) {
            $modelId = $this->routeSubtask($st, $brain);
            $modelByStId[$st['id']] = $modelId;
            $stByStId[$st['id']] = $st;
            $this->emit([
                'type' => 'subtask_routed', 'id' => $st['id'], 'model' => $modelId,
                'difficulty' => $st['difficulty'], 'dim' => $st['dim'],
            ]);

            // In parallel mode subtasks are independent — only the original task is in scope.
            $stPrompt = $this->renderSubtaskPrompt($st, $task, []);
            $promptByStId[$st['id']] = $stPrompt;

            $payload = json_encode([
                'model'  => $modelId,
                'prompt' => $stPrompt,
                'system' => null,
                'max_tokens' => 4000,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                $this->emit(['type' => 'subtask_error', 'id' => $st['id'], 'error' => 'failed to encode subtask payload']);
                continue;
            }

            $process = new Process([$php, $bin, '_subtask']);
            $process->setInput($payload);
            // No idle timeout — model calls can be long.
            $process->setTimeout(null);
            $process->start();
            $processes[$st['id']] = ['process' => $process, 'started' => microtime(true)];
        }

        $this->emit(['type' => 'parallel_started', 'count' => count($processes)]);

        // Block until every subprocess finishes. `wait()` is per-process but the
        // sibling processes keep running in the meantime — total wall-clock is
        // bounded by the slowest subprocess, not the sum.
        $results = [];
        foreach ($processes as $stId => $bundle) {
            /** @var Process $proc */
            $proc = $bundle['process'];
            try {
                $proc->wait();
            } catch (\Throwable $e) {
                // Surface as a failed subtask rather than crash the whole run.
            }
            $latency = (int) round((microtime(true) - $bundle['started']) * 1000);
            $stdout = $proc->getOutput();
            $stderr = $proc->getErrorOutput();
            $st = $stByStId[$stId];
            $modelId = $modelByStId[$stId];

            $decoded = json_decode(trim($stdout), true);
            if (! is_array($decoded) || ! ($decoded['ok'] ?? false)) {
                $err = is_array($decoded) ? (string) ($decoded['error'] ?? 'subprocess returned non-ok envelope') : ('subprocess output unparseable; stderr: ' . trim($stderr));
                $results[] = [
                    'id' => $stId, 'prompt' => $st['prompt'], 'difficulty' => $st['difficulty'],
                    'dim' => $st['dim'], 'model' => $modelId,
                    'output' => '[subtask failed: ' . $err . ']',
                    'latency_ms' => $latency, 'cost_usd' => 0.0,
                ];
                $this->emit(['type' => 'subtask_error', 'id' => $stId, 'error' => $err]);
                continue;
            }

            $results[] = [
                'id' => $stId, 'prompt' => $st['prompt'], 'difficulty' => $st['difficulty'],
                'dim' => $st['dim'], 'model' => $modelId,
                'output' => (string) ($decoded['output'] ?? ''),
                'latency_ms' => (int) ($decoded['latency_ms'] ?? $latency),
                'cost_usd' => (float) ($decoded['cost_usd'] ?? 0.0),
            ];
            $this->emit([
                'type' => 'subtask_done', 'id' => $stId,
                'latency_ms' => (int) ($decoded['latency_ms'] ?? $latency),
                'cost_usd' => (float) ($decoded['cost_usd'] ?? 0.0),
            ]);
        }

        // Restore the plan's subtask order in the result list — `wait()` doesn't
        // preserve insertion order if processes finish out-of-order via array iteration.
        $byId = [];
        foreach ($results as $r) {
            $byId[$r['id']] = $r;
        }
        $ordered = [];
        foreach ($plan['subtasks'] as $st) {
            if (isset($byId[$st['id']])) {
                $ordered[] = $byId[$st['id']];
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
        foreach ($brain->chat($messages, [], $system, ['max_tokens' => 4000]) as $chunk) {
            if ($chunk instanceof AssistantMessage) {
                $final = $chunk;
            }
        }
        return [$final?->text() ?? '', $final?->usage];
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

    private function buildProvider(string $modelId): LLMProvider
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
