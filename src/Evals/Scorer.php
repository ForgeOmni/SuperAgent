<?php

declare(strict_types=1);

namespace SuperAgent\Evals;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Score a single model-output-vs-case pair.
 *
 * Two scorer flavors:
 *
 *   1. `rule` — deterministic, free, runs locally. Supported rule types:
 *      - contains      : output contains $needle (case-insensitive optional)
 *      - not_contains  : output must NOT contain $needle
 *      - exact         : trim(output) === $expected
 *      - regex         : preg_match($pattern, $output)
 *      - json          : output parses as JSON; optional `must_have` key paths
 *      - any_of        : nested list of rules, pass if any matches
 *      - all_of        : nested list of rules, pass only if all match
 *
 *   2. `judge` — LLM-as-judge. A separate provider/model rates the output
 *      against a free-text `judge_criteria` and must reply with the single
 *      token `PASS` or `FAIL` (we look for those words case-insensitively at
 *      the start). Costs money — only used when explicitly requested.
 *
 * Result: ['passed' => bool, 'detail' => string, 'judge_raw' => ?string]
 */
final class Scorer
{
    public function __construct(
        private ?LLMProvider $judgeProvider = null,
    ) {}

    /**
     * @param array<string, mixed> $case
     * @return array{passed: bool, detail: string, judge_raw: ?string}
     */
    public function score(array $case, string $output): array
    {
        $scorer = (string) ($case['scorer'] ?? 'rule');
        if ($scorer === 'judge') {
            return $this->judgeScore($case, $output);
        }
        $rule = is_array($case['rule'] ?? null) ? $case['rule'] : [];
        $passed = $this->applyRule($rule, $output);
        return [
            'passed'    => $passed,
            'detail'    => $passed ? 'rule passed' : ('rule failed: ' . ($rule['type'] ?? 'unknown')),
            'judge_raw' => null,
        ];
    }

    /** @param array<string, mixed> $rule */
    private function applyRule(array $rule, string $output): bool
    {
        $type = strtolower((string) ($rule['type'] ?? ''));
        switch ($type) {
            case 'contains':
                $needle = (string) ($rule['needle'] ?? '');
                $ci = (bool) ($rule['case_insensitive'] ?? false);
                return $ci
                    ? stripos($output, $needle) !== false
                    : strpos($output, $needle) !== false;

            case 'not_contains':
                $needle = (string) ($rule['needle'] ?? '');
                $ci = (bool) ($rule['case_insensitive'] ?? false);
                $found = $ci
                    ? stripos($output, $needle) !== false
                    : strpos($output, $needle) !== false;
                return ! $found;

            case 'exact':
                $expected = (string) ($rule['expected'] ?? '');
                return trim($output) === trim($expected);

            case 'regex':
                $pattern = (string) ($rule['pattern'] ?? '');
                if ($pattern === '') {
                    return false;
                }
                return @preg_match($pattern, $output) === 1;

            case 'json':
                $parsed = $this->extractJson($output);
                if ($parsed === null) {
                    return false;
                }
                $required = (array) ($rule['must_have'] ?? []);
                foreach ($required as $path) {
                    if (! $this->jsonHasPath($parsed, (string) $path)) {
                        return false;
                    }
                }
                return true;

            case 'any_of':
                foreach ((array) ($rule['rules'] ?? []) as $sub) {
                    if (is_array($sub) && $this->applyRule($sub, $output)) {
                        return true;
                    }
                }
                return false;

            case 'all_of':
                $rules = (array) ($rule['rules'] ?? []);
                if (empty($rules)) {
                    return false;
                }
                foreach ($rules as $sub) {
                    if (! is_array($sub) || ! $this->applyRule($sub, $output)) {
                        return false;
                    }
                }
                return true;

            default:
                return false;
        }
    }

    /**
     * @param array<string, mixed> $case
     * @return array{passed: bool, detail: string, judge_raw: ?string}
     */
    private function judgeScore(array $case, string $output): array
    {
        if ($this->judgeProvider === null) {
            return ['passed' => false, 'detail' => 'judge scorer requested but no judge configured', 'judge_raw' => null];
        }

        $criteria = (string) ($case['judge_criteria'] ?? 'Output is correct and well-formed.');
        $prompt = $case['prompt'] ?? '';

        $rubric = <<<TXT
You are grading a model's response against a specific criterion. Reply with a
single word on the first line: PASS or FAIL. Then optionally one short line
explaining why.

Criterion: {$criteria}

--- Original prompt ---
{$prompt}

--- Model response ---
{$output}
TXT;

        try {
            $messages = [new UserMessage($rubric)];
            $final = null;
            foreach ($this->judgeProvider->chat($messages, [], null, ['max_tokens' => 200]) as $chunk) {
                if ($chunk instanceof AssistantMessage) {
                    $final = $chunk;
                }
            }
            $raw = $final?->text() ?? '';
            $firstLine = strtoupper(trim(strtok($raw, "\n") ?: ''));
            $passed = str_starts_with($firstLine, 'PASS');
            return [
                'passed'    => $passed,
                'detail'    => $passed ? 'judge: PASS' : 'judge: FAIL',
                'judge_raw' => $raw,
            ];
        } catch (\Throwable $e) {
            return ['passed' => false, 'detail' => 'judge error: ' . $e->getMessage(), 'judge_raw' => null];
        }
    }

    /** Pull the first JSON object/array out of `$output`, even if surrounded by prose / fences. */
    private function extractJson(string $output): mixed
    {
        $trimmed = trim($output);
        $decoded = json_decode($trimmed, true);
        if ($decoded !== null || $trimmed === 'null') {
            return $decoded;
        }
        if (preg_match('/(\{.*\}|\[.*\])/s', $output, $m)) {
            $decoded = json_decode($m[1], true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        return null;
    }

    /** `foo.bar.0.baz` style path existence check against a decoded JSON tree. */
    private function jsonHasPath(mixed $data, string $path): bool
    {
        $segments = explode('.', $path);
        $cursor = $data;
        foreach ($segments as $seg) {
            if (is_array($cursor) && array_key_exists($seg, $cursor)) {
                $cursor = $cursor[$seg];
                continue;
            }
            if (is_array($cursor) && ctype_digit($seg) && array_key_exists((int) $seg, $cursor)) {
                $cursor = $cursor[(int) $seg];
                continue;
            }
            return false;
        }
        return true;
    }
}
