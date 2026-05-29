<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

/**
 * Routes slash commands from the REPL loop to handler closures.
 *
 * Built-in commands: /help, /status, /tasks, /compact, /continue,
 * /session, /clear, /model, /cost, /smart, /workflows, /ultraplan,
 * /ultrareview, /deep-research, /quit
 *
 * Custom commands can be registered via `register()`.
 *
 * `/workflows`, `/ultraplan`, `/ultrareview` and `/deep-research` mirror the
 * Opus 4.8 harness commands. They build/run **dynamic workflows** through a
 * session-scoped {@see \SuperAgent\Tools\Builtin\WorkflowTool}. Both a plan (dry-run, offline)
 * and a live (PipelineEngine-backed) mode are always selectable by the caller
 * via `--run` / `--plan` — the configured environment sets the default, never
 * the only option. Inject an agent runner with
 * `getWorkflowTool()->setPipelineRunner(...)` to enable live execution.
 */
class CommandRouter
{
    /** @var array<string, CommandDefinition> */
    private array $commands = [];

    /**
     * Session-scoped workflow store shared by /workflows, /ultraplan and
     * /ultrareview so workflows created by one command are visible to the
     * others (and persist across calls within the REPL session).
     */
    private ?\SuperAgent\Tools\Builtin\WorkflowTool $workflowTool = null;

    public function __construct()
    {
        $this->registerBuiltins();
    }

    /**
     * The session-scoped WorkflowTool. Hosts can call
     * `setPipelineRunner()` on it to enable live `--run` execution.
     */
    public function getWorkflowTool(): \SuperAgent\Tools\Builtin\WorkflowTool
    {
        return $this->workflowTool ??= new \SuperAgent\Tools\Builtin\WorkflowTool();
    }

    /**
     * Register a custom slash command.
     */
    public function register(string $name, string $description, \Closure $handler): void
    {
        $this->commands[strtolower($name)] = new CommandDefinition($name, $description, $handler);
    }

    /**
     * Check if input is a slash command (starts with /).
     */
    public function isCommand(string $input): bool
    {
        return str_starts_with(trim($input), '/');
    }

    /**
     * Parse a command line into [name, args].
     *
     * @return array{0: string, 1: string} [command_name, arguments]
     */
    public function parse(string $input): array
    {
        $input = trim($input);
        if (!str_starts_with($input, '/')) {
            return ['', $input];
        }

        $parts = explode(' ', substr($input, 1), 2);
        $name = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        return [$name, $args];
    }

    /**
     * Execute a command. Returns the handler result or null if unknown.
     *
     * @param  string $input      Full input line (e.g. "/status --verbose")
     * @param  array  $context    Shared harness context for handlers
     * @return CommandResult
     */
    public function dispatch(string $input, array $context = []): CommandResult
    {
        [$name, $args] = $this->parse($input);

        if ($name === '' || !isset($this->commands[$name])) {
            $available = implode(', ', array_map(fn($c) => "/{$c->name}", $this->commands));
            return CommandResult::error("Unknown command: /{$name}. Available: {$available}");
        }

        try {
            $output = ($this->commands[$name]->handler)($args, $context);
            return CommandResult::success((string) $output);
        } catch (\Throwable $e) {
            return CommandResult::error("Command /{$name} failed: {$e->getMessage()}");
        }
    }

