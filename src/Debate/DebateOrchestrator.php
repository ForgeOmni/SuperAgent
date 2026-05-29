<?php

declare(strict_types=1);

namespace SuperAgent\Debate;

use SuperAgent\Tracing\TraceCollector;

final class DebateOrchestrator
{
    /**
     * @var callable Agent runner: fn(string $prompt, string $model, ?string $systemPrompt, int $maxTurns, float $maxBudget): array{content: string, cost: float, turns: int}
     */
    private $agentRunner;

    private array $defaultConfig;

    public function __construct(callable $agentRunner, array $defaultConfig = [])
    {
        $this->agentRunner = $agentRunner;
        $this->defaultConfig = $defaultConfig;
    }

    /**
     * Run a structured debate between proposer, critic, and judge.
     */
    public function debate(DebateConfig $config, string $topic): DebateResult
    {
        $startTime = microtime(true);
        $startMicros = (int) ($startTime * 1_000_000);
        $tracer = TraceCollector::getInstance();
        $tid = $this->traceTid($topic);

        $tracer->emitInstant(
            name: 'debate.start',
            category: 'debate',
            tid: $tid,
            args: [
                'proposer_model' => $config->proposerModel,
                'critic_model'   => $config->criticModel,
                'judge_model'    => $config->judgeModel,
                'topic_preview'  => mb_substr($topic, 0, 120),
            ],
        );

        $protocol = new DebateProtocol($this->agentRunner);

        // Run debate rounds — emit a per-round duration span.
        $roundsStart = (int) (microtime(true) * 1_000_000);
        $rounds = $protocol->runDebateRounds($config, $topic);
        $tracer->emitDuration(
            name: 'debate.rounds',
            category: 'debate',
            tid: $tid,
            startMicros: $roundsStart,
            durationMicros: (int) ((microtime(true) * 1_000_000)) - $roundsStart,
            args: ['round_count' => count($rounds)],
        );

        foreach ($rounds as $r) {
            $tracer->emitInstant(
                name: 'debate.round_' . $r->roundNumber,
                category: 'debate',
                tid: $tid,
                args: [
                    'round'      => $r->roundNumber,
                    'round_cost' => $r->roundCost,
                    'has_rebuttal' => $r->proposerRebuttal !== null,
                ],
            );
        }

        $roundsCost = array_sum(array_map(fn(DebateRound $r) => $r->roundCost, $rounds));
        $remainingBudget = max(0.5, $config->maxBudget - $roundsCost);

        // Judge the debate — span the judgment call separately.
        $judgeStart = (int) (microtime(true) * 1_000_000);
        $judgment = $protocol->runJudgment(
            $config->judgeModel,
            $config->judgeSystemPrompt,
            $rounds,
            $topic,
            $config->judgingCriteria,
            $remainingBudget,
        );
        $tracer->emitDuration(
            name: 'debate.judge',
            category: 'debate',
            tid: $tid,
            startMicros: $judgeStart,
            durationMicros: (int) ((microtime(true) * 1_000_000)) - $judgeStart,
            args: [
                'model' => $config->judgeModel,
                'cost'  => $judgment['cost'] ?? 0.0,
                'turns' => $judgment['turns'] ?? 1,
            ],
        );

        $totalCost = $roundsCost + ($judgment['cost'] ?? 0.0);
        $totalDuration = (microtime(true) - $startTime) * 1000;

        $tracer->emitDuration(
            name: 'debate.total',
            category: 'debate',
            tid: $tid,
            startMicros: $startMicros,
            durationMicros: (int) ($totalDuration * 1000),
            args: [
                'total_cost'  => $totalCost,
                'rounds_cost' => $roundsCost,
                'round_count' => count($rounds),
            ],
        );
        $totalTurns = array_sum(array_map(fn(DebateRound $r) => 2 + ($r->proposerRebuttal !== null ? 1 : 0), $rounds)) + ($judgment['turns'] ?? 1);

        return new DebateResult(
            type: 'debate',
            topic: $topic,
            rounds: $rounds,
            finalVerdict: $judgment['content'],
            recommendation: $this->extractRecommendation($judgment['content']),
            totalCost: $totalCost,
            totalDurationMs: $totalDuration,
            agentContributions: [
                'proposer' => ['model' => $config->proposerModel, 'rounds' => count($rounds)],
                'critic' => ['model' => $config->criticModel, 'rounds' => count($rounds)],
                'judge' => ['model' => $config->judgeModel, 'cost' => $judgment['cost'] ?? 0.0],
            ],
            totalTurns: $totalTurns,
        );
    }

