<?php

declare(strict_types=1);

namespace SuperAgent\Debate;

final class DebateProtocol
{
    /**
     * @var callable Agent runner: fn(string $prompt, string $model, ?string $systemPrompt, int $maxTurns, float $maxBudget): array{content: string, cost: float, turns: int}
     */
    private $agentRunner;

    public function __construct(callable $agentRunner)
    {
        $this->agentRunner = $agentRunner;
    }

    /**
     * Run structured debate rounds between proposer and critic.
     *
     * @return DebateRound[]
     */
    public function runDebateRounds(
        DebateConfig $config,
        string $topic,
    ): array {
        $rounds = [];
        $context = '';
        $totalCost = 0.0;
        $budgetPerRound = $config->maxBudget / max(1, $config->rounds);

        for ($i = 1; $i <= $config->rounds; $i++) {
            $roundStart = microtime(true);
            $roundCost = 0.0;

            // Proposer argues
            $proposerPrompt = $this->buildProposerPrompt($topic, $context, $i, $config->rounds);
            $proposerSystemPrompt = $config->proposerSystemPrompt
                ?? 'You are a skilled proposer in a structured debate. Present clear, well-reasoned arguments with concrete evidence and examples.';

            $proposerResult = ($this->agentRunner)(
                $proposerPrompt,
                $config->proposerModel,
                $proposerSystemPrompt,
                $config->maxTurnsPerRound,
                $budgetPerRound / 3,
            );
            $roundCost += $proposerResult['cost'] ?? 0.0;

            // Critic responds
            $criticPrompt = $this->buildCriticPrompt($topic, $proposerResult['content'], $i);
            $criticSystemPrompt = $config->criticSystemPrompt
                ?? 'You are a rigorous critic in a structured debate. Find flaws, edge cases, unstated assumptions, and weaknesses. Be specific and constructive.';

            $criticResult = ($this->agentRunner)(
                $criticPrompt,
                $config->criticModel,
                $criticSystemPrompt,
                $config->maxTurnsPerRound,
                $budgetPerRound / 3,
            );
            $roundCost += $criticResult['cost'] ?? 0.0;

            // Proposer rebuts (optional, skip in last round)
            $rebuttal = null;
            if ($i < $config->rounds) {
                $rebuttalPrompt = $this->buildRebuttalPrompt($topic, $proposerResult['content'], $criticResult['content'], $i);
                $rebuttalResult = ($this->agentRunner)(
                    $rebuttalPrompt,
                    $config->proposerModel,
                    $proposerSystemPrompt,
                    $config->maxTurnsPerRound,
                    $budgetPerRound / 3,
                );
                $rebuttal = $rebuttalResult['content'];
                $roundCost += $rebuttalResult['cost'] ?? 0.0;
            }

            $roundDuration = (microtime(true) - $roundStart) * 1000;
            $totalCost += $roundCost;

            $round = new DebateRound(
                roundNumber: $i,
                proposerArgument: $proposerResult['content'],
                criticResponse: $criticResult['content'],
                proposerRebuttal: $rebuttal,
                roundCost: $roundCost,
                durationMs: $roundDuration,
            );

            $rounds[] = $round;

            // Build context for next round
            $context = $round->getSummary();

            // Budget check
            if ($totalCost >= $config->maxBudget) {
                break;
            }
        }

        return $rounds;
    }

    /**
     * Run judgment on completed debate rounds.
     */
    public function runJudgment(
        string $judgeModel,
        ?string $judgeSystemPrompt,
        array $rounds,
        string $topic,
        string $criteria,
        float $maxBudget,
    ): array {
        $systemPrompt = $judgeSystemPrompt
            ?? 'You are an impartial judge reviewing a structured debate. Analyze all arguments objectively and provide a clear verdict with reasoning.';

        $roundsSummary = '';
        foreach ($rounds as $round) {
            $roundsSummary .= $round->getSummary() . "\n";
        }

        $judgePrompt = <<<PROMPT
You are judging a debate on the following topic:

**Topic:** {$topic}

**Judging Criteria:** {$criteria}

**Debate Transcript:**
{$roundsSummary}

Please provide:
1. **Verdict**: Which position is stronger and why
2. **Key Strengths**: Best arguments from each side
3. **Key Weaknesses**: Main flaws in each side's reasoning
4. **Recommendation**: A concrete, actionable recommendation that synthesizes the best of both positions
PROMPT;

        return ($this->agentRunner)(
            $judgePrompt,
            $judgeModel,
            $systemPrompt,
            5,
            $maxBudget,
        );
    }