    /**
     * Get all registered command definitions.
     *
     * @return CommandDefinition[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Check if a specific command is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->commands[strtolower($name)]);
    }

    // ── Built-ins ─────────────────────────────────────────────────

    private function registerBuiltins(): void
    {
        $this->register('help', 'Show available commands', function (string $args, array $ctx): string {
            $lines = ["Available commands:"];
            foreach ($this->commands as $cmd) {
                $lines[] = "  /{$cmd->name} — {$cmd->description}";
            }
            return implode("\n", $lines);
        });

        $this->register('status', 'Show session status (turns, cost, tokens)', function (string $args, array $ctx): string {
            $turns = $ctx['turn_count'] ?? 0;
            $cost = $ctx['total_cost_usd'] ?? 0.0;
            $model = $ctx['model'] ?? 'unknown';
            $messageCount = $ctx['message_count'] ?? 0;
            return sprintf(
                "Model: %s | Turns: %d | Messages: %d | Cost: \$%.4f",
                $model, $turns, $messageCount, $cost,
            );
        });

        $this->register('tasks', 'List current tasks', function (string $args, array $ctx): string {
            $tasks = $ctx['tasks'] ?? [];
            if (empty($tasks)) {
                return 'No tasks.';
            }
            $lines = [];
            foreach ($tasks as $task) {
                $status = $task['status'] ?? 'unknown';
                $subject = $task['subject'] ?? 'Untitled';
                $id = $task['id'] ?? '?';
                $lines[] = "  [{$status}] #{$id} {$subject}";
            }
            return implode("\n", $lines);
        });

        $this->register('compact', 'Force context compaction', function (string $args, array $ctx): string {
            $compactor = $ctx['auto_compactor'] ?? null;
            if ($compactor === null) {
                return 'AutoCompactor not available.';
            }
            $messages = &$ctx['messages'];
            if ($messages === null) {
                return 'No messages to compact.';
            }
            $compacted = $compactor->maybeCompact($messages);
            return $compacted
                ? "Compaction done. Total tokens saved: {$compactor->getTotalTokensSaved()}"
                : 'No compaction needed or all strategies failed.';
        });

        $this->register('continue', 'Continue a pending tool loop', function (string $args, array $ctx): string {
            // Sets a flag that HarnessLoop reads
            return '__CONTINUE__';
        });

        $this->register('session', 'Session management (list|save|load|delete)', function (string $args, array $ctx): string {
            $parts = explode(' ', trim($args), 2);
            $subCommand = $parts[0] ?? 'list';
            $subArgs = $parts[1] ?? '';
            $sessionManager = $ctx['session_manager'] ?? null;

            if ($sessionManager === null) {
                return 'SessionManager not available (persistence disabled).';
            }

            return match ($subCommand) {
                'list' => $this->sessionList($sessionManager),
                'save' => $this->sessionSave($sessionManager, $subArgs, $ctx),
                'load' => $this->sessionLoad($sessionManager, $subArgs),
                'delete' => $this->sessionDelete($sessionManager, $subArgs),
                default => "Unknown subcommand: {$subCommand}. Use: list, save, load, delete",
            };
        });

        $this->register('clear', 'Clear conversation history', function (string $args, array $ctx): string {
            return '__CLEAR__';
        });

        $this->register('model', 'Show, list, or change the current model', function (string $args, array $ctx): string {
            $current = (string) ($ctx['model'] ?? 'unknown');
            $arg = trim($args);

            if ($arg === '' || strtolower($arg) === 'list') {
                $models = $this->availableModels($ctx);
                $lines = ["Current model: {$current}", '', 'Available models:'];
                foreach ($models as $i => $m) {
                    $marker = ($m['id'] === $current) ? ' *' : '';
                    $desc = $m['description'] ?? '';
                    $lines[] = sprintf('  %d) %s%s%s', $i + 1, $m['id'], $desc ? " — {$desc}" : '', $marker);
                }
                $lines[] = '';
                $lines[] = 'Usage: /model <id|number|alias>';
                return implode("\n", $lines);
            }

            // Allow selecting by number
            if (ctype_digit($arg)) {
                $models = $this->availableModels($ctx);
                $idx = (int) $arg - 1;
                if (isset($models[$idx])) {
                    return '__MODEL__:' . $models[$idx]['id'];
                }
                return "Invalid selection: {$arg}";
            }

            return '__MODEL__:' . $arg;
        });

        $this->register('cost', 'Show cost breakdown', function (string $args, array $ctx): string {
            $cost = $ctx['total_cost_usd'] ?? 0.0;
            $turns = $ctx['turn_count'] ?? 0;
            $perTurn = $turns > 0 ? $cost / $turns : 0;
            return sprintf("Total cost: \$%.4f | Turns: %d | Avg/turn: \$%.4f", $cost, $turns, $perTurn);
        });

        $this->register('quit', 'Exit the session', function (string $args, array $ctx): string {
            return '__QUIT__';
        });

        $this->register('smart', 'Run an eval-score-driven smart task (plan+route+merge)', function (string $args, array $ctx): string {
            $task = trim($args);
            if ($task === '') {
                return "Usage: /smart <task>\nReads ~/.superagent/model_scores.json to pick a brain model, plan + route subtasks, and merge.";
            }
            try {
                $catalog = \SuperAgent\Evals\ScoreCatalog::default();
                $orchestrator = new \SuperAgent\Evals\SmartOrchestrator(catalog: $catalog);
                $result = $orchestrator->run($task);
                $cost = sprintf('%.4f', (float) ($result['total_cost_usd'] ?? 0.0));
                $brain = (string) ($result['brain'] ?? '?');
                $count = is_array($result['subtask_results'] ?? null) ? count($result['subtask_results']) : 0;
                $final = (string) ($result['final'] ?? '');
                return $final . "\n\n— smart: brain={$brain} · subtasks={$count} · cost=\${$cost}";
            } catch (\SuperAgent\Exceptions\BudgetExceededException $e) {
                return sprintf('Smart run aborted — budget cap of $%.4f exceeded (spent $%.4f).', $e->budget, $e->spent);
            } catch (\Throwable $e) {
                return 'Smart run failed: ' . $e->getMessage();
            }
        });

        $this->register('workflows', 'Create/run dynamic workflows (list|get|plan|run|delete|create)', function (string $args, array $ctx): string {
            return $this->handleWorkflowsCommand($args);
        });

        $this->register('ultraplan', 'Deep-plan a task into a dynamic workflow (Opus 4.8 ultraplan)', function (string $args, array $ctx): string {
            return $this->handleUltraplan($args, $ctx);
        });

        $this->register('ultrareview', 'Multi-dimension review of the current diff as a dynamic workflow (Opus 4.8 ultrareview)', function (string $args, array $ctx): string {
            return $this->handleUltrareview($args, $ctx);
        });

        $this->register('deep-research', 'Fan-out web research → verify → cited report as a dynamic workflow (Opus 4.8 deep-research)', function (string $args, array $ctx): string {
            return $this->handleDeepResearch($args, $ctx);
        });
    }

    // ── /workflows, /ultraplan, /ultrareview ──────────────────────

    /**
     * Pull mode flags (`--run` / `--execute`, `--plan` / `--dry-run`) out of an
     * argument string. Returns [cleanedArgs, executeOrNull] where executeOrNull
     * is true (force live), false (force plan) or null (use the default).
     *
     * @return array{0: string, 1: ?bool}
     */
    private function parseModeFlags(string $args): array
    {
        $execute = null;
        $tokens = preg_split('/\s+/', trim($args)) ?: [];
        $kept = [];
        foreach ($tokens as $tok) {
            $low = strtolower($tok);
            if (in_array($low, ['--run', '--execute', '--live'], true)) {
                $execute = true;
            } elseif (in_array($low, ['--plan', '--dry-run', '--dry'], true)) {
                $execute = false;
            } elseif ($tok !== '') {
                $kept[] = $tok;
            }
        }
        return [implode(' ', $kept), $execute];
    }