    /**
     * Run a red-team session: builder creates, attacker finds issues, reviewer synthesizes.
     */
    public function redTeam(RedTeamConfig $config, string $task): DebateResult
    {
        $startTime = microtime(true);
        $startMicros = (int) ($startTime * 1_000_000);
        $tracer = TraceCollector::getInstance();
        $tid = $this->traceTid($task);

        $tracer->emitInstant(
            name: 'redteam.start',
            category: 'debate',
            tid: $tid,
            args: [
                'builder_model'  => $config->builderModel,
                'attacker_model' => $config->attackerModel,
                'reviewer_model' => $config->reviewerModel,
                'rounds_planned' => $config->rounds,
            ],
        );

        $protocol = new DebateProtocol($this->agentRunner);

        $roundsStart = (int) (microtime(true) * 1_000_000);
        $result = $protocol->runRedTeamRounds($config, $task);
        $rounds = $result['rounds'];
        $roundsCost = $result['total_cost'];

        $tracer->emitDuration(
            name: 'redteam.rounds',
            category: 'debate',
            tid: $tid,
            startMicros: $roundsStart,
            durationMicros: (int) ((microtime(true) * 1_000_000)) - $roundsStart,
            args: ['round_count' => count($rounds), 'rounds_cost' => $roundsCost],
        );

        // Final review/synthesis
        $remainingBudget = max(0.5, $config->maxBudget - $roundsCost);
        $lastRound = end($rounds);
        $finalSolution = $lastRound instanceof DebateRound ? $lastRound->proposerArgument : '';
        $lastIssues = $lastRound instanceof DebateRound ? $lastRound->criticResponse : '';

        $reviewResult = ($this->agentRunner)(
            "Review the final solution after {$config->rounds} rounds of red-teaming.\n\n**Solution:**\n{$finalSolution}\n\n**Remaining Issues:**\n{$lastIssues}\n\nProvide a final assessment and recommendation.",
            $config->reviewerModel,
            'You are a senior reviewer synthesizing the results of a red-team exercise. Be thorough and actionable.',
            5,
            $remainingBudget,
        );

        $totalCost = $roundsCost + ($reviewResult['cost'] ?? 0.0);
        $totalDuration = (microtime(true) - $startTime) * 1000;

        $tracer->emitDuration(
            name: 'redteam.total',
            category: 'debate',
            tid: $tid,
            startMicros: $startMicros,
            durationMicros: (int) ($totalDuration * 1000),
            args: [
                'total_cost'   => $totalCost,
                'rounds_cost'  => $roundsCost,
                'review_cost'  => $reviewResult['cost'] ?? 0.0,
                'round_count'  => count($rounds),
            ],
        );

        return new DebateResult(
            type: 'red_team',
            topic: $task,
            rounds: $rounds,
            finalVerdict: $reviewResult['content'],
            recommendation: $this->extractRecommendation($reviewResult['content']),
            totalCost: $totalCost,
            totalDurationMs: $totalDuration,
            agentContributions: [
                'builder' => ['model' => $config->builderModel],
                'attacker' => ['model' => $config->attackerModel],
                'reviewer' => ['model' => $config->reviewerModel, 'cost' => $reviewResult['cost'] ?? 0.0],
            ],
            totalTurns: count($rounds) * 2 + ($reviewResult['turns'] ?? 1),
        );
    }

