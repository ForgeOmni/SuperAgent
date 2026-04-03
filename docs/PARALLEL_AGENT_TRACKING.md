# Parallel Agent Tracking Implementation

## Overview

SuperAgent now supports comprehensive tracking of multiple agents running in parallel, similar to Claude Code's team management system. This enhancement addresses the limitation where only single agent execution was tracked, despite using PHP Fibers for concurrent execution.

## Key Components

### 1. **AgentProgressTracker** (`src/Swarm/AgentProgressTracker.php`)
Individual agent progress tracking with:
- Token counting (input/output/cache tokens)
- Tool use tracking with activity descriptions
- Real-time activity monitoring
- Duration tracking
- Human-readable activity descriptions for common tools

### 2. **ParallelAgentCoordinator** (`src/Swarm/ParallelAgentCoordinator.php`)
Central coordinator for multiple agents:
- Singleton pattern for global coordination
- Team management and hierarchy tracking
- Consolidated progress reporting
- Fiber management for concurrent execution
- Message queueing between agents

### 3. **ParallelAgentDisplay** (`src/Console/Output/ParallelAgentDisplay.php`)
Console visualization similar to Claude Code's TeammateSpinnerTree:
- Hierarchical display of teams and agents
- Real-time progress updates
- Token and tool use statistics
- Activity status indicators
- Auto-refresh capability

### 4. **Enhanced InProcessBackend** (`src/Swarm/Backends/InProcessBackend.php`)
Integration with parallel tracking:
- Automatic registration with coordinator
- Progress tracking during fiber execution
- Team context support
- Parallel progress reporting methods

## Usage Example

```php
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Console\Output\ParallelAgentDisplay;

// Create team and coordinator
$coordinator = ParallelAgentCoordinator::getInstance();
$team = new TeamContext('research_team', 'lead_researcher');
$coordinator->registerTeam($team);

// Create backend with team
$backend = new InProcessBackend();
$backend->setTeamContext($team);

// Spawn multiple agents
$config1 = new AgentSpawnConfig(
    name: 'DataProcessor',
    prompt: 'Process data',
    teamName: 'research_team',
);
$backend->spawn($config1);

$config2 = new AgentSpawnConfig(
    name: 'CodeAnalyzer',
    prompt: 'Analyze code',
    teamName: 'research_team',
);
$backend->spawn($config2);

// Get parallel progress
$progress = $backend->getParallelProgress();
echo "Total agents: " . $progress['totalAgents'] . "\n";
echo "Total tokens: " . $progress['totalTokens'] . "\n";

// Display hierarchical view
$display = new ParallelAgentDisplay($output, $coordinator);
$display->display();
```

## Display Output Example

```
🤖 Agents: 3 | 💬 Total Tokens: 13.6K | 🔧 Tool Uses: 6
────────────────────────────────────────────────────────────
📂 Team: research_team (Leader: lead_researcher)
  ├─ 🔄 DataProcessor : Running: python analyze.py · 2.8K tokens · 2 tools
  └─ 🔄 CodeAnalyzer : Reading src/auth.php · 4.5K tokens · 2 tools
📌 Standalone Agents
  └─ ⏳ ReportWriter : Editing report.md · 6.3K tokens · 2 tools
```

## Features Comparison with Claude Code

| Feature | Claude Code | SuperAgent (New) | Notes |
|---------|-------------|------------------|-------|
| Individual agent tracking | ✅ | ✅ | Token counts, tool uses |
| Team hierarchy | ✅ | ✅ | Teams with leaders and members |
| Real-time progress | ✅ | ✅ | Live activity updates |
| Tree display | ✅ | ✅ | Hierarchical console output |
| Message passing | ✅ | ✅ | Queue messages between agents |
| Activity descriptions | ✅ | ✅ | Human-readable tool descriptions |
| Token tracking | ✅ | ✅ | Input/output/cache tokens |
| Concurrent execution | ✅ | ✅ | PHP Fibers for parallelism |

## Testing

Run the comprehensive test suite:
```bash
php vendor/bin/phpunit tests/Unit/ParallelAgentTrackingTest.php
```

Run the interactive demo:
```bash
php examples/parallel_agents_demo.php
```

## Architecture Benefits

1. **Separation of Concerns**: Progress tracking is separate from execution logic
2. **Scalability**: Coordinator can handle many agents without modification
3. **Extensibility**: Easy to add new tracking metrics or display formats
4. **Compatibility**: Works with existing InProcessBackend without breaking changes
5. **Testability**: Each component can be tested in isolation

## Future Enhancements

- [ ] WebSocket support for real-time browser display
- [ ] Agent communication protocols
- [ ] Performance metrics and profiling
- [ ] Agent dependency management
- [ ] Distributed backend support
- [ ] Persistent progress storage

## Migration Guide

Existing code continues to work without changes. To enable parallel tracking:

1. Use `ParallelAgentCoordinator::getInstance()` to get the coordinator
2. Create teams with `TeamContext` for grouped agents
3. Use `ParallelAgentDisplay` for visualization
4. Access consolidated progress via `getParallelProgress()`

No breaking changes to existing APIs.