    private function handleWorkflowsCommand(string $args): string
    {
        [$clean, $execute] = $this->parseModeFlags($args);
        $clean = trim($clean);
        $parts = $clean === '' ? [] : preg_split('/\s+/', $clean, 2);
        $sub = strtolower($parts[0] ?? 'list');
        $rest = trim($parts[1] ?? '');
        $tool = $this->getWorkflowTool();

        switch ($sub) {
            case '':
            case 'list':
                return $this->formatWorkflowResult($tool->execute(['action' => 'list']));

            case 'get':
            case 'plan':
            case 'delete':
                if (!ctype_digit($rest)) {
                    return "Usage: /workflows {$sub} <id>";
                }
                return $this->formatWorkflowResult($tool->execute([
                    'action' => $sub,
                    'workflow_id' => (int) $rest,
                ]));

            case 'run':
                $segs = $rest === '' ? [] : preg_split('/\s+/', $rest, 2);
                $id = $segs[0] ?? '';
                if (!ctype_digit($id)) {
                    return 'Usage: /workflows run <id> [--run|--plan] [json-parameters]';
                }
                $params = [];
                if (!empty($segs[1])) {
                    $decoded = json_decode($segs[1], true);
                    if (!is_array($decoded)) {
                        return 'Invalid JSON parameters: ' . $segs[1];
                    }
                    $params = $decoded;
                }
                // Default to plan in the REPL (no runner injected); --run/--plan override.
                if ($execute !== null) {
                    $params['execute'] = $execute;
                }
                return $this->formatWorkflowResult($tool->execute([
                    'action' => 'run',
                    'workflow_id' => (int) $id,
                    'parameters' => $params,
                ]));

            case 'create':
                if ($rest === '') {
                    return 'Usage: /workflows create <json>  (e.g. {"name":"deploy","type":"dynamic",'
                        . '"strategy":"pipeline","steps":[{"agent":"general","prompt":"..."}]})';
                }
                $spec = json_decode($rest, true);
                if (!is_array($spec)) {
                    return 'Invalid workflow JSON: ' . $rest;
                }
                $spec['action'] = 'create';
                return $this->formatWorkflowResult($tool->execute($spec));

            case 'help':
                return $this->workflowsHelp();

            default:
                return "Unknown /workflows subcommand: {$sub}\n" . $this->workflowsHelp();
        }
    }

