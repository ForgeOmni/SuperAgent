<?php

declare(strict_types=1);

namespace SuperAgent\AutoMode;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Agent\Agent;
use SuperAgent\Agent\AgentResult;
use SuperAgent\Context\Context;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\Templates\AgentTemplateManager;
use SuperAgent\Console\Output\ParallelAgentDisplay;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Agent that automatically decides between single and multi-agent mode.
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
            // Use multi-agent mode
            $suggestion = $this->analyzer->suggestConfiguration($analysis);
            return $this->runMultiAgent($prompt, $suggestion, $options);
        } else {
            // Use single agent mode
            return $this->runSingleAgent($prompt, $options);
        }
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
     * Collect results from all agents.
     */
    private function collectResults(InProcessBackend $backend, array $agentIds): array
    {
        $results = [];
        
        foreach ($agentIds as $agentId) {
            $status = $backend->getStatus($agentId);
            
            // Get agent's output (would need to be implemented in backend)
            // For now, create a placeholder result
            $results[] = [
                'agent_id' => $agentId,
                'status' => $status,
                'content' => "Agent $agentId completed its task",
            ];
        }
        
        return $results;
    }
    
    /**
     * Merge results from multiple agents.
     */
    private function mergeResults(array $results, string $originalPrompt): AgentResult
    {
        // Combine all agent outputs
        $combinedContent = "Multi-agent execution completed:\n\n";
        $totalTokens = 0;
        
        foreach ($results as $result) {
            $combinedContent .= sprintf(
                "Agent %s:\n%s\n\n",
                $result['agent_id'],
                $result['content']
            );
        }
        
        // Create merged result
        return new AgentResult(
            content: $combinedContent,
            model: $this->config['model'] ?? 'unknown',
            usage: [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => $totalTokens,
            ],
            metadata: [
                'mode' => 'multi-agent',
                'agent_count' => count($results),
                'original_prompt' => $originalPrompt,
            ]
        );
    }
}