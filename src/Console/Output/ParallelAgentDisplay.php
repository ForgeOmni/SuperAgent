<?php

declare(strict_types=1);

namespace SuperAgent\Console\Output;

use Symfony\Component\Console\Output\OutputInterface;
use SuperAgent\Swarm\ParallelAgentCoordinator;

/**
 * Displays parallel agent execution progress similar to Claude Code's TeammateSpinnerTree.
 */
class ParallelAgentDisplay
{
    private OutputInterface $output;
    private ParallelAgentCoordinator $coordinator;
    private bool $useColors;
    
    // ANSI color codes
    private const COLOR_CYAN = "\033[36m";
    private const COLOR_GREEN = "\033[32m";
    private const COLOR_YELLOW = "\033[33m";
    private const COLOR_GRAY = "\033[90m";
    private const COLOR_RESET = "\033[0m";
    private const BOLD = "\033[1m";
    
    // Tree characters
    private const TREE_BRANCH = "├─";
    private const TREE_LAST = "└─";
    private const TREE_PIPE = "│ ";
    private const TREE_SPACE = "  ";
    
    public function __construct(
        OutputInterface $output,
        ?ParallelAgentCoordinator $coordinator = null,
        bool $useColors = true
    ) {
        $this->output = $output;
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance();
        $this->useColors = $useColors && $output->isDecorated();
    }
    
    /**
     * Display the current state of all running agents.
     */
    public function display(): void
    {
        $display = $this->coordinator->getHierarchicalDisplay();
        $progress = $this->coordinator->getConsolidatedProgress();
        
        if (empty($display)) {
            $this->output->writeln($this->colorize("No agents currently running", self::COLOR_GRAY));
            return;
        }
        
        // Display header
        $this->displayHeader($progress);
        
        // Display each team or standalone group
        foreach ($display as $group) {
            if ($group['type'] === 'team') {
                $this->displayTeam($group);
            } else {
                $this->displayStandaloneAgents($group);
            }
        }
    }
    
    /**
     * Display header with overall statistics.
     */
    private function displayHeader(array $progress): void
    {
        $this->output->writeln("");
        $this->output->writeln($this->colorize(
            sprintf(
                "🤖 Agents: %d | 💬 Total Tokens: %s | 🔧 Tool Uses: %d",
                $progress['totalAgents'],
                $this->formatNumber($progress['totalTokens']),
                $progress['totalToolUses']
            ),
            self::COLOR_CYAN,
            true
        ));
        $this->output->writeln($this->colorize(
            str_repeat("─", 60),
            self::COLOR_GRAY
        ));
    }
    
    /**
     * Display a team and its members.
     */
    private function displayTeam(array $team): void
    {
        // Team header
        $this->output->writeln($this->colorize(
            sprintf("📂 Team: %s (Leader: %s)", $team['name'], $team['leaderId']),
            self::COLOR_GREEN,
            true
        ));
        
        // Display members
        $memberCount = count($team['members']);
        foreach ($team['members'] as $index => $member) {
            $isLast = ($index === $memberCount - 1);
            $this->displayAgent($member, 1, $isLast);
        }
    }
    
    /**
     * Display standalone agents.
     */
    private function displayStandaloneAgents(array $group): void
    {
        $this->output->writeln($this->colorize(
            "📌 Standalone Agents",
            self::COLOR_YELLOW,
            true
        ));
        
        $agentCount = count($group['members']);
        foreach ($group['members'] as $index => $agent) {
            $isLast = ($index === $agentCount - 1);
            $this->displayAgent($agent, 1, $isLast);
        }
    }
    
    /**
     * Display a single agent.
     */
    private function displayAgent(array $agent, int $indent = 0, bool $isLast = false): void
    {
        $prefix = str_repeat(self::TREE_SPACE, $indent);
        $branch = $isLast ? self::TREE_LAST : self::TREE_BRANCH;
        
        // Status indicator
        $statusIndicator = $this->getStatusIndicator($agent['status']);
        
        // Agent name and status
        $line = sprintf(
            "%s%s %s %s",
            $prefix,
            $branch,
            $statusIndicator,
            $this->colorize($agent['name'], self::COLOR_CYAN)
        );
        
        // Add activity if present
        if (!empty($agent['currentActivity'])) {
            $line .= $this->colorize(
                sprintf(" : %s", $agent['currentActivity']),
                self::COLOR_GRAY
            );
        }
        
        // Add stats
        if ($agent['tokenCount'] > 0 || $agent['toolUseCount'] > 0) {
            $stats = [];
            if ($agent['tokenCount'] > 0) {
                $stats[] = sprintf("%s tokens", $this->formatNumber($agent['tokenCount']));
            }
            if ($agent['toolUseCount'] > 0) {
                $stats[] = sprintf("%d tools", $agent['toolUseCount']);
            }
            $line .= $this->colorize(
                sprintf(" · %s", implode(" · ", $stats)),
                self::COLOR_GRAY
            );
        }
        
        $this->output->writeln($line);
    }
    
    /**
     * Get status indicator emoji/symbol.
     */
    private function getStatusIndicator(string $status): string
    {
        return match(strtolower($status)) {
            'running' => '🔄',
            'pending' => '⏳',
            'completed' => '✅',
            'failed' => '❌',
            'cancelled' => '⛔',
            'paused' => '⏸️',
            default => '❓'
        };
    }
    
    /**
     * Format a number with thousand separators.
     */
    private function formatNumber(int $number): string
    {
        if ($number < 1000) {
            return (string)$number;
        } elseif ($number < 1000000) {
            return sprintf("%.1fK", $number / 1000);
        } else {
            return sprintf("%.1fM", $number / 1000000);
        }
    }
    
    /**
     * Apply color to text if colors are enabled.
     */
    private function colorize(string $text, string $color, bool $bold = false): string
    {
        if (!$this->useColors) {
            return $text;
        }
        
        $prefix = $bold ? self::BOLD . $color : $color;
        return $prefix . $text . self::COLOR_RESET;
    }
    
    /**
     * Clear the display area.
     */
    public function clear(int $lines = 20): void
    {
        if ($this->output->isDecorated()) {
            // Move cursor up and clear lines
            for ($i = 0; $i < $lines; $i++) {
                $this->output->write("\033[1A\033[2K");
            }
        }
    }
    
    /**
     * Display with auto-refresh.
     */
    public function displayWithRefresh(int $intervalMs = 500, ?callable $stopCondition = null): void
    {
        $lastLineCount = 0;
        
        while (true) {
            // Clear previous display
            if ($lastLineCount > 0) {
                $this->clear($lastLineCount);
            }
            
            // Capture output to count lines
            ob_start();
            $this->display();
            $content = ob_get_clean();
            
            // Display and count lines
            $this->output->write($content);
            $lastLineCount = substr_count($content, "\n");
            
            // Check stop condition
            if ($stopCondition && $stopCondition()) {
                break;
            }
            
            // Check if all agents are done
            $activeTrackers = $this->coordinator->getActiveTrackers();
            if (empty($activeTrackers)) {
                break;
            }
            
            // Sleep before next update
            usleep($intervalMs * 1000);
        }
    }
}