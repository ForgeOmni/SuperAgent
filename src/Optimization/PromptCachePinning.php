<?php

declare(strict_types=1);

namespace SuperAgent\Optimization;

class PromptCachePinning
{
    public const CACHE_BOUNDARY = '__SYSTEM_PROMPT_DYNAMIC_BOUNDARY__';

    /**
     * Heading patterns that typically introduce dynamic (per-session) content.
     * Order does not matter — we find the earliest occurrence.
     */
    private const DYNAMIC_MARKERS = [
        '/^#{1,3}\s+Current\b/im',
        '/^#{1,3}\s+Context\b/im',
        '/^#{1,3}\s+Memory\b/im',
        '/^#{1,3}\s+Session\b/im',
        '/^#{1,3}\s+Recent\b/im',
        '/^#{1,3}\s+Task\b/im',
        '/^#{1,3}\s+Current\s+date\b/im',
    ];

    public function __construct(
        private bool $enabled = true,
        private int $minStaticLength = 500,
    ) {}

    /**
     * Create an instance from the application config.
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.optimization.prompt_cache_pinning') ?? []) : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: $config['enabled'] ?? true,
            minStaticLength: $config['min_static_length'] ?? 500,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Ensure the system prompt has a cache boundary marker.
     * If the prompt already has one, returns it unchanged.
     * If not, analyzes the prompt and inserts a boundary between
     * static (tool descriptions, role definition) and dynamic
     * (memory, context, task-specific) sections.
     *
     * @param string|null $systemPrompt  Original system prompt
     * @return string|null  System prompt with cache boundary
     */
    public function pin(?string $systemPrompt): ?string
    {
        if ($systemPrompt === null || trim($systemPrompt) === '') {
            return null;
        }

        if (! $this->enabled) {
            return $systemPrompt;
        }

        // Already has a boundary — nothing to do.
        if (str_contains($systemPrompt, self::CACHE_BOUNDARY)) {
            return $systemPrompt;
        }

        // Too short to benefit from caching.
        if (mb_strlen($systemPrompt) < $this->minStaticLength) {
            return $systemPrompt;
        }

        $splitPos = $this->findDynamicSectionOffset($systemPrompt);

        if ($splitPos === null) {
            // No recognisable dynamic markers — use the 80/20 heuristic:
            // treat the last 20 % as dynamic.
            $splitPos = (int) floor(mb_strlen($systemPrompt) * 0.8);

            // Back up to the nearest newline so we don't split mid-line.
            $newlinePos = strrpos($systemPrompt, "\n", -(mb_strlen($systemPrompt) - $splitPos));
            if ($newlinePos !== false && $newlinePos > 0) {
                $splitPos = $newlinePos;
            }
        }

        $staticPart = substr($systemPrompt, 0, $splitPos);
        $dynamicPart = substr($systemPrompt, $splitPos);

        return $staticPart . self::CACHE_BOUNDARY . $dynamicPart;
    }

    /**
     * Scan the prompt for the earliest dynamic-section heading and return its
     * byte offset, or null if none is found.
     */
    private function findDynamicSectionOffset(string $prompt): ?int
    {
        $earliest = null;

        foreach (self::DYNAMIC_MARKERS as $pattern) {
            if (preg_match($pattern, $prompt, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = $matches[0][1];

                // Only treat it as a split point when the static portion before
                // it is long enough to be worth caching.
                if ($offset >= $this->minStaticLength && ($earliest === null || $offset < $earliest)) {
                    $earliest = $offset;
                }
            }
        }

        return $earliest;
    }
}
