<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\NaturalLanguage;

/**
 * Pattern-based parser that extracts structured rules from natural language.
 * Zero-cost (no LLM calls), deterministic, fast.
 */
final class RuleParser
{
    /**
     * Parse natural language text into a structured rule.
     */
    public function parse(string $text): ParsedRule
    {
        $text = trim($text);
        $lower = mb_strtolower($text);

        // Try each pattern type in order of specificity
        return $this->tryRateRule($text, $lower)
            ?? $this->tryCostRule($text, $lower)
            ?? $this->tryToolRestriction($text, $lower)
            ?? $this->tryFilePathRestriction($text, $lower)
            ?? $this->tryWarningRule($text, $lower)
            ?? $this->tryContentRule($text, $lower)
            ?? $this->buildFallback($text);
    }

    /**
     * Check if text looks like a guardrail rule.
     */
    public function isGuardrailRule(string $text): bool
    {
        $lower = mb_strtolower(trim($text));
        $patterns = [
            '/^(never|don\'?t|do not|block|prevent|forbid|prohibit|deny|disallow)\b/',
            '/^(limit|max|maximum|restrict|cap)\b/',
            '/^(warn|alert|notify)\b.*\b(when|if|before)\b/',
            '/^(if|when)\b.*\b(cost|budget|spend|price)\b/',
            '/^(all|every|each)\b.*\b(must|should|need)\b/',
            '/^(stop|halt|pause)\b.*\b(if|when)\b/',
            '/\b(not allowed|forbidden|prohibited|blocked)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    private function tryRateRule(string $text, string $lower): ?ParsedRule
    {
        // "Max/Limit N [tool] calls per [period]"
        if (preg_match('/(?:max|limit|maximum)\s+(\d+)\s+(\w+)\s+calls?\s+per\s+(minute|hour|second|min|hr|sec)/i', $lower, $m)) {
            $max = (int) $m[1];
            $tool = $m[2];
            $period = $this->parsePeriod($m[3]);

            return new ParsedRule(
                originalText: $text,
                action: 'rate_limit',
                toolName: $this->normalizeToolName($tool),
                conditions: [
                    'rate' => ['max' => $max, 'period' => $period],
                ],
                message: "Rate limit: max {$max} {$tool} calls per {$m[3]}",
                confidence: 0.9,
                needsReview: false,
                groupName: 'rate',
                priority: 60,
            );
        }

        return null;
    }

    private function tryCostRule(string $text, string $lower): ?ParsedRule
    {
        // "If cost exceeds/reaches $X, [action]"
        if (preg_match('/(?:if|when)\s+(?:cost|budget|spend|spending)\s+(?:exceeds?|reaches?|goes?\s+(?:over|above|beyond))\s+\$?([\d.]+)/i', $lower, $m)) {
            $threshold = (float) $m[1];
            $action = $this->detectActionFromText($lower, 'ask');

            return new ParsedRule(
                originalText: $text,
                action: $action,
                toolName: null,
                conditions: [
                    'cost_exceeds' => $threshold,
                ],
                message: "Cost threshold: \${$threshold} exceeded",
                confidence: 0.9,
                needsReview: false,
                groupName: 'cost',
                priority: 80,
            );
        }

        // "Stop if budget goes over $X"
        if (preg_match('/(?:stop|halt|pause|deny)\s+.*\$?([\d.]+)/i', $lower, $m)) {
            $threshold = (float) $m[1];
            if ($threshold > 0 && $threshold < 10000) {
                $action = str_contains($lower, 'pause') ? 'pause' : 'deny';

                return new ParsedRule(
                    originalText: $text,
                    action: $action,
                    toolName: null,
                    conditions: [
                        'cost_exceeds' => $threshold,
                    ],
                    message: "Budget limit: \${$threshold}",
                    confidence: 0.75,
                    needsReview: false,
                    groupName: 'cost',
                    priority: 80,
                );
            }
        }

        return null;
    }

    private function tryToolRestriction(string $text, string $lower): ?ParsedRule
    {
        // "Never/Don't/Block [verb] [with tool / using tool]"
        if (preg_match('/(?:never|don\'?t|do\s+not|block|prevent|forbid|prohibit|deny)\s+(?:use\s+)?(\w+)\s+(?:to\s+)?(.+)/i', $lower, $m)) {
            $subject = trim($m[1]);
            $rest = trim($m[2]);

            // Check if subject is a known tool
            $toolName = $this->normalizeToolName($subject);
            if ($toolName !== null) {
                $contentPattern = $this->extractContentPatterns($rest);

                $conditions = [];
                if (!empty($contentPattern)) {
                    $conditions['tool_input_contains'] = count($contentPattern) === 1 ? $contentPattern[0] : $contentPattern;
                }

                return new ParsedRule(
                    originalText: $text,
                    action: 'deny',
                    toolName: $toolName,
                    conditions: $conditions,
                    message: "Blocked by rule: {$text}",
                    confidence: 0.85,
                    needsReview: false,
                    groupName: 'security',
                    priority: 100,
                );
            }

            // Subject might be the action, try to find tool in rest
            $toolFromRest = $this->findToolInText($rest);
            if ($toolFromRest !== null) {
                return new ParsedRule(
                    originalText: $text,
                    action: 'deny',
                    toolName: $toolFromRest,
                    conditions: [],
                    message: "Blocked by rule: {$text}",
                    confidence: 0.7,
                    needsReview: false,
                    groupName: 'security',
                    priority: 100,
                );
            }
        }

        // "Block all [tool] [calls/usage]"
        if (preg_match('/(?:block|deny|disable)\s+(?:all\s+)?(\w+)\s*(?:calls?|usage|access)?/i', $lower, $m)) {
            $toolName = $this->normalizeToolName(trim($m[1]));
            if ($toolName !== null) {
                return new ParsedRule(
                    originalText: $text,
                    action: 'deny',
                    toolName: $toolName,
                    conditions: [],
                    message: "Tool blocked: {$toolName}",
                    confidence: 0.9,
                    needsReview: false,
                    groupName: 'security',
                    priority: 100,
                );
            }
        }

        return null;
    }

    private function tryFilePathRestriction(string $text, string $lower): ?ParsedRule
    {
        // "Don't touch/modify/read [path]"
        if (preg_match('/(?:never|don\'?t|do\s+not)\s+(?:touch|modify|edit|change|delete|remove|read|access)\s+(.+)/i', $lower, $m)) {
            $target = trim($m[1], ' "\'');
            // Strip trailing words like "files"
            $target = preg_replace('/\s+files?\s*$/', '', $target);

            // Check if target looks like a file path or pattern
            if (preg_match('/[\/\\\\.]/', $target) || preg_match('/\.(env|json|yaml|yml|config|lock|key|pem|cert)$/i', $target)) {
                $tools = ['write', 'edit', 'bash'];
                if (str_contains($lower, 'read') || str_contains($lower, 'access')) {
                    $tools[] = 'read';
                }

                return new ParsedRule(
                    originalText: $text,
                    action: 'deny',
                    toolName: null,
                    conditions: [
                        'any_of' => array_map(fn(string $t) => [
                            'tool_name' => $t,
                            'tool_input_contains' => $target,
                        ], $tools),
                    ],
                    message: "Path restricted: {$target}",
                    confidence: 0.85,
                    needsReview: false,
                    groupName: 'security',
                    priority: 100,
                );
            }
        }

        // "Never modify files in [directory]"
        if (preg_match('/(?:never|don\'?t|do\s+not)\s+modify\s+(?:files?\s+)?in\s+(?:the\s+)?(.+)/i', $lower, $m)) {
            $directory = trim($m[1], ' ."\'');

            return new ParsedRule(
                originalText: $text,
                action: 'deny',
                toolName: null,
                conditions: [
                    'any_of' => [
                        ['tool_name' => 'write', 'tool_input_contains' => $directory],
                        ['tool_name' => 'edit', 'tool_input_contains' => $directory],
                    ],
                ],
                message: "Directory restricted: {$directory}",
                confidence: 0.9,
                needsReview: false,
                groupName: 'security',
                priority: 100,
            );
        }

        return null;
    }

    private function tryWarningRule(string $text, string $lower): ?ParsedRule
    {
        // "Warn when/if [condition]"
        if (preg_match('/(?:warn|alert|notify)\s+(?:me\s+)?(?:when|if|before)\s+(.+)/i', $lower, $m)) {
            $condition = trim($m[1]);
            $contentPatterns = $this->extractContentPatterns($condition);
            $tool = $this->findToolInText($condition);

            $conditions = [];
            if (!empty($contentPatterns)) {
                $conditions['tool_input_contains'] = count($contentPatterns) === 1 ? $contentPatterns[0] : $contentPatterns;
            }

            return new ParsedRule(
                originalText: $text,
                action: 'warn',
                toolName: $tool,
                conditions: $conditions,
                message: "Warning: {$text}",
                confidence: !empty($contentPatterns) || $tool !== null ? 0.75 : 0.5,
                needsReview: empty($contentPatterns) && $tool === null,
                groupName: 'safety',
                priority: 40,
            );
        }

        return null;
    }

    private function tryContentRule(string $text, string $lower): ?ParsedRule
    {
        // "All/Every [output] must [requirement]"
        if (preg_match('/(?:all|every|each)\s+(?:generated\s+)?(\w+)\s+(?:must|should|need\s+to)\s+(.+)/i', $lower, $m)) {
            $subject = trim($m[1]);
            $requirement = trim($m[2]);

            return new ParsedRule(
                originalText: $text,
                action: 'warn',
                toolName: null,
                conditions: [
                    'tool_name' => $this->subjectToTool($subject),
                ],
                message: "Content requirement: {$requirement}",
                confidence: 0.6,
                needsReview: true,
                groupName: 'content',
                priority: 40,
            );
        }

        return null;
    }

    private function buildFallback(string $text): ParsedRule
    {
        // Can't confidently parse - mark for review
        return new ParsedRule(
            originalText: $text,
            action: 'warn',
            toolName: null,
            conditions: [],
            message: "Unstructured rule: {$text}",
            confidence: 0.2,
            needsReview: true,
            groupName: 'custom',
            priority: 50,
        );
    }

    private function normalizeToolName(string $name): ?string
    {
        $map = [
            'bash' => 'bash',
            'shell' => 'bash',
            'terminal' => 'bash',
            'command' => 'bash',
            'read' => 'read',
            'write' => 'write',
            'edit' => 'edit',
            'grep' => 'grep',
            'search' => 'grep',
            'glob' => 'glob',
            'find' => 'glob',
            'web' => 'web_search',
            'web_search' => 'web_search',
            'websearch' => 'web_search',
            'agent' => 'agent',
        ];

        return $map[mb_strtolower($name)] ?? null;
    }

    private function findToolInText(string $text): ?string
    {
        $toolKeywords = ['bash', 'shell', 'terminal', 'read', 'write', 'edit', 'grep', 'search', 'glob', 'web_search', 'agent'];

        foreach ($toolKeywords as $keyword) {
            if (str_contains(mb_strtolower($text), $keyword)) {
                return $this->normalizeToolName($keyword);
            }
        }

        return null;
    }

    private function extractContentPatterns(string $text): array
    {
        $patterns = [];

        // Extract quoted strings
        if (preg_match_all('/"([^"]+)"/', $text, $matches)) {
            $patterns = array_merge($patterns, $matches[1]);
        }
        if (preg_match_all("/\'([^']+)'/", $text, $matches)) {
            $patterns = array_merge($patterns, $matches[1]);
        }

        // Extract file-like patterns
        if (preg_match_all('/\b([\w\/\-_.]+\.(?:env|json|yaml|yml|config|lock|key|pem|cert|sql|sh|php|js|py))\b/', $text, $matches)) {
            $patterns = array_merge($patterns, $matches[1]);
        }

        // Extract path patterns
        if (preg_match_all('/\b((?:\/|\.\/|\.\.\/)?(?:[\w\-]+\/)+[\w\-.*]*)\b/', $text, $matches)) {
            $patterns = array_merge($patterns, $matches[1]);
        }

        // Extract dangerous command patterns
        $dangerousPatterns = ['rm -rf', 'rm -r', 'chmod 777', 'DROP TABLE', 'DELETE FROM', '> /dev/', 'mkfs'];
        foreach ($dangerousPatterns as $dp) {
            if (stripos($text, $dp) !== false) {
                $patterns[] = $dp;
            }
        }

        return array_unique($patterns);
    }

    private function subjectToTool(string $subject): ?string
    {
        $map = [
            'code' => 'write',
            'file' => 'write',
            'files' => 'write',
            'output' => null,
            'response' => null,
            'commands' => 'bash',
            'scripts' => 'bash',
        ];

        return $map[mb_strtolower($subject)] ?? null;
    }

    private function detectActionFromText(string $text, string $default): string
    {
        if (preg_match('/\b(stop|halt|deny|block|reject)\b/', $text)) {
            return 'deny';
        }
        if (preg_match('/\b(pause|wait|ask|confirm|approval)\b/', $text)) {
            return 'ask';
        }
        if (preg_match('/\b(warn|alert|notify)\b/', $text)) {
            return 'warn';
        }
        if (preg_match('/\b(downgrade|cheaper|switch model)\b/', $text)) {
            return 'downgrade_model';
        }
        return $default;
    }

    private function parsePeriod(string $period): int
    {
        return match (mb_strtolower($period)) {
            'second', 'sec' => 1,
            'minute', 'min' => 60,
            'hour', 'hr' => 3600,
            default => 60,
        };
    }
}