    private function workflowsHelp(): string
    {
        return implode("\n", [
            'Workflow commands:',
            '  /workflows                       list workflows',
            '  /workflows get <id>              show a workflow definition',
            '  /workflows plan <id>             expand a workflow into its wave/iteration schedule',
            '  /workflows run <id> [--run|--plan] [json]   run (default: plan; --run executes via PipelineEngine)',
            '  /workflows delete <id>           delete a workflow',
            '  /workflows create <json>         create from {"name","type","strategy","guards","steps":[...]}',
            '',
            'Dynamic strategies: sequential, pipeline, parallel, fan_out, loop_until, self_paced',
        ]);
    }

    /**
     * /ultraplan <task> [--run|--plan]
     *
     * Decomposes the task with the heuristic TaskDecomposer (offline; refined by
     * an LLM when a host wires one in), turns the resulting plan into a dynamic
     * workflow, registers it in the session store, and prints the schedule.
     */
    private function handleUltraplan(string $args, array $ctx): string
    {
        [$task, $execute] = $this->parseModeFlags($args);
        $task = trim($task);
        if ($task === '') {
            return "Usage: /ultraplan <task> [--run|--plan]\n"
                . 'Deep-plans the task into a dynamic workflow you can inspect with /workflows and run with --run.';
        }

        try {
            $decomposer = new \SuperAgent\Squad\TaskDecomposer();
            $result = $decomposer->decomposeWithConfidence($task);
            $subTasks = $result->subTasks;
        } catch (\Throwable $e) {
            return 'Ultraplan failed during decomposition: ' . $e->getMessage();
        }

        $steps = [];
        foreach ($subTasks as $st) {
            $steps[] = [
                'name' => $st->name,
                'agent' => $st->role !== '' ? $st->role : 'general',
                'prompt' => $st->prompt,
                'depends_on' => $st->dependsOn,
            ];
        }

        $tool = $this->getWorkflowTool();
        $created = $tool->execute([
            'action' => 'create',
            'name' => 'ultraplan: ' . $this->snippet($task, 48),
            'description' => 'Deep plan generated from: ' . $task,
            'type' => \SuperAgent\Tools\Builtin\WorkflowTool::TYPE_DYNAMIC,
            'strategy' => 'sequential',
            'steps' => $steps,
        ]);
        if ($created->isError) {
            return 'Ultraplan could not build a workflow: ' . $created->contentAsString();
        }
        $wfId = (int) (($created->content['workflow_id'] ?? 0));

        $lines = [
            sprintf('Ultraplan — %d step(s), confidence %.0f%%%s',
                count($subTasks), $result->confidence * 100,
                $result->signals ? ' (' . implode(', ', $result->signals) . ')' : ''),
            '',
        ];
        $i = 1;
        foreach ($subTasks as $st) {
            $dep = $st->dependsOn ? ' ⟸ ' . implode(', ', $st->dependsOn) : '';
            $rev = $st->requiresReview ? ' [review]' : '';
            $lines[] = sprintf('  %d. [%s] %s%s%s', $i++, $st->role ?: 'do', $st->name, $dep, $rev);
            $lines[] = '       ' . $this->snippet($st->prompt, 90);
        }
        $lines[] = '';
        $lines[] = "Saved as dynamic workflow #{$wfId}. Inspect: /workflows plan {$wfId}  ·  run: /workflows run {$wfId} --run";

        // Live execution only if the caller asked for it AND a runner is wired.
        if ($execute === true) {
            $lines[] = '';
            $run = $tool->execute(['action' => 'run', 'workflow_id' => $wfId, 'parameters' => ['execute' => true]]);
            $lines[] = $this->formatWorkflowResult($run);
        }

        return implode("\n", $lines);
    }

