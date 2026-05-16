<?php

declare(strict_types=1);

namespace SuperAgent\AutoMode;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Agent\Agent;
use SuperAgent\AgentResult;
use SuperAgent\Context\Context;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\Templates\AgentTemplateManager;
use SuperAgent\Console\Output\ParallelAgentDisplay;
use SuperAgent\Squad\PeerOrchestrator;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAgent\Squad\TaskDecomposer;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Agent that automatically decides between single, multi-agent, or squad mode.
 *
 * Squad mode is the cross-model peer-collaboration path: when a prompt
 * decomposes cleanly into >= 2 sub-tasks with distinct difficulty
 * bands, we skip the master-slave coordinator and run an
 * `Adaptive Cross-Model Squad` instead — each sub-task picks its own
 * model tier via `Squad\ModelTierMap`.
 */
class AutoModeAgent
{
    private TaskAnalyzer $analyzer;
    private LoggerInterface $logger;
    private array $config;
    private ?OutputInterface $output;
    private AgentTemplateManager $templateManager;

    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null,
        ?OutputInterface $output = null,
        ?AgentTemplateManager $templateManager = null
    ) {
        $this->config = array_merge([
            'auto_mode' => true,
            'provider' => 'anthropic',
            'model' => 'claude-3-opus-20240229',
            'analyzer_config' => [],
            'multi_agent_config' => [
                'max_agents' => 10,
                'backend' => 'in_process',
                'enable_display' => true,
                'refresh_interval' => 500,
            ],
        ], $config);
        
        $this->analyzer = new TaskAnalyzer(
            $this->config['analyzer_config'],
            $logger
        );
        
        $this->logger = $logger ?? new NullLogger();
        $this->output = $output ?? new NullOutput();
        $this->templateManager = $templateManager ?? AgentTemplateManager::getInstance();
    }
    
    /**
     * Run the agent with automatic mode detection.
     */
    public function run(string $prompt, array $options = []): AgentResult
    {
        // Analyze the task
        $analysis = $this->analyzer->analyzeTask($prompt);
        
        $this->logger->info("Task analysis completed", [
            'mode' => $analysis->shouldUseMultiAgent() ? 'multi-agent' : 'single-agent',
            'score' => $analysis->getComplexityScore(),
            'reason' => $analysis->getReason(),
        ]);
        
        // Output analysis to console if available
        if ($this->output->isVerbose()) {
            $this->output->writeln(sprintf(
                "<info>Task Analysis:</info> %s",
                $analysis
            ));
        }
        
        // Decide execution mode
        if (!$this->config['auto_mode']) {
            // Auto-mode disabled, use single agent
            return $this->runSingleAgent($prompt, $options);
        }
        
        if ($analysis->shouldUseMultiAgent()) {
            // Squad mode beats the master-slave coordinator when the
            // prompt naturally decomposes into well-shaped sub-tasks
            // AND the caller opted in (auto_mode.prefer_squad).
            if (($this->config['prefer_squad'] ?? true) && $this->shouldUseSquad($prompt)) {
                return $this->runSquad($prompt, $options);
            }

            // Use legacy multi-agent (master-slave) mode
            $suggestion = $this->analyzer->suggestConfiguration($analysis);
            return $this->runMultiAgent($prompt, $suggestion, $options);
        } else {
            // Use single agent mode
            return $this->runSingleAgent($prompt, $options);
        }
    }

    /**
     * Squad mode is appropriate when the prompt decomposes into 2+
     * meaningful sub-tasks AND those sub-tasks span at least two
     * difficulty bands — otherwise the cross-model overhead doesn't
     * earn its keep.
     */
    private function shouldUseSquad(string $prompt): bool
    {
        $subTasks = (new TaskDecomposer())->decompose($prompt);

        if (count($subTasks) < 2) {
            return false;
        }

        $bands = [];
        foreach ($subTasks as $s) {
            $bands[$s->difficulty->value] = true;
        }

        return count($bands) >= 2;
    }

    /**
     * Run the squad pipeline. The caller can plug a real dispatcher
     * via `$this->config['squad']['dispatcher']`; if absent, we use
     * an in-process dispatcher that delegates to a fresh `Agent` per
     * step, honouring the per-step provider/model.
     */
    private function runSquad(string $prompt, array $options = []): AgentResult
    {
        $subTasks = (new TaskDecomposer())->decompose($prompt);
        $squadId = 'auto_' . uniqid();

        $dispatcher = $this->config['squad']['dispatcher'] ?? function (SquadDispatchRequest $req) {
            $ctx = new Context();
            if ($req->provider !== '') {
                $ctx->setMetadata('provider', $req->provider);
            }
            // Stable session ID per role → provider sees the same session
            // across the squad's lifetime, so its prompt-cache prefix
            // (system prompt + prior assistant messages) stays warm and
            // re-billed at cache-hit rates instead of fresh-prefix rates.
            if ($req->sessionId !== null && $req->sessionId !== '') {
                $ctx->setMetadata('session_id', $req->sessionId);
            }
            // Per-role allowed-tools / read-only flags are surfaced via
            // the dispatch request's role so an integration host that
            // honours these can lock down researcher / verify steps.
            $ctx->setMetadata('squad_role', $req->role->name);
            $ctx->setMetadata('squad_role_tier', $req->role->tier->value);

            $agent = new Agent(context: $ctx, logger: $this->logger);
            if ($req->model !== '') {
                $agent->setModel($req->model);
            }
            if ($req->systemPrompt !== null && $req->systemPrompt !== '') {
                // Use the real system-prompt slot so providers can cache
                // it as a stable prefix instead of treating it as a user
                // message that varies turn-to-turn.
                $agent->setSystemPrompt($req->systemPrompt);
            }
            // Peer messaging: the default `Agent\Agent` used here is
            // a single-shot chat agent (no tool loop), so peer tools
            // can't be invoked by the model on this path. Inbox
            // messages still reach the agent — the orchestrator
            // prepends them to the prompt via PeerMailbox.
            // Hosts that need an in-loop PeerAsk/PeerSend call should
            // plug a `squad.dispatcher` that builds a tool-capable
            // `SuperAgent\Agent` and addTool(PeerAskTool…) when
            // $req->mailbox is non-null. See ADVANCED_USAGE §60.
            $result = $agent->run($req->prompt);
            return [
                'output'   => $result->text(),
                'cost_usd' => $result->totalCostUsd,
            ];
        };

        $orchestrator = new PeerOrchestrator($dispatcher, null, $this->logger);
        $squadResult = $orchestrator->run($squadId, $subTasks);

        $summary = '';
        foreach ($squadResult->pipelineResult->getStepResults() as $r) {
            $summary .= "## " . $r->stepName . " (" . $r->status->value . ")\n";
            $summary .= (string) $r->output . "\n\n";
        }

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text(trim($summary))];

        return new AgentResult(
            message: $msg,
            allResponses: [$msg],
            messages: [$msg],
        );
    }
    
    /**
     * Run in single agent mode.
     */
    private function runSingleAgent(string $prompt, array $options = []): AgentResult
    {
        $this->logger->info("Running in single-agent mode");
        
        if ($this->output->isVerbose()) {
            $this->output->writeln("<comment>Executing in single-agent mode...</comment>");
        }
        
        // Create context
        $context = new Context();
        
        // Create agent
        $agent = new Agent(
            context: $context,
            logger: $this->logger,
        );
        
        // Configure agent
        if (isset($this->config['provider'])) {
            $context->setMetadata('provider', $this->config['provider']);
        }
        
        if (isset($this->config['model'])) {
            $agent->setModel($this->config['model']);
        }
        
        // Apply options
        foreach ($options as $key => $value) {
            $context->setMetadata($key, $value);
        }
        
        // Run the agent
        return $agent->run($prompt);
    }
    
    /**
     * Run in multi-agent mode.
     */
    private function runMultiAgent(
        string $prompt,
        array $suggestion,
        array $options = []
    ): AgentResult {
        $this->logger->info("Running in multi-agent mode", [
            'suggested_agents' => $suggestion['agents'],
            'team_structure' => $suggestion['team_structure'] ?? [],
        ]);
        
        if ($this->output->isVerbose()) {
            $this->output->writeln(sprintf(
                "<comment>Executing in multi-agent mode with %d agents...</comment>",
                $suggestion['agents']
            ));
        }
        
        // Create team context
        $teamName = 'auto_team_' . uniqid();
        $leaderId = 'coordinator';
        $team = new TeamContext($teamName, $leaderId);
        
        // Create backend
        $backend = new InProcessBackend($this->logger);
        $backend->setTeamContext($team);
        
        // Parse the prompt to identify subtasks
        $subtasks = $this->parseSubtasks($prompt, $suggestion['agents']);
        
        // Spawn agents based on subtasks
        $agents = [];
        foreach ($subtasks as $index => $subtask) {
            $agentName = $this->generateAgentName($subtask['type'], $index);
            
            $config = new AgentSpawnConfig(
                name: $agentName,
                prompt: $subtask['prompt'],
                teamName: $teamName,
                model: $this->config['model'] ?? null,
                runInBackground: true,
            );
            
            // Apply template if available
            if (isset($subtask['template'])) {
                if ($this->templateManager->hasTemplate($subtask['template'])) {
                    $config = $this->templateManager->createSpawnConfig(
                        $subtask['template'],
                        ['prompt' => $subtask['prompt']]
                    );
                    $config->teamName = $teamName;
                    $config->runInBackground = true;
                }
            }
            
            $result = $backend->spawn($config);
            if ($result->success) {
                $agents[] = $result->agentId;
                $this->logger->debug("Spawned agent", [
                    'name' => $agentName,
                    'id' => $result->agentId,
                ]);
            }
        }
        
        // Display progress if enabled
        if ($this->config['multi_agent_config']['enable_display'] && $this->output) {
            $display = new ParallelAgentDisplay($this->output);
            $display->displayWithRefresh(
                $this->config['multi_agent_config']['refresh_interval']
            );
        } else {
            // Process agents without display
            $this->processAgentsWithoutDisplay($backend, $agents);
        }
        
        // Collect results
        $results = $this->collectResults($backend, $agents);
        
        // Merge results into a single AgentResult
        return $this->mergeResults($results, $prompt);
    }
    
    /**
     * Parse prompt into subtasks.
     */
    private function parseSubtasks(string $prompt, int $suggestedCount): array
    {
        $subtasks = [];
        
        // Try to identify explicit subtasks
        $lines = explode("\n", $prompt);
        $currentTask = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Check for numbered items
            if (preg_match('/^\d+[\.\)]\s*(.+)/', $line, $matches)) {
                if ($currentTask) {
                    $subtasks[] = $this->createSubtask($currentTask);
                }
                $currentTask = $matches[1];
            }
            // Check for bullet points
            elseif (preg_match('/^[-*]\s+(.+)/', $line, $matches)) {
                if ($currentTask) {
                    $subtasks[] = $this->createSubtask($currentTask);
                }
                $currentTask = $matches[1];
            }
            // Accumulate lines
            elseif ($line) {
                $currentTask .= ' ' . $line;
            }
        }
        
        // Add last task
        if ($currentTask) {
            $subtasks[] = $this->createSubtask($currentTask);
        }
        
        // If no explicit subtasks found, split based on complexity
        if (count($subtasks) <= 1) {
            $subtasks = $this->splitByComplexity($prompt, $suggestedCount);
        }
        
        return $subtasks;
    }
    
    /**
     * Create a subtask definition.
     */
    private function createSubtask(string $prompt): array
    {
        $type = 'general';
        $template = null;
        
        // Detect task type
        $lowerPrompt = strtolower($prompt);
        
        if (strpos($lowerPrompt, 'test') !== false) {
            $type = 'testing';
            $template = 'test_generator';
        } elseif (strpos($lowerPrompt, 'document') !== false) {
            $type = 'documentation';
            $template = 'documentation_writer';
        } elseif (strpos($lowerPrompt, 'review') !== false || strpos($lowerPrompt, 'analyze') !== false) {
            $type = 'analysis';
            $template = 'code_reviewer';
        } elseif (strpos($lowerPrompt, 'security') !== false || strpos($lowerPrompt, 'vulnerability') !== false) {
            $type = 'security';
            $template = 'security_scanner';
        } elseif (strpos($lowerPrompt, 'data') !== false || strpos($lowerPrompt, 'etl') !== false) {
            $type = 'data';
            $template = 'data_processor';
        }
        
        return [
            'prompt' => trim($prompt),
            'type' => $type,
            'template' => $template,
        ];
    }
    
    /**
     * Split prompt by complexity when no explicit subtasks.
     */
    private function splitByComplexity(string $prompt, int $count): array
    {
        $subtasks = [];
        
        // Identify different aspects of the task
        $aspects = [
            'analysis' => ['analyze', 'review', 'examine', 'investigate', '分析', '审查'],
            'implementation' => ['implement', 'create', 'build', 'develop', '实现', '创建'],
            'testing' => ['test', 'validate', 'verify', 'check', '测试', '验证'],
            'documentation' => ['document', 'describe', 'explain', 'write', '文档', '说明'],
            'optimization' => ['optimize', 'improve', 'enhance', 'refactor', '优化', '改进'],
        ];
        
        $lowerPrompt = mb_strtolower($prompt);
        $foundAspects = [];
        
        foreach ($aspects as $aspect => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($lowerPrompt, $keyword) !== false) {
                    $foundAspects[$aspect] = true;
                    break;
                }
            }
        }
        
        // If specific aspects found, create subtasks for each
        if (count($foundAspects) > 0) {
            foreach (array_keys($foundAspects) as $aspect) {
                $subtasks[] = [
                    'prompt' => $prompt . " (Focus: $aspect)",
                    'type' => $aspect,
                    'template' => $this->getTemplateForAspect($aspect),
                ];
            }
        }
        
        // Otherwise, create generic subtasks
        else {
            $taskSize = max(1, (int)(strlen($prompt) / $count));
            for ($i = 0; $i < $count; $i++) {
                $subtasks[] = [
                    'prompt' => $prompt . sprintf(" (Part %d of %d)", $i + 1, $count),
                    'type' => 'general',
                    'template' => null,
                ];
            }
        }
        
        return $subtasks;
    }
    
    /**
     * Get template for a specific aspect.
     */
    private function getTemplateForAspect(string $aspect): ?string
    {
        $templates = [
            'analysis' => 'code_reviewer',
            'implementation' => 'developer',
            'testing' => 'test_generator',
            'documentation' => 'documentation_writer',
            'optimization' => 'performance_optimizer',
        ];
        
        return $templates[$aspect] ?? null;
    }
    
    /**
     * Generate agent name based on type and index.
     */
    private function generateAgentName(string $type, int $index): string
    {
        $names = [
            'analysis' => 'Analyzer',
            'implementation' => 'Developer',
            'testing' => 'Tester',
            'documentation' => 'Documenter',
            'optimization' => 'Optimizer',
            'security' => 'Security Scanner',
            'data' => 'Data Processor',
            'general' => 'Worker',
        ];
        
        $baseName = $names[$type] ?? 'Agent';
        return sprintf("%s-%d", $baseName, $index + 1);
    }
    
    /**
     * Process agents without display.
     */
    private function processAgentsWithoutDisplay(InProcessBackend $backend, array $agentIds): void
    {
        $allComplete = false;
        
        while (!$allComplete) {
            $backend->processMessages();
            
            $allComplete = true;
            foreach ($agentIds as $agentId) {
                if ($backend->isRunning($agentId)) {
                    $allComplete = false;
                    break;
                }
            }
            
            if (!$allComplete) {
                usleep(100000); // 100ms
            }
        }
    }
    
    /**
     * Collect real agent outputs from the coordinator.
     *
     * Each spawn stored a `\SuperAgent\AgentResult` keyed by agentId in
     * `ParallelAgentCoordinator` (see InProcessBackend's fiber). We pull
     * those out here. Agents that failed (no stored result) get a
     * placeholder so downstream merging still has every slot.
     */
    private function collectResults(InProcessBackend $backend, array $agentIds): array
    {
        $coordinator = ParallelAgentCoordinator::getInstance();
        $results = [];

        foreach ($agentIds as $agentId) {
            $status = $backend->getStatus($agentId);
            $agentResult = $coordinator->getAgentResult($agentId);
            $content = $agentResult?->text();
            if ($content === null || $content === '') {
                $content = sprintf('[%s produced no output (status: %s)]', $agentId, $status?->value ?? 'unknown');
            }
            $results[] = [
                'agent_id' => $agentId,
                'status'   => $status,
                'content'  => $content,
                'result'   => $agentResult,
            ];
        }

        return $results;
    }
    
    /**
     * Merge results from multiple agents into a canonical AgentResult.
     *
     * Naive concatenation — for a smarter consolidation that uses a strong
     * model to deduplicate and integrate, see `SmartOrchestrator::merge()`.
     * AutoMode keeps this dumb on purpose: it has no eval-score notion of
     * which model is the strongest, so it just hands the user the union.
     *
     * The returned AgentResult wraps a synthetic AssistantMessage; total
     * cost/usage is summed across constituent agent results so the cost
     * footer in the CLI is accurate.
     */
    private function mergeResults(array $results, string $originalPrompt): AgentResult
    {
        $body = ["Multi-agent execution completed:\n"];
        $allResponses = [];
        $totalCost = 0.0;
        foreach ($results as $r) {
            $body[] = sprintf("--- Agent %s ---\n%s", $r['agent_id'], $r['content']);
            if (($r['result'] ?? null) instanceof AgentResult) {
                /** @var AgentResult $sub */
                $sub = $r['result'];
                $totalCost += (float) $sub->totalCostUsd;
                foreach ($sub->allResponses as $resp) {
                    $allResponses[] = $resp;
                }
            }
        }

        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text(implode("\n", $body))];
        $msg->metadata = [
            'mode'           => 'multi-agent',
            'agent_count'    => count($results),
            'original_prompt'=> $originalPrompt,
        ];

        return new AgentResult(
            message: $msg,
            allResponses: $allResponses === [] ? [$msg] : $allResponses,
            totalCostUsd: $totalCost,
        );
    }
}