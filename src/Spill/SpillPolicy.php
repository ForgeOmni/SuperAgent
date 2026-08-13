<?php

declare(strict_types=1);

namespace SuperAgent\Spill;

/**
 * Applies the spill decision to tool output before it enters model context.
 *
 * Output above threshold_chars is persisted via the SpillStore and replaced
 * inline by a head+tail preview plus the retrieval locator. Save failure
 * keeps the full inline result (best-effort — never lose data to spilling).
 */
class SpillPolicy
{
    public function __construct(
        private readonly SpillStoreInterface $store,
        private readonly bool $enabled = true,
        private readonly int $thresholdChars = 30_000,
        private readonly int $previewHeadChars = 2_000,
        private readonly int $previewTailChars = 500,
        private array $skipTools = ['spill_read'],
    ) {
    }

    public static function fromConfig(?string $sessionId = null): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.spill') ?? []) : [];
        } catch (\Throwable $e) {
            $config = [];
        }

        return new self(
            store: LocalSpillStore::fromConfig($sessionId ?? 'default'),
            enabled: $config['enabled'] ?? true,
            thresholdChars: $config['threshold_chars'] ?? 30_000,
            previewHeadChars: $config['preview_head_chars'] ?? 2_000,
            previewTailChars: $config['preview_tail_chars'] ?? 500,
            skipTools: $config['skip_tools'] ?? ['spill_read'],
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Spill $content when oversized; returns the model-facing replacement,
     * or the original content when below threshold / disabled / save failed.
     */
    public function apply(string $toolName, string $toolUseId, string $content): string
    {
        if (!$this->enabled
            || strlen($content) <= $this->thresholdChars
            || in_array($toolName, $this->skipTools, true)) {
            return $content;
        }

        $ref = $this->store->save($toolName, $toolUseId, $content);
        if ($ref === null) {
            return $content;
        }

        $head = mb_substr($content, 0, $this->previewHeadChars);
        $tail = $this->previewTailChars > 0 ? mb_substr($content, -$this->previewTailChars) : '';
        $lines = substr_count($content, "\n") + 1;

        $preview = "[Tool output spilled to storage — too large to keep inline]\n"
            . "tool: {$toolName}\n"
            . "size: {$ref->bytes} bytes, {$lines} lines\n"
            . "locator: {$ref->locator}\n"
            . "retrieval: {$ref->retrievalHint}\n\n"
            . "--- head preview ---\n{$head}\n";

        if ($tail !== '') {
            $preview .= "--- tail preview ---\n{$tail}\n";
        }

        return $preview;
    }
}