    /**
     * /ultrareview [target] [--run|--plan]
     *
     * Builds a dynamic review workflow: a parallel fan-out across review
     * dimensions over the current diff, followed by a synthesis step.
     */
    private function handleUltrareview(string $args, array $ctx): string
    {
        [$target, $execute] = $this->parseModeFlags($args);
        $target = trim($target);

        $dimensions = ['correctness', 'security', 'performance', 'tests', 'maintainability'];
        $scope = $target !== '' ? $target : 'the current working-tree diff';

        $reviewSteps = [];
        foreach ($dimensions as $dim) {
            $reviewSteps[] = [
                'name' => "review-{$dim}",
                'agent' => 'reviewer',
                'prompt' => "Review {$scope} for {$dim} issues. Report concrete findings with file:line and severity.",
            ];
        }
        $steps = [
            ['name' => 'review', 'parallel' => $reviewSteps],
            [
                'name' => 'synthesize',
                'agent' => 'reviewer',
                'prompt' => 'Merge the per-dimension findings into one prioritized review, de-duplicated and ranked by severity.',
                'depends_on' => ['review'],
            ],
        ];

        $tool = $this->getWorkflowTool();
        $created = $tool->execute([
            'action' => 'create',
            'name' => 'ultrareview: ' . $this->snippet($scope, 40),
            'description' => 'Multi-dimension review of ' . $scope,
            'type' => \SuperAgent\Tools\Builtin\WorkflowTool::TYPE_DYNAMIC,
            'strategy' => 'sequential',
            'steps' => $steps,
        ]);
        if ($created->isError) {
            return 'Ultrareview could not build a workflow: ' . $created->contentAsString();
        }
        $wfId = (int) (($created->content['workflow_id'] ?? 0));

        $lines = [
            'Ultrareview — ' . count($dimensions) . ' dimensions over ' . $scope,
            '',
            '  Wave 1 (parallel): ' . implode(' · ', $dimensions),
            '  Wave 2: synthesize → one prioritized, de-duplicated review',
        ];

        $diffStat = $this->gitDiffStat();
        if ($diffStat !== null) {
            $lines[] = '';
            $lines[] = 'Changed files under review:';
            $lines[] = $diffStat;
        }

        $lines[] = '';
        $lines[] = "Saved as dynamic workflow #{$wfId}. Inspect: /workflows plan {$wfId}  ·  run: /workflows run {$wfId} --run";

        if ($execute === true) {
            $lines[] = '';
            $run = $tool->execute(['action' => 'run', 'workflow_id' => $wfId, 'parameters' => ['execute' => true]]);
            $lines[] = $this->formatWorkflowResult($run);
        }

        return implode("\n", $lines);
    }

