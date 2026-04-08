<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Detects prompt injection attempts in context files and user input.
 *
 * Inspired by hermes-agent's prompt_builder.py threat detection — scans for:
 *   - Instruction override patterns ("ignore previous instructions")
 *   - System prompt extraction attempts
 *   - Data exfiltration via curl/wget/fetch
 *   - Hidden content (HTML comments, invisible Unicode)
 *   - Role confusion ("you are now", "act as")
 *   - Encoded/obfuscated payloads
 */
class PromptInjectionDetector
{
    /**
     * Injection pattern categories with their regex patterns.
     * Each pattern is designed to catch common prompt injection techniques.
     */
    private const PATTERNS = [
        'instruction_override' => [
            '/ignore\s+(all\s+)?(previous|prior|above|earlier)\s+(instructions?|prompts?|rules?|directions?)/i',
            '/disregard\s+(all\s+)?(previous|prior|above)\s+(instructions?|context)/i',
            '/forget\s+(everything|all)\s+(you\s+)?(know|learned|were\s+told)/i',
            '/override\s+(system|safety|security)\s+(prompt|instructions?|rules?)/i',
            '/new\s+instructions?\s*[:=]/i',
        ],
        'system_prompt_extraction' => [
            '/(?:print|show|display|reveal|output|repeat|echo)\s+(?:your\s+)?(?:system\s+)?(?:prompt|instructions?|rules?)/i',
            '/what\s+(?:are|is)\s+your\s+(?:system\s+)?(?:prompt|instructions?|rules?|guidelines?)/i',
            '/(?:beginning|start)\s+of\s+(?:your\s+)?(?:system\s+)?(?:prompt|instructions?)/i',
        ],
        'data_exfiltration' => [
            '/curl\s+(?:-[sSkLfO]*\s+)*https?:\/\//i',
            '/wget\s+(?:-[qO]*\s+)*https?:\/\//i',
            '/fetch\s*\(\s*[\'"]https?:\/\//i',
            '/(?:nc|netcat|ncat)\s+-[a-z]*\s+\d+\.\d+\.\d+\.\d+/i',
        ],
        'role_confusion' => [
            '/you\s+are\s+now\s+(?:a\s+)?(?:different|new|my|an?\s+)/i',
            '/(?:act|behave|respond|pretend)\s+(?:as|like)\s+(?:if\s+)?(?:you\s+(?:are|were)\s+)?(?:a\s+)?/i',
            '/(?:switch|change)\s+(?:to|into)\s+(?:a\s+)?(?:different|new)\s+(?:mode|role|persona)/i',
            '/\[system\]|\[SYSTEM\]|<\|system\|>/i',
        ],
        'invisible_unicode' => [
            // Zero-width characters
            '/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u',
            // Bidirectional override characters
            '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
            // Tag characters (U+E0001-U+E007F)
            '/[\x{E0001}-\x{E007F}]/u',
        ],
        'hidden_content' => [
            '/<!--[\s\S]*?-->/s',
            '/<div\s+style\s*=\s*["\'].*?display\s*:\s*none.*?["\'].*?>/is',
            '/<span\s+style\s*=\s*["\'].*?font-size\s*:\s*0.*?["\'].*?>/is',
        ],
        'encoding_evasion' => [
            '/(?:base64|b64)\s*(?:decode|encode)\s*\(/i',
            '/\\\\x[0-9a-fA-F]{2}(?:\\\\x[0-9a-fA-F]{2}){3,}/i',
            '/\\\\u[0-9a-fA-F]{4}(?:\\\\u[0-9a-fA-F]{4}){3,}/i',
        ],
    ];

    /**
     * Severity levels for each category.
     */
    private const SEVERITY = [
        'instruction_override' => 'high',
        'system_prompt_extraction' => 'high',
        'data_exfiltration' => 'critical',
        'role_confusion' => 'medium',
        'invisible_unicode' => 'medium',
        'hidden_content' => 'low',
        'encoding_evasion' => 'medium',
    ];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Scan text for prompt injection patterns.
     *
     * @return PromptInjectionResult
     */
    public function scan(string $text, string $source = 'unknown'): PromptInjectionResult
    {
        $threats = [];

        foreach (self::PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $threats[] = [
                        'category' => $category,
                        'severity' => self::SEVERITY[$category],
                        'pattern' => $pattern,
                        'match' => mb_substr($matches[0], 0, 100),
                        'source' => $source,
                    ];
                }
            }
        }

        if (!empty($threats)) {
            $this->logger->warning('Prompt injection patterns detected', [
                'source' => $source,
                'threat_count' => count($threats),
                'categories' => array_unique(array_column($threats, 'category')),
                'max_severity' => $this->getMaxSeverity($threats),
            ]);
        }

        return new PromptInjectionResult(
            hasThreat: !empty($threats),
            threats: $threats,
            source: $source,
        );
    }

    /**
     * Scan a context file (CLAUDE.md, .cursorrules, etc.) for injection.
     */
    public function scanFile(string $filePath): PromptInjectionResult
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return new PromptInjectionResult(false, [], $filePath);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return new PromptInjectionResult(false, [], $filePath);
        }

        return $this->scan($content, $filePath);
    }

    /**
     * Scan multiple context files and aggregate results.
     *
     * @param string[] $filePaths
     * @return PromptInjectionResult[] Keyed by file path
     */
    public function scanFiles(array $filePaths): array
    {
        $results = [];
        foreach ($filePaths as $path) {
            $result = $this->scanFile($path);
            if ($result->hasThreat) {
                $results[$path] = $result;
            }
        }
        return $results;
    }

    /**
     * Sanitize text by removing detected invisible Unicode characters.
     * Returns the cleaned text (does not remove other threat types).
     */
    public function sanitizeInvisible(string $text): string
    {
        foreach (self::PATTERNS['invisible_unicode'] as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }
        return $text;
    }

    /**
     * Get the highest severity from a list of threats.
     */
    private function getMaxSeverity(array $threats): string
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        $max = 'low';

        foreach ($threats as $threat) {
            $sev = $threat['severity'] ?? 'low';
            if (($order[$sev] ?? 0) > ($order[$max] ?? 0)) {
                $max = $sev;
            }
        }

        return $max;
    }
}
