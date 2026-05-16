<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Output of `TaskDecomposer::decomposeWithConfidence()` — wraps the
 * sub-task plan with a heuristic confidence score so a caller can
 * decide whether to invoke the optional LLM-assisted refiner before
 * dispatching the squad.
 */
final class DecompositionResult
{
    /**
     * @param SubTask[] $subTasks
     * @param float     $confidence 0..1 — how strongly the heuristic
     *                              signals fired. Below ~0.5 the
     *                              decomposition probably misses
     *                              structure and an LLM pass helps.
     * @param string[]  $signals    Names of signals that fired.
     */
    public function __construct(
        public readonly array $subTasks,
        public readonly float $confidence,
        public readonly array $signals = [],
    ) {}
}