    /**
     * /deep-research <question> [--run|--plan]
     *
     * Mirrors the Opus 4.8 deep-research harness as a dynamic workflow: a
     * parallel fan-out of web searches across distinct angles, an adversarial
     * verification pass that cross-checks the gathered claims, then a synthesis
     * step that produces one cited report.
     */
    private function handleDeepResearch(string $args, array $ctx): string
    {
        [$question, $execute] = $this->parseModeFlags($args);
        $question = trim($question);
        if ($question === '') {
            return "Usage: /deep-research <question> [--run|--plan]\n"
                . 'Fans out web searches across angles, reads the sources, adversarially verifies the claims, and synthesizes one cited report.';
        }

        // Angles fanned out concurrently in wave 1; each researcher searches the
        // web and reads its own sources for that lens.
        $angles = [
            'background' => 'foundational background, key definitions, and context',
            'current'    => 'the most recent developments, data, and reporting',
            'evidence'   => 'primary sources, studies, and hard numbers',
            'skeptic'    => 'counterarguments, criticism, and competing interpretations',
        ];

        $searchSteps = [];
        foreach ($angles as $key => $focus) {
            $searchSteps[] = [
                'name' => "search-{$key}",
                'agent' => 'researcher',
                'prompt' => "Research the question: \"{$question}\". Search the web for {$focus}. "
                    . 'Read the most relevant sources and report concrete findings, each with its source URL and publication date.',
            ];
        }

        $steps = [
            ['name' => 'search', 'parallel' => $searchSteps],
            [
                'name' => 'verify',
                'agent' => 'researcher',
                'prompt' => "Adversarially verify the key claims gathered about \"{$question}\". "
                    . 'Cross-check each claim against independent sources; flag anything unsupported, outdated, or contradicted, and keep only what survives.',
                'depends_on' => ['search'],
            ],
            [
                'name' => 'synthesize',
                'agent' => 'researcher',
                'prompt' => "Synthesize the verified findings on \"{$question}\" into one cited report: "
                    . 'a direct answer up front, supporting evidence with inline source citations, remaining open questions, and an overall confidence level.',
                'depends_on' => ['verify'],
            ],
        ];

        $tool = $this->getWorkflowTool();
        $created = $tool->execute([
            'action' => 'create',
            'name' => 'deep-research: ' . $this->snippet($question, 44),
            'description' => 'Deep research on: ' . $question,
            'type' => \SuperAgent\Tools\Builtin\WorkflowTool::TYPE_DYNAMIC,
            'strategy' => 'sequential',
            'steps' => $steps,
        ]);
        if ($created->isError) {
            return 'Deep-research could not build a workflow: ' . $created->contentAsString();
        }
        $wfId = (int) (($created->content['workflow_id'] ?? 0));

        $lines = [
            'Deep-research — ' . count($angles) . ' search angles on: ' . $this->snippet($question, 60),
            '',
            '  Wave 1 (parallel): ' . implode(' · ', array_keys($angles)),
            '  Wave 2: verify → adversarially cross-check the gathered claims',
            '  Wave 3: synthesize → one cited report (answer · evidence · open questions · confidence)',
            '',
            "Saved as dynamic workflow #{$wfId}. Inspect: /workflows plan {$wfId}  ·  run: /workflows run {$wfId} --run",
        ];

        if ($execute === true) {
            $lines[] = '';
            $run = $tool->execute(['action' => 'run', 'workflow_id' => $wfId, 'parameters' => ['execute' => true]]);
            $lines[] = $this->formatWorkflowResult($run);
        }

        return implode("\n", $lines);
    }

