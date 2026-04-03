#!/usr/bin/env php
<?php

/**
 * Demo: Parallel Agent Execution with Real-time Progress Tracking
 * 
 * This example shows how SuperAgent now tracks multiple agents running in parallel,
 * similar to Claude Code's team management system.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Console\Output\ParallelAgentDisplay;
use Symfony\Component\Console\Output\ConsoleOutput;

// Reset coordinator for clean demo
ParallelAgentCoordinator::reset();

// Create output and display
$output = new ConsoleOutput();
$coordinator = ParallelAgentCoordinator::getInstance();
$display = new ParallelAgentDisplay($output, $coordinator);

// Create a team
$team = new TeamContext('research_team', 'lead_researcher');
$coordinator->registerTeam($team);

// Create backend with team context
$backend = new InProcessBackend();
$backend->setTeamContext($team);

// Simulate spawning multiple agents
$output->writeln("\n<info>🚀 Spawning parallel agents...</info>\n");

// Agent 1: Data Processor
$config1 = new AgentSpawnConfig(
    name: 'DataProcessor',
    prompt: 'Process customer data and generate insights',
    teamName: 'research_team',
    runInBackground: false,
);
$result1 = $backend->spawn($config1);
$tracker1 = $coordinator->getTracker($result1->agentId);

// Agent 2: Code Analyzer
$config2 = new AgentSpawnConfig(
    name: 'CodeAnalyzer',
    prompt: 'Analyze codebase for security vulnerabilities',
    teamName: 'research_team',
    runInBackground: false,
);
$result2 = $backend->spawn($config2);
$tracker2 = $coordinator->getTracker($result2->agentId);

// Agent 3: Report Writer (standalone)
$config3 = new AgentSpawnConfig(
    name: 'ReportWriter',
    prompt: 'Generate comprehensive report',
    teamName: null, // Standalone agent
    runInBackground: false,
);
$result3 = $backend->spawn($config3);
$tracker3 = $coordinator->registerAgent($result3->agentId, 'ReportWriter');

// Simulate agent activities
$output->writeln("<comment>📊 Simulating agent activities...</comment>\n");

// Simulate progress updates
$activities = [
    ['tracker' => $tracker1, 'tool' => ['name' => 'Read', 'input' => ['file_path' => 'customers.csv']], 'tokens' => ['input_tokens' => 1200, 'output_tokens' => 600]],
    ['tracker' => $tracker2, 'tool' => ['name' => 'Grep', 'input' => ['pattern' => 'eval\\(.*\\)']], 'tokens' => ['input_tokens' => 2500, 'output_tokens' => 800]],
    ['tracker' => $tracker3, 'tool' => ['name' => 'Write', 'input' => ['file_path' => 'report.md']], 'tokens' => ['input_tokens' => 3000, 'output_tokens' => 1500]],
    ['tracker' => $tracker1, 'tool' => ['name' => 'Bash', 'input' => ['command' => 'python analyze.py']], 'tokens' => ['input_tokens' => 1500, 'output_tokens' => 700]],
    ['tracker' => $tracker2, 'tool' => ['name' => 'Read', 'input' => ['file_path' => 'src/auth.php']], 'tokens' => ['input_tokens' => 2800, 'output_tokens' => 900]],
    ['tracker' => $tracker3, 'tool' => ['name' => 'Edit', 'input' => ['file_path' => 'report.md']], 'tokens' => ['input_tokens' => 3200, 'output_tokens' => 1600]],
];

foreach ($activities as $i => $activity) {
    // Update tracker with activity
    $activity['tracker']->updateFromResponse($activity['tokens']);
    $activity['tracker']->addToolActivity($activity['tool']);
    
    // Display current state
    $output->write("\033[2J\033[H"); // Clear screen
    $output->writeln("\n<info>🎯 Parallel Agent Execution Demo</info>");
    $output->writeln("<comment>Step " . ($i + 1) . "/" . count($activities) . "</comment>\n");
    
    // Show the display
    $display->display();
    
    // Show recent activity
    $output->writeln("\n<comment>Latest Activity:</comment>");
    $progress = $activity['tracker']->getProgress();
    $output->writeln(sprintf(
        "  • %s: %s",
        $progress['agentName'],
        $progress['currentActivity']
    ));
    
    // Pause for visualization
    sleep(1);
}

// Show final consolidated progress
$output->write("\033[2J\033[H"); // Clear screen
$output->writeln("\n<info>✅ All Agents Completed</info>\n");

// Complete all trackers
$tracker1->complete();
$tracker2->complete();
$tracker3->complete();

// Display final state
$display->display();

// Show consolidated statistics
$finalProgress = $coordinator->getConsolidatedProgress();
$output->writeln("\n<info>📈 Final Statistics:</info>");
$output->writeln(sprintf("  • Total Agents: %d", $finalProgress['totalAgents']));
$output->writeln(sprintf("  • Total Tokens: %s", number_format($finalProgress['totalTokens'])));
$output->writeln(sprintf("  • Total Tool Uses: %d", $finalProgress['totalToolUses']));

// Show hierarchical structure
$output->writeln("\n<info>🏢 Team Structure:</info>");
$hierarchy = $coordinator->getHierarchicalDisplay();
foreach ($hierarchy as $group) {
    if ($group['type'] === 'team') {
        $output->writeln(sprintf("  📂 Team: %s", $group['name']));
        foreach ($group['members'] as $member) {
            $output->writeln(sprintf("    └─ %s (%d tokens, %d tools)", 
                $member['name'], 
                $member['tokenCount'], 
                $member['toolUseCount']
            ));
        }
    } else {
        $output->writeln("  📌 Standalone Agents:");
        foreach ($group['members'] as $member) {
            $output->writeln(sprintf("    └─ %s (%d tokens, %d tools)", 
                $member['name'], 
                $member['tokenCount'], 
                $member['toolUseCount']
            ));
        }
    }
}

$output->writeln("\n<success>✨ Demo complete! SuperAgent now tracks parallel agents like Claude Code!</success>\n");