    /**
     * Run red-team attack/defense rounds.
     *
     * @return array{rounds: array, total_cost: float}
     */
    public function runRedTeamRounds(
        RedTeamConfig $config,
        string $task,
    ): array {
        $rounds = [];
        $currentSolution = '';
        $totalCost = 0.0;
        $budgetPerRound = $config->maxBudget / max(1, $config->rounds);

        $builderSystemPrompt = $config->builderSystemPrompt
            ?? 'You are a builder creating robust solutions. Address all identified issues thoroughly.';
        $attackerSystemPrompt = $config->attackerSystemPrompt
            ?? 'You are a security/quality reviewer. Find vulnerabilities, edge cases, and failure modes. Be thorough and specific.';

        for ($i = 1; $i <= $config->rounds; $i++) {
            $roundStart = microtime(true);
            $roundCost = 0.0;

            // Builder creates/improves solution
            $builderPrompt = $i === 1
                ? "Create a solution for the following task:\n\n{$task}"
                : "Improve your solution based on these issues found:\n\n{$currentSolution}\n\nAddress all identified problems.";

            $builderResult = ($this->agentRunner)(
                $builderPrompt,
                $config->builderModel,
                $builderSystemPrompt,
                $config->maxTurnsPerRound,
                $budgetPerRound / 2,
            );
            $roundCost += $builderResult['cost'] ?? 0.0;
            $currentSolution = $builderResult['content'];

            // Attacker finds issues
            $vectors = implode(', ', $config->attackVectors);
            $attackerPrompt = "Review this solution and find all issues. Focus on: {$vectors}\n\n**Solution:**\n{$currentSolution}";

            $attackerResult = ($this->agentRunner)(
                $attackerPrompt,
                $config->attackerModel,
                $attackerSystemPrompt,
                $config->maxTurnsPerRound,
                $budgetPerRound / 2,
            );
            $roundCost += $attackerResult['cost'] ?? 0.0;

            $roundDuration = (microtime(true) - $roundStart) * 1000;
            $totalCost += $roundCost;

            $rounds[] = new DebateRound(
                roundNumber: $i,
                proposerArgument: $builderResult['content'],
                criticResponse: $attackerResult['content'],
                roundCost: $roundCost,
                durationMs: $roundDuration,
            );

            $currentSolution = "Previous solution:\n{$builderResult['content']}\n\nIssues found:\n{$attackerResult['content']}";

            if ($totalCost >= $config->maxBudget) {
                break;
            }
        }

        return ['rounds' => $rounds, 'total_cost' => $totalCost];
    }

    /**
     * Run ensemble merge: combine N independent solutions.
     */
    public function runEnsembleMerge(
        string $mergerModel,
        array $solutions,
        string $task,
        string $criteria,
        float $maxBudget,
    ): array {
        $solutionsList = '';
        foreach ($solutions as $i => $solution) {
            $num = $i + 1;
            $solutionsList .= "### Solution {$num}\n{$solution}\n\n";
        }

        $mergePrompt = <<<PROMPT
You are synthesizing multiple independent solutions to the same task.

**Task:** {$task}

**Merge Criteria:** {$criteria}

**Solutions:**
{$solutionsList}

Please:
1. Identify the best elements from each solution
2. Note any contradictions or conflicts
3. Produce a single merged solution that combines the strengths of all inputs
4. Explain what you took from each solution and why
PROMPT;

        return ($this->agentRunner)(
            $mergePrompt,
            $mergerModel,
            'You are an expert at synthesizing multiple approaches into an optimal combined solution.',
            10,
            $maxBudget,
        );
    }

    private function buildProposerPrompt(string $topic, string $context, int $round, int $totalRounds): string
    {
        $prompt = "**Debate Topic:** {$topic}\n\n";
        if ($context !== '') {
            $prompt .= "**Previous rounds:**\n{$context}\n\n";
        }
        $prompt .= "This is round {$round} of {$totalRounds}. Present your strongest argument.";
        return $prompt;
    }

    private function buildCriticPrompt(string $topic, string $argument, int $round): string
    {
        return "**Debate Topic:** {$topic}\n\n**Proposer's argument (Round {$round}):**\n{$argument}\n\nCritically analyze this argument. Find flaws, missing considerations, and edge cases.";
    }

    private function buildRebuttalPrompt(string $topic, string $argument, string $criticism, int $round): string
    {
        return "**Debate Topic:** {$topic}\n\n**Your original argument (Round {$round}):**\n{$argument}\n\n**Critic's response:**\n{$criticism}\n\nAddress the criticism and strengthen your position.";
    }
}
