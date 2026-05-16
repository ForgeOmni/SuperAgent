<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use SuperAgent\SmartContext\TaskComplexity;

/**
 * Splits a free-form prompt into a `SubTask[]` plan.
 *
 * Deliberately *heuristic* — no LLM round-trip — so the planner is
 * deterministic, free, and adds zero latency before the squad starts
 * running. The decomposition produces a small set of canonical roles
 * (research / design / implement / verify / decide) that map cleanly
 * onto common multi-step workflows; a downstream LLM-driven planner
 * can replace this later, but the contract (returns `SubTask[]`)
 * stays the same.
 *
 * Detection signals:
 *   - Numbered lists (`1. … 2. …`) → one subtask per item
 *   - Step keywords (then / 然后 / next / 最后 / finally) → split
 *     around them
 *   - "decide / 决策 / 敲定 / approve / 评审 / review" keywords inside
 *     a step → mark `requires_review = true` so an HITL gate is added
 *   - Verbs ("research / 调研", "design / 设计", "implement /
 *     实现", "test / 测试 / 验证") → pick the canonical role
 *
 * If no structural signal is found the prompt is returned as a single
 * `MODERATE` subtask — the squad then degenerates into a one-agent
 * pipeline, which is the right behaviour for "just answer this".
 */
final class TaskDecomposer
{
    /**
     * Run the heuristic and additionally return a confidence score
     * + the signal list. Hosts that want to spend a cheap LLM call
     * to refine low-confidence plans use this entrypoint.
     */
    public function decomposeWithConfidence(string $prompt): DecompositionResult
    {
        $subTasks = $this->decompose($prompt);

        $signals = [];
        $confidence = 0.0;

        if (preg_match('/(?:^|\n)\s*\d+[.、)]\s+/u', $prompt)) {
            $signals[] = 'numbered_list';
            $confidence += 0.55;
        }
        if (preg_match('/\b(?:then|after that|next|finally|also)\b|然后|接着|最后|另外/iu', $prompt)) {
            $signals[] = 'step_keywords';
            $confidence += 0.30;
        }
        if (preg_match('/同时|并行|in parallel|concurrently/iu', $prompt)) {
            $signals[] = 'parallel_marker';
            $confidence += 0.15;
        }
        if (count($subTasks) >= 2) {
            $signals[] = 'multiple_subtasks';
            $confidence += 0.10;
        }
        // Cap at 1.0; if no signals at all, confidence floors at 0.2
        // (we DID produce a single subtask, which is a valid answer).
        $confidence = max(0.2, min(1.0, $confidence));

        return new DecompositionResult($subTasks, $confidence, $signals);
    }

    /**
     * Pipe an LLM call through this callable to refine a low-confidence
     * heuristic plan. The callable receives the raw prompt + the current
     * SubTask[] plan and should return a refined SubTask[] (or the
     * original list if it doesn't see any improvement).
     *
     * @param callable(string, SubTask[]): SubTask[] $refiner
     */
    public function withLlmRefiner(callable $refiner, float $confidenceFloor = 0.50): self
    {
        $copy = clone $this;
        $copy->refiner = $refiner;
        $copy->refinerThreshold = $confidenceFloor;
        return $copy;
    }

    /** @var (callable(string, SubTask[]): SubTask[])|null */
    private $refiner = null;

    private float $refinerThreshold = 0.50;

    /**
     * @return SubTask[] In dependency order. Refined by an injected
     *                   LLM callable if confidence is below the floor.
     */
    public function decomposeRefined(string $prompt): array
    {
        $result = $this->decomposeWithConfidence($prompt);
        if ($this->refiner === null || $result->confidence >= $this->refinerThreshold) {
            return $result->subTasks;
        }
        $refined = ($this->refiner)($prompt, $result->subTasks);
        return is_array($refined) && !empty($refined) ? $refined : $result->subTasks;
    }

    /**
     * @return SubTask[] In dependency order.
     */
    public function decompose(string $prompt): array
    {
        $segments = $this->split($prompt);

        if (count($segments) <= 1) {
            return [$this->single($prompt)];
        }

        $subTasks = [];
        $prev = null;
        $parallelGroup = null;
        $parallelDeps = [];
        $parallelGroupCounter = 0;

        foreach ($segments as $i => $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $role = $this->detectRole($segment);
            $name = $this->buildName($role, $i + 1);
            $complexity = TaskComplexity::analyze($segment);
            $difficulty = DifficultyClass::fromScore($complexity->score);

            $requiresReview = $this->mentionsReviewGate($segment)
                || $role === 'decide'
                || $difficulty->defaultRequiresReview();

            $marker = $this->parallelMarker($segment);

            if ($marker === 'start') {
                $parallelGroupCounter++;
                $parallelGroup = 'g' . $parallelGroupCounter;
                $parallelDeps = $prev !== null ? [$prev] : [];
            } elseif ($marker === 'end') {
                // Close the group AFTER this subtask is emitted.
            } elseif ($parallelGroup !== null && $marker === null) {
                // Already in a group — keep extending it as long as no
                // explicit end marker comes in, then close on the next
                // sequential keyword (e.g. "then / 然后").
            }

            $depsForThis = $parallelGroup !== null
                ? $parallelDeps                // every group peer shares the same upstream
                : ($prev !== null ? [$prev] : []);

            $subTasks[] = new SubTask(
                name: $name,
                role: $role,
                prompt: $segment,
                difficulty: $difficulty,
                dependsOn: $depsForThis,
                requiresReview: $requiresReview,
                parallelGroup: $parallelGroup,
            );

            if ($parallelGroup !== null && $marker === 'end') {
                // Synthetic group name becomes the dependency of the next sequential step.
                $prev = 'parallel-' . $parallelGroup;
                $parallelGroup = null;
                $parallelDeps = [];
            } elseif ($parallelGroup === null) {
                $prev = $name;
            }
        }

        // If a group was left open, close it implicitly.
        if ($parallelGroup !== null) {
            $prev = 'parallel-' . $parallelGroup;
        }

        return $subTasks;
    }

