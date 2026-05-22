<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Octopus-borrowed N-of-M consensus gate.
 *
 * The current squad reviewer-loop model is "one reviewer must agree
 * before pass" — a serial gate. claude-octopus (nyldn/claude-octopus)
 * uses a parallel N-of-M gate: M peers each give a verdict on the
 * same artifact and at least N must agree before the work advances.
 *
 * Differences vs. reviewer_loop:
 *   - Reviewer_loop: A writes, B reviews, optional feedback round.
 *                    Serial; one-vote-veto; intended for craft critique.
 *   - ConsensusGate: A writes; B C D E vote in parallel; gate passes
 *                    only when ≥N of M agree. Designed for high-stakes
 *                    decisions (ship/no-ship, security-sensitive code,
 *                    irreversible operations) where blind spots matter
 *                    more than craft.
 *
 * YAML schema extension:
 *
 *   - kind: consensus_gate
 *     name: ship-decision
 *     depends_on: [synthesize]
 *     params:
 *       n: 3
 *       m: 4
 *       voters:
 *         - role: qa-bach
 *         - role: security-schneier
 *         - role: cto-vogels
 *         - role: ceo-bezos
 *     prompt: |
 *       Review the proposed change and respond with exactly one line:
 *       VERDICT: APPROVE | REJECT | ABSTAIN
 *       followed by a single-paragraph justification.
 *
 * The gate produces a verdict object that downstream steps can branch
 * on (continue when APPROVED, escalate / loop when REJECTED).
 */
final class ConsensusGate
{
    public const VERDICT_APPROVE = 'APPROVE';
    public const VERDICT_REJECT  = 'REJECT';
    public const VERDICT_ABSTAIN = 'ABSTAIN';

    public function __construct(
        public readonly string $name,
        public readonly int $n,
        public readonly int $m,
        /** @var list<array{role:string, provider?:string, model?:string}> */
        public readonly array $voters,
        public readonly string $prompt = '',
    ) {
        if ($n < 1 || $n > $m || $m < 1) {
            throw new \InvalidArgumentException("ConsensusGate '{$name}': require 1 <= n <= m, got n={$n} m={$m}");
        }
        if (count($voters) !== $m) {
            throw new \InvalidArgumentException("ConsensusGate '{$name}': voters[] length must equal m ({$m}), got " . count($voters));
        }
    }

    /**
     * Parse a single voter's free-form response into a verdict.
     * Looks for a "VERDICT: APPROVE|REJECT|ABSTAIN" line; defaults to
     * ABSTAIN when the response doesn't contain the expected sentinel.
     */
    public static function parseVerdict(string $response): string
    {
        if (preg_match('/\bVERDICT:\s*(APPROVE|REJECT|ABSTAIN)\b/i', $response, $m)) {
            return strtoupper($m[1]);
        }
        // Lenient fallback for models that ignore the format hint
        if (preg_match('/\bapproved?\b/i', $response) && !preg_match('/\brejected?\b/i', $response)) {
            return self::VERDICT_APPROVE;
        }
        if (preg_match('/\brejected?\b/i', $response)) {
            return self::VERDICT_REJECT;
        }
        return self::VERDICT_ABSTAIN;
    }

    /**
     * Tally voter responses. Returns:
     *   - 'verdict'  : APPROVE / REJECT / ABSTAIN (overall)
     *   - 'passed'   : true when approve count >= n
     *   - 'counts'   : ['APPROVE' => x, 'REJECT' => y, 'ABSTAIN' => z]
     *   - 'per_voter': [voter_index => verdict]
     *
     * @param list<string> $responses One per voter in voter[] order.
     * @return array{
     *   verdict:string, passed:bool,
     *   counts:array<string,int>, per_voter:array<int,string>
     * }
     */
    public function tally(array $responses): array
    {
        $counts = [
            self::VERDICT_APPROVE => 0,
            self::VERDICT_REJECT  => 0,
            self::VERDICT_ABSTAIN => 0,
        ];
        $perVoter = [];
        foreach ($responses as $i => $resp) {
            $v = self::parseVerdict((string) $resp);
            $perVoter[$i] = $v;
            $counts[$v]++;
        }
        $passed = $counts[self::VERDICT_APPROVE] >= $this->n;
        $verdict = $passed
            ? self::VERDICT_APPROVE
            : ($counts[self::VERDICT_REJECT] > $counts[self::VERDICT_APPROVE] ? self::VERDICT_REJECT : self::VERDICT_ABSTAIN);
        return [
            'verdict'   => $verdict,
            'passed'    => $passed,
            'counts'    => $counts,
            'per_voter' => $perVoter,
        ];
    }
}