    private function gitDiffStat(): ?string
    {
        $cwd = getcwd();
        if ($cwd === false || !is_dir($cwd . '/.git')) {
            return null;
        }
        $out = @shell_exec('git -C ' . escapeshellarg($cwd) . ' diff --stat 2>/dev/null');
        $out = is_string($out) ? trim($out) : '';
        if ($out === '') {
            $out = @shell_exec('git -C ' . escapeshellarg($cwd) . ' diff --stat HEAD 2>/dev/null');
            $out = is_string($out) ? trim($out) : '';
        }
        return $out === '' ? null : $out;
    }

    private function snippet(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * Render a WorkflowTool ToolResult as readable REPL text.
     */
    private function formatWorkflowResult(\SuperAgent\Tools\ToolResult $res): string
    {
        if ($res->isError) {
            return 'Workflow error: ' . $res->contentAsString();
        }
        $data = is_array($res->content) ? $res->content : ['message' => $res->content];

        // List view.
        if (isset($data['workflows']) && is_array($data['workflows'])) {
            if (empty($data['workflows'])) {
                return "No workflows yet.\n" . $this->workflowsHelp();
            }
            $lines = [sprintf('Workflows (%d):', count($data['workflows']))];
            foreach ($data['workflows'] as $w) {
                $type = $w['type'] ?? 'static';
                $strat = isset($w['strategy']) ? "/{$w['strategy']}" : '';
                $lines[] = sprintf(
                    '  #%s %s [%s%s] — %d step(s), run %d× — %s',
                    $w['id'] ?? '?', $w['name'] ?? '?', $type, $strat,
                    $w['steps'] ?? 0, $w['run_count'] ?? 0, $w['description'] ?? ''
                );
            }
            return implode("\n", $lines);
        }

        // Plan view.
        if (isset($data['plan']) && is_array($data['plan'])) {
            $plan = $data['plan'];
            $lines = [];
            if (isset($data['message'])) {
                $lines[] = (string) $data['message'];
            }
            $lines[] = sprintf('Workflow #%s "%s" — type %s, strategy %s, ~%s step(s)',
                $data['workflow_id'] ?? '?', $data['name'] ?? '?',
                $plan['type'] ?? '?', $plan['strategy'] ?? '?', $plan['estimated_steps'] ?? '?');
            foreach (($plan['waves'] ?? []) as $w) {
                $lines[] = '  ' . $w;
            }
            if (!empty($plan['loop'])) {
                $lines[] = '  loop: ' . $plan['loop'];
            }
            return implode("\n", $lines);
        }

        // Generic key/value view (create/run/delete/get).
        $lines = [];
        if (isset($data['message'])) {
            $lines[] = (string) $data['message'];
        }
        foreach ($data as $k => $v) {
            if ($k === 'message') {
                continue;
            }
            $lines[] = sprintf('  %s: %s', $k, is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        return $lines === [] ? 'OK' : implode("\n", $lines);
    }

    /**
     * Return the list of available models for the active provider.
     * Provider inferred from ctx['provider'] or ctx['model'] prefix.
     *
     * @return array<int, array{id:string, description?:string}>
     */
    private function availableModels(array $ctx): array
    {
        $provider = strtolower((string) ($ctx['provider'] ?? ''));
        $model = (string) ($ctx['model'] ?? '');
        if ($provider === '' && str_starts_with($model, 'claude')) {
            $provider = 'anthropic';
        }
        if ($provider === '' && (str_starts_with($model, 'gpt') || str_starts_with($model, 'o'))) {
            $provider = 'openai';
        }
        if ($provider === '' && str_starts_with($model, 'gemini')) {
            $provider = 'gemini';
        }

        // Dynamic catalog wins when it has entries for this provider — keeps /model
        // in sync with `superagent models update` without a code release.
        if ($provider !== '') {
            $catalog = \SuperAgent\Providers\ModelCatalog::modelsFor($provider);
            if (! empty($catalog)) {
                $out = [];
                foreach ($catalog as $entry) {
                    $row = ['id' => (string) ($entry['id'] ?? '')];
                    if (! empty($entry['description'])) {
                        $row['description'] = (string) $entry['description'];
                    }
                    if ($row['id'] !== '') {
                        $out[] = $row;
                    }
                }
                if (! empty($out)) {
                    return $out;
                }
            }
        }

        return match ($provider) {
            'anthropic' => [
                ['id' => 'claude-opus-4-8',    'description' => 'Opus 4.8 — flagship reasoning + workflows'],
                ['id' => 'claude-opus-4-5',    'description' => 'Opus 4.5'],
                ['id' => 'claude-sonnet-4-5',  'description' => 'Sonnet 4.5 — balanced'],
                ['id' => 'claude-haiku-4-5',   'description' => 'Haiku 4.5 — fast + cheap'],
                ['id' => 'claude-opus-4-1',    'description' => 'Opus 4.1'],
                ['id' => 'claude-sonnet-4',    'description' => 'Sonnet 4'],
            ],
            'openai' => [
                ['id' => 'gpt-5',              'description' => 'GPT-5'],
                ['id' => 'gpt-5-mini',         'description' => 'GPT-5 mini'],
                ['id' => 'gpt-4o',             'description' => 'GPT-4o'],
                ['id' => 'o4-mini',            'description' => 'o4-mini — reasoning'],
            ],
            'gemini' => [
                ['id' => 'gemini-2.0-flash',             'description' => 'Gemini 2.0 Flash — fast + cheap'],
                ['id' => 'gemini-2.0-flash-thinking-exp','description' => 'Gemini 2.0 Flash Thinking (experimental)'],
                ['id' => 'gemini-1.5-pro',               'description' => 'Gemini 1.5 Pro — long context'],
                ['id' => 'gemini-1.5-flash',             'description' => 'Gemini 1.5 Flash'],
            ],
            'openrouter' => [
                ['id' => 'anthropic/claude-opus-4-5'],
                ['id' => 'anthropic/claude-sonnet-4-5'],
                ['id' => 'openai/gpt-5'],
            ],
            'ollama' => [
                ['id' => 'llama3.1'],
                ['id' => 'qwen2.5-coder'],
            ],
            default => [
                ['id' => 'claude-opus-4-5'],
                ['id' => 'claude-sonnet-4-5'],
                ['id' => 'gpt-5'],
            ],
        };
    }

    // ── Session sub-commands ──────────────────────────────────────

    private function sessionList(object $mgr): string
    {
        $sessions = $mgr->listSessions(10);
        if (empty($sessions)) {
            return 'No saved sessions.';
        }
        $lines = ['Recent sessions:'];
        foreach ($sessions as $s) {
            $summary = mb_substr($s['summary'] ?? '', 0, 50);
            $lines[] = sprintf(
                "  %s — %s (%d msgs, \$%.3f)",
                $s['session_id'], $summary, $s['message_count'] ?? 0, $s['total_cost_usd'] ?? 0,
            );
        }
        return implode("\n", $lines);
    }

    private function sessionSave(object $mgr, string $id, array $ctx): string
    {
        if ($id === '') {
            $id = 'session-' . date('Ymd-His');
        }
        $messages = $ctx['messages_serialized'] ?? [];
        $mgr->save($id, $messages, [
            'model' => $ctx['model'] ?? null,
            'cwd' => $ctx['cwd'] ?? getcwd(),
            'total_cost_usd' => $ctx['total_cost_usd'] ?? 0.0,
        ]);
        return "Session saved: {$id}";
    }

    private function sessionLoad(object $mgr, string $id): string
    {
        if ($id === '') {
            return 'Usage: /session load <session-id>';
        }
        $data = $mgr->loadById(trim($id));
        if ($data === null) {
            return "Session not found: {$id}";
        }
        return '__SESSION_LOAD__:' . json_encode($data);
    }

    private function sessionDelete(object $mgr, string $id): string
    {
        if ($id === '') {
            return 'Usage: /session delete <session-id>';
        }
        $deleted = $mgr->delete(trim($id));
        return $deleted ? "Session deleted: {$id}" : "Session not found: {$id}";
    }
}