    /**
     * Detect parallelism markers in the segment. Returns:
     *   'start' — opens a new parallel group
     *   'end'   — closes the current parallel group
     *   null    — no marker (sequential or continuing the current group)
     */
    private function parallelMarker(string $segment): ?string
    {
        $s = mb_strtolower($segment);

        // Group-opening markers
        foreach (['同时', '并行', 'in parallel', 'concurrently', 'simultaneously'] as $kw) {
            if (str_contains($s, $kw)) {
                return 'start';
            }
        }

        // Group-closing markers — "synthesize / 综合" usually wraps a fan-in
        foreach (['综合', '汇总', '聚合', 'synthesize', 'aggregate', 'merge results'] as $kw) {
            if (str_contains($s, $kw)) {
                return 'end';
            }
        }

        return null;
    }

    /**
     * Lower the prompt into segments using whichever signal fires.
     *
     * @return string[]
     */
    private function split(string $prompt): array
    {
        // Numbered list, e.g. "1. foo\n2. bar"
        if (preg_match_all('/(?:^|\n)\s*\d+[.、)]\s+([^\n]+(?:\n(?!\s*\d+[.、)])[^\n]+)*)/u', $prompt, $m)) {
            if (count($m[1]) >= 2) {
                return array_map('trim', $m[1]);
            }
        }

        // Parallel-shaped prompts: "同时/in parallel ... A 和/and B ... 综合/synthesize ..."
        // When we see a parallel marker followed by a conjunction list,
        // split on the conjunctions so each side becomes its own segment.
        $hasParallelOpener = preg_match('/同时|并行|in parallel|concurrently|simultaneously/iu', $prompt);
        if ($hasParallelOpener) {
            $sequentialBreak = '/最后|综合|aggregate|synthesize|然后|finally/iu';
            $branches = preg_split($sequentialBreak, $prompt, 2);
            if (is_array($branches) && count($branches) === 2) {
                $head = trim($branches[0]);
                $tail = trim($branches[1]);
                // Split the head on " 和 " / "、" / " and ".
                $headParts = preg_split('/\s*(?:和|、|,|\sand\s)\s*/iu', $head) ?: [$head];
                $headParts = array_values(array_filter(array_map('trim', $headParts), fn ($s) => $s !== ''));
                if (count($headParts) >= 2) {
                    return [...$headParts, $tail];
                }
            }
        }

        // Step keywords. Split on, but keep the segment that follows.
        $pattern = '/\b(?:then|after that|next|finally|also)\b|然后|接着|接下来|最后|另外|再然后/iu';
        $parts = preg_split($pattern, $prompt) ?: [$prompt];

        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }

    private function detectRole(string $segment): string
    {
        $s = mb_strtolower($segment);

        $roles = [
            'research' => ['research', 'investigate', 'survey', 'gather', '调研', '研究', '搜索', '收集'],
            'design'   => ['design', 'architect', 'plan',  'propose', '设计', '架构', '规划', '方案'],
            'decide'   => ['decide', 'pick', 'select', 'choose', 'approve', '敲定', '决策', '确定', '选定', '选题'],
            'implement'=> ['implement', 'build', 'write', 'code',    '实现', '编写', '构建', '开发'],
            'verify'   => ['verify', 'test', 'review', 'audit', 'check', '测试', '验证', '审查', '评审', '审核'],
        ];

        foreach ($roles as $role => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($s, $kw)) {
                    return $role;
                }
            }
        }

        return 'execute';
    }

    private function mentionsReviewGate(string $segment): bool
    {
        $s = mb_strtolower($segment);
        foreach (['人工审核', '人工审查', '人审', '需审核', '需审查', 'needs review', 'human review', 'human-in-the-loop', 'approve before'] as $kw) {
            if (str_contains($s, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function buildName(string $role, int $idx): string
    {
        return sprintf('%s-%02d', $role, $idx);
    }

    private function single(string $prompt): SubTask
    {
        $complexity = TaskComplexity::analyze($prompt);
        $role = $this->detectRole($prompt);

        return new SubTask(
            name: $this->buildName($role, 1),
            role: $role,
            prompt: $prompt,
            difficulty: DifficultyClass::fromScore($complexity->score),
            dependsOn: [],
            requiresReview: false,
        );
    }
}
