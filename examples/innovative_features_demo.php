<?php

/**
 * SuperAgent v0.8.0 - Innovative Features Demo
 *
 * Demonstrates all 6 new features:
 * 1. Agent Replay & Time-Travel Debugging
 * 2. Conversation Forking
 * 3. Agent Debate Protocol
 * 4. Cost Prediction Engine
 * 5. Natural Language Guardrails
 * 6. Self-Healing Pipelines
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent;
use SuperAgent\Replay\ReplayRecorder;
use SuperAgent\Replay\ReplayPlayer;
use SuperAgent\Replay\ReplayStore;
use SuperAgent\Fork\ForkManager;
use SuperAgent\Fork\ForkExecutor;
use SuperAgent\Fork\ForkScorer;
use SuperAgent\Debate\DebateOrchestrator;
use SuperAgent\Debate\DebateConfig;
use SuperAgent\Debate\RedTeamConfig;
use SuperAgent\Debate\EnsembleConfig;
use SuperAgent\CostPrediction\CostPredictor;
use SuperAgent\CostPrediction\CostHistoryStore;
use SuperAgent\Guardrails\NaturalLanguage\NLGuardrailFacade;
use SuperAgent\Pipeline\SelfHealing\SelfHealingStrategy;
use SuperAgent\Pipeline\SelfHealing\StepFailure;

echo "=== SuperAgent v0.8.0 Innovative Features Demo ===\n\n";

// ─────────────────────────────────────────────────
// 1. AGENT REPLAY & TIME-TRAVEL DEBUGGING
// ─────────────────────────────────────────────────
echo "--- 1. Agent Replay & Time-Travel Debugging ---\n";

$recorder = new ReplayRecorder('session-demo-001', snapshotInterval: 3);

// Simulate recording an agent execution
$recorder->recordLlmCall('main', 'claude-sonnet-4-6', [], 'I will read the file first.', [
    'input_tokens' => 1500,
    'output_tokens' => 200,
], 1200.0);

$recorder->recordToolCall('main', 'read', 'tool-1', ['path' => 'src/Agent.php'], 'file contents...', 50.0);
$recorder->recordToolCall('main', 'edit', 'tool-2', ['path' => 'src/Agent.php'], 'edit applied', 30.0);
$recorder->recordAgentSpawn('agent-2', 'main', 'test-writer', ['model' => 'haiku']);
$recorder->recordAgentMessage('main', 'main', 'agent-2', 'Write tests for Agent.php');

$recorder->recordStateSnapshot('main', [], 2, 0.05, ['main', 'agent-2']);

$trace = $recorder->finalize();

// Replay and inspect
$player = new ReplayPlayer($trace);
$state = $player->stepTo(3);
echo "  At step 3: {$state->turnCount} turns, \${$state->costSoFar} cost\n";

$agentInfo = $player->inspect('main');
echo "  Main agent: {$agentInfo['llm_calls']} LLM calls, " . count($agentInfo['tool_calls']) . " tool calls\n";

$timeline = $player->getTimeline();
echo "  Timeline has " . count($timeline) . " entries\n";

// Persist and reload
$store = new ReplayStore(sys_get_temp_dir() . '/superagent_replay_demo');
$store->save($trace);
$loaded = $store->load('session-demo-001');
echo "  Loaded trace: {$loaded->count()} events, \${$loaded->totalCost} total cost\n\n";

// Cleanup
$store->delete('session-demo-001');

// ─────────────────────────────────────────────────
// 2. CONVERSATION FORKING
// ─────────────────────────────────────────────────
echo "--- 2. Conversation Forking ---\n";

$executor = new ForkExecutor(defaultTimeout: 30);
$forkManager = new ForkManager($executor);

// Create a fork session (won't execute without agent-runner, just demo the API)
$session = $forkManager->forkWithVariants(
    messages: [['role' => 'user', 'content' => 'Refactor this service']],
    turnCount: 5,
    prompts: [
        'Refactor using the Strategy pattern',
        'Refactor using the Command pattern',
        'Refactor using simple function extraction',
    ],
    config: ['model' => 'sonnet', 'max_turns' => 10],
);

echo "  Fork session: {$session->id}\n";
echo "  Branches: {$session->getBranchCount()}\n";
foreach ($session->getBranches() as $branch) {
    echo "    - [{$branch->id}] {$branch->prompt}\n";
}

// Scoring
echo "  Built-in scorers: costEfficiency, completeness, brevity, composite\n\n";

// ─────────────────────────────────────────────────
// 3. AGENT DEBATE PROTOCOL
// ─────────────────────────────────────────────────
echo "--- 3. Agent Debate Protocol ---\n";

// Configure a debate
$debateConfig = DebateConfig::create()
    ->withProposerModel('opus')
    ->withCriticModel('sonnet')
    ->withJudgeModel('opus')
    ->withRounds(3)
    ->withMaxBudget(5.0)
    ->withJudgingCriteria('Evaluate correctness, maintainability, and performance');

echo "  Debate config: {$debateConfig->rounds} rounds, proposer={$debateConfig->proposerModel}, critic={$debateConfig->criticModel}\n";

// Configure red-team
$redTeamConfig = RedTeamConfig::create()
    ->withBuilderModel('opus')
    ->withAttackerModel('sonnet')
    ->withAttackVectors(['security', 'edge_cases', 'race_conditions'])
    ->withRounds(3);

echo "  Red-team config: vectors=" . implode(', ', $redTeamConfig->attackVectors) . "\n";

// Configure ensemble
$ensembleConfig = EnsembleConfig::create()
    ->withAgentCount(3)
    ->withModels(['opus', 'sonnet', 'haiku'])
    ->withMergerModel('opus')
    ->withParallel(true);

echo "  Ensemble config: {$ensembleConfig->agents} agents, merger={$ensembleConfig->mergerModel}\n\n";

// ─────────────────────────────────────────────────
// 4. COST PREDICTION ENGINE
// ─────────────────────────────────────────────────
echo "--- 4. Cost Prediction Engine ---\n";

$historyStore = new CostHistoryStore(sys_get_temp_dir() . '/superagent_cost_demo');
$predictor = new CostPredictor($historyStore);

// Estimate costs for different prompts
$prompts = [
    'Fix the typo in README.md',
    'Refactor the authentication service to use JWT tokens',
    'Write comprehensive unit tests for all controllers',
];

foreach ($prompts as $prompt) {
    $estimate = $predictor->estimate($prompt, 'claude-sonnet-4-6');
    echo "  \"{$prompt}\"\n";
    echo "    {$estimate->format()}\n";
}

// Compare models
$comparison = $predictor->compareModels('Refactor the authentication service', ['opus', 'sonnet', 'haiku']);
echo "\n  Model comparison for refactoring task:\n";
foreach ($comparison as $model => $est) {
    echo "    {$model}: \${$est->estimatedCost} (range: \${$est->lowerBound}-\${$est->upperBound})\n";
}
echo "\n";

// Cleanup
@array_map('unlink', glob(sys_get_temp_dir() . '/superagent_cost_demo/*.json'));
@rmdir(sys_get_temp_dir() . '/superagent_cost_demo');

// ─────────────────────────────────────────────────
// 5. NATURAL LANGUAGE GUARDRAILS
// ─────────────────────────────────────────────────
echo "--- 5. Natural Language Guardrails ---\n";

$guardrails = NLGuardrailFacade::create()
    ->rule('Never modify files in database/migrations')
    ->rule('If cost exceeds $5, pause and ask for approval')
    ->rule('Max 10 bash calls per minute')
    ->rule("Don't touch .env files")
    ->rule('Warn if modifying config files')
    ->rule('Block all web searches');

$compiled = $guardrails->compile();

echo "  Compiled {$compiled->totalRules} rules:\n";
echo "    High confidence: {$compiled->highConfidenceCount}\n";
echo "    Needs review: {$compiled->needsReviewCount}\n";

foreach ($compiled->rules as $rule) {
    $status = $rule->needsReview ? '[REVIEW]' : '[OK]';
    $conf = (int) ($rule->confidence * 100);
    echo "    {$status} ({$conf}%) [{$rule->groupName}] {$rule->originalText}\n";
}

echo "\n  Generated YAML preview:\n";
$yaml = $guardrails->toYaml();
$yamlLines = explode("\n", $yaml);
foreach (array_slice($yamlLines, 0, 10) as $line) {
    echo "    {$line}\n";
}
echo "    ... (" . count($yamlLines) . " lines total)\n\n";

// ─────────────────────────────────────────────────
// 6. SELF-HEALING PIPELINES
// ─────────────────────────────────────────────────
echo "--- 6. Self-Healing Pipelines ---\n";

$healer = new SelfHealingStrategy(config: [
    'max_heal_attempts' => 3,
    'diagnose_model' => 'sonnet',
    'max_diagnose_budget' => 0.50,
]);

// Simulate a step failure
$failure = new StepFailure(
    stepName: 'deploy_service',
    stepType: 'agent',
    stepConfig: ['prompt' => 'Deploy the service to staging', 'timeout' => 60],
    errorMessage: 'Connection timed out after 60 seconds',
    errorClass: 'RuntimeException',
    stackTrace: null,
    attemptNumber: 1,
);

echo "  Failure: {$failure->errorMessage}\n";
echo "  Category: {$failure->getErrorCategory()}\n";
echo "  Recoverable: " . ($failure->isRecoverable() ? 'yes' : 'no') . "\n";
echo "  Can heal: " . ($healer->canHeal($failure) ? 'yes' : 'no') . "\n";

// Simulate healing with a retry callback
$healResult = $healer->heal($failure, function (array $mutatedConfig) {
    // Simulate success on retry with adjusted timeout
    if (($mutatedConfig['timeout'] ?? 0) >= 300) {
        return 'Deployment successful!';
    }
    throw new \RuntimeException('Still timing out');
});

echo "  Healed: " . ($healResult->wasHealed() ? 'yes' : 'no') . "\n";
echo "  Attempts used: {$healResult->attemptsUsed}\n";
echo "  Summary: {$healResult->summary}\n";

echo "\n=== Demo Complete ===\n";