    /**
     * Run ensemble: N agents solve independently, then merge.
     */
    public function ensemble(EnsembleConfig $config, string $task): DebateResult
    {
        $startTime = microtime(true);
        $startMicros = (int) ($startTime * 1_000_000);
        $tracer = TraceCollector::getInstance();
        $tid = $this->traceTid($task);

        $tracer->emitInstant(
            name: 'ensemble.start',
            category: 'debate',
            tid: $tid,
            args: [
                'agents'   => $config->agents,
                'merger'   => $config->mergerModel,
            ],
        );

        $protocol = new DebateProtocol($this->agentRunner);
        $budgetPerAgent = ($config->maxBudget * 0.7) / max(1, $config->agents);

        // Run N agents independently
        $solutions = [];
        $agentContributions = [];
        $totalAgentCost = 0.0;
        $totalTurns = 0;
        $rounds = [];

        for ($i = 0; $i < $config->agents; $i++) {
            $model = $config->getModelForAgent($i);
            $agentResult = ($this->agentRunner)(
                "Solve this task independently. Provide your complete solution.\n\n**Task:** {$task}",
                $model,
                "You are Agent " . ($i + 1) . " in an ensemble. Provide your best independent solution.",
                $config->maxTurnsPerAgent,
                $budgetPerAgent,
            );

            $solutions[] = $agentResult['content'];
            $agentCost = $agentResult['cost'] ?? 0.0;
            $totalAgentCost += $agentCost;
            $totalTurns += $agentResult['turns'] ?? 1;

            $agentContributions["agent_" . ($i + 1)] = [
                'model' => $model,
                'cost' => $agentCost,
                'turns' => $agentResult['turns'] ?? 1,
            ];

            // Record as "rounds" for consistency
            $rounds[] = new DebateRound(
                roundNumber: $i + 1,
                proposerArgument: $agentResult['content'],
                criticResponse: "(independent solution - no critique)",
                roundCost: $agentCost,
            );
        }

        // Merge solutions
        $remainingBudget = max(0.5, $config->maxBudget - $totalAgentCost);
        $mergeResult = $protocol->runEnsembleMerge(
            $config->mergerModel,
            $solutions,
            $task,
            $config->mergeCriteria,
            $remainingBudget,
        );

        $totalCost = $totalAgentCost + ($mergeResult['cost'] ?? 0.0);
        $totalDuration = (microtime(true) - $startTime) * 1000;

        $agentContributions['merger'] = [
            'model' => $config->mergerModel,
            'cost' => $mergeResult['cost'] ?? 0.0,
        ];

        return new DebateResult(
            type: 'ensemble',
            topic: $task,
            rounds: $rounds,
            finalVerdict: $mergeResult['content'],
            recommendation: $this->extractRecommendation($mergeResult['content']),
            totalCost: $totalCost,
            totalDurationMs: $totalDuration,
            agentContributions: $agentContributions,
            totalTurns: $totalTurns + ($mergeResult['turns'] ?? 1),
        );
    }

    /**
     * Stable lane label for trace events from a debate session.
     * Hash the topic so multi-session debates separate on the viewer.
     */
    private function traceTid(string $topic): string
    {
        return 'debate:' . substr(md5($topic), 0, 12);
    }

    private function extractRecommendation(string $content): string
    {
        // Try to extract a recommendation section
        if (preg_match('/#+\s*Recommendation[s]?\s*\n(.*?)(?=\n#|\z)/si', $content, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/\*\*Recommendation[s]?\*\*[:\s]*(.*?)(?=\n\*\*|\n#|\z)/si', $content, $matches)) {
            return trim($matches[1]);
        }
        // Fallback: return last paragraph
        $paragraphs = array_filter(explode("\n\n", trim($content)));
        return trim(end($paragraphs) ?: $content);
    }
}
