<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

/**
 * Detects pathological loops in an agent run.
 *
 * Port of qwen-code's `services/loopDetectionService.ts`. Five detectors,
 * each with a different trigger condition:
 *
 *   1. TOOL_LOOP — same tool + same arguments called 5 times in a row.
 *      Most common "model stuck" failure mode: keeps retrying the
 *      exact same call expecting different output.
 *
 *   2. STAGNATION — same tool name called 8 times in a row, regardless
 *      of args. Parameter-thrashing variant of TOOL_LOOP (model varies
 *      one field to pretend it's making progress).
 *
 *   3. FILE_READ_LOOP — 8 of the last 15 tool calls were read-like
 *      (read_file / read_many_files / list_directory / `read_*` /
 *      `list_*` prefixes). Catches "just reading files forever
 *      without acting" pattern. Gated by cold-start exemption:
 *      until at least one non-read tool has fired, read-heavy
 *      exploration is legitimate.
 *
 *   4. CONTENT_LOOP — same 50-char chunk appears 10 times in a
 *      rolling content window. Catches models that get stuck
 *      repeating the same phrase.
 *
 *   5. THOUGHT_LOOP — same thinking text appears 3 times. Applies
 *      to the thinking channel; separate from CONTENT_LOOP since
 *      thinking tokens don't user-face.
 *
 * Every detector returns a `LoopViolation` the first time it fires;
 * further observations after a fired detector return the same
 * violation unchanged until `reset()` is called (typically at the
 * start of each new user prompt). Callers decide what to do with the
 * violation — usually "stop the turn, surface the detector type to
 * the user, let them restart with a different prompt."
 *
 * Thresholds are tuned to match qwen-code's values (and by extension
 * kimi-cli's, which forked the same detector). Overridable via
 * constructor config for tests / operator-level tuning.
 */
final class LoopDetector
{
    // Defaults match qwen-code's loopDetectionService.ts constants.
    public const DEFAULT_TOOL_CALL_LOOP_THRESHOLD = 5;
    public const DEFAULT_CONTENT_LOOP_THRESHOLD   = 10;
    public const DEFAULT_CONTENT_CHUNK_SIZE       = 50;
    public const DEFAULT_CONTENT_WINDOW_SIZE      = 1000;
    public const DEFAULT_THOUGHT_REPEAT_THRESHOLD = 3;
    public const DEFAULT_FILE_READ_THRESHOLD      = 8;
    public const DEFAULT_FILE_READ_WINDOW         = 15;
    public const DEFAULT_STAGNATION_THRESHOLD     = 8;

    /** Tool names always classified as read-like. */
    private const READ_LIKE_NAMES = [
        'read_file', 'read_many_files', 'list_directory',
        // Common Claude Code / SA-canonical equivalents.
        'Read', 'read', 'Grep', 'grep', 'Glob', 'glob', 'LS', 'ls',
    ];
    /** Name prefixes that signal read-like tools (MCP conventions). */
    private const READ_LIKE_PREFIXES = ['read_', 'list_'];

    /** @var array<string, int> */
    private array $thresholds;

    // Tool-call tracking
    private ?string $lastToolCallKey = null;
    private int $toolCallRepetitionCount = 0;
    private string $lastSeenToolName = '';
    private int $sameNameStreak = 0;
    /** @var list<array{name:string, args:array<string, mixed>}> */
    private array $recentToolCalls = [];
    private bool $hasSeenNonReadTool = false;

    // Content tracking
    private string $streamContentHistory = '';
    /** @var array<string, list<int>> chunk-hash → list of offsets */
    private array $contentStats = [];

    // Thought tracking
    /** @var list<string> */
    private array $thoughtHistory = [];

    // Last-fired violation — cached to give stable reads from
    // `lastViolation()` after the first trigger.
    private ?LoopViolation $lastViolation = null;

    /**
     * @param array<string, int> $thresholds Override any of the
     *   public DEFAULT_* constants by key (e.g.
     *   ['TOOL_CALL_LOOP_THRESHOLD' => 10] to loosen the tool loop
     *   trigger). Unknown keys are ignored.
     */
    public function __construct(array $thresholds = [])
    {
        $this->thresholds = [
            'TOOL_CALL_LOOP_THRESHOLD' => self::DEFAULT_TOOL_CALL_LOOP_THRESHOLD,
            'CONTENT_LOOP_THRESHOLD'   => self::DEFAULT_CONTENT_LOOP_THRESHOLD,
            'CONTENT_CHUNK_SIZE'       => self::DEFAULT_CONTENT_CHUNK_SIZE,
            'CONTENT_WINDOW_SIZE'      => self::DEFAULT_CONTENT_WINDOW_SIZE,
            'THOUGHT_REPEAT_THRESHOLD' => self::DEFAULT_THOUGHT_REPEAT_THRESHOLD,
            'FILE_READ_THRESHOLD'      => self::DEFAULT_FILE_READ_THRESHOLD,
            'FILE_READ_WINDOW'         => self::DEFAULT_FILE_READ_WINDOW,
            'STAGNATION_THRESHOLD'     => self::DEFAULT_STAGNATION_THRESHOLD,
        ];
        foreach ($thresholds as $k => $v) {
            if (isset($this->thresholds[$k])) {
                $this->thresholds[$k] = (int) $v;
            }
        }
    }

    /**
     * Record a tool call. Returns a violation the first time any tool
     * detector trips; further observations on an already-fired
     * detector return the cached violation without re-checking.
     *
     * @param array<string, mixed> $args
     */
    public function observeToolCall(string $name, array $args = []): ?LoopViolation
    {
        if ($this->lastViolation !== null) {
            return $this->lastViolation;
        }

        // Flip cold-start once any non-read tool fires. Read-like
        // exploration before this doesn't count toward FILE_READ_LOOP.
        if (! $this->hasSeenNonReadTool && ! self::isReadLike($name)) {
            $this->hasSeenNonReadTool = true;
        }

        // Bounded recent-calls history for FILE_READ_LOOP.
        $this->recentToolCalls[] = ['name' => $name, 'args' => $args];
        if (count($this->recentToolCalls) > $this->thresholds['FILE_READ_WINDOW']) {
            array_shift($this->recentToolCalls);
        }

        // 1) TOOL_LOOP — same name + same args
        $key = $name . ':' . self::canonicalArgs($args);
        if ($key === $this->lastToolCallKey) {
            $this->toolCallRepetitionCount++;
            if ($this->toolCallRepetitionCount >= $this->thresholds['TOOL_CALL_LOOP_THRESHOLD']) {
                return $this->lastViolation = new LoopViolation(
                    LoopType::ToolLoop,
                    "Tool '{$name}' called {$this->toolCallRepetitionCount} times with identical arguments",
                    ['tool' => $name, 'count' => $this->toolCallRepetitionCount],
                );
            }
        } else {
            $this->lastToolCallKey = $key;
            $this->toolCallRepetitionCount = 1;
        }

        // 2) STAGNATION — same name, any args
        if ($name === $this->lastSeenToolName) {
            $this->sameNameStreak++;
            if ($this->sameNameStreak >= $this->thresholds['STAGNATION_THRESHOLD']) {
                return $this->lastViolation = new LoopViolation(
                    LoopType::Stagnation,
                    "Tool '{$name}' called {$this->sameNameStreak} times in a row (varying args) — parameter thrashing",
                    ['tool' => $name, 'count' => $this->sameNameStreak],
                );
            }
        } else {
            $this->lastSeenToolName = $name;
            $this->sameNameStreak = 1;
        }

        // 3) FILE_READ_LOOP (gated by cold-start)
        if ($this->hasSeenNonReadTool
            && count($this->recentToolCalls) >= $this->thresholds['FILE_READ_WINDOW']
        ) {
            $readCount = 0;
            foreach ($this->recentToolCalls as $call) {
                if (self::isReadLike($call['name'])) {
                    $readCount++;
                }
            }
            if ($readCount >= $this->thresholds['FILE_READ_THRESHOLD']) {
                return $this->lastViolation = new LoopViolation(
                    LoopType::FileReadLoop,
                    "{$readCount} of the last " . count($this->recentToolCalls)
                        . ' tool calls were read-like — model may be stuck exploring',
                    ['reads' => $readCount, 'window' => count($this->recentToolCalls)],
                );
            }
        }

        return null;
    }

    /**
     * Observe a chunk of streamed assistant content. Accumulates into
     * a rolling window; detects sliding-window repetition of 50-char
     * slices.
     */
    public function observeContent(string $chunk): ?LoopViolation
    {
        if ($this->lastViolation !== null) {
            return $this->lastViolation;
        }
        if ($chunk === '') {
            return null;
        }

        $this->streamContentHistory .= $chunk;
        // Bound the window so pathological streams don't OOM.
        $win = $this->thresholds['CONTENT_WINDOW_SIZE'];
        if (strlen($this->streamContentHistory) > $win * 2) {
            $this->streamContentHistory = substr($this->streamContentHistory, -$win);
        }

        $size = $this->thresholds['CONTENT_CHUNK_SIZE'];
        $len = strlen($this->streamContentHistory);
        if ($len < $size) {
            return null;
        }

        // Only hash the NEW tail slice to avoid re-hashing the entire
        // buffer every chunk.
        $tail = substr($this->streamContentHistory, -$size);
        $hash = md5($tail);
        $this->contentStats[$hash] = $this->contentStats[$hash] ?? [];
        $this->contentStats[$hash][] = $len - $size;
        if (count($this->contentStats[$hash]) >= $this->thresholds['CONTENT_LOOP_THRESHOLD']) {
            return $this->lastViolation = new LoopViolation(
                LoopType::ContentLoop,
                "Same {$size}-char sequence repeated "
                    . count($this->contentStats[$hash])
                    . ' times in the assistant response',
                ['chunk' => $tail, 'count' => count($this->contentStats[$hash])],
            );
        }
        return null;
    }

    /**
     * Observe a thinking-channel text fragment. Threshold is lower
     * (3 repeats) because thinking loops are rarer and more
     * diagnostic when they happen.
     */
    public function observeThought(string $thought): ?LoopViolation
    {
        if ($this->lastViolation !== null) {
            return $this->lastViolation;
        }
        $trim = trim($thought);
        if ($trim === '') {
            return null;
        }
        $this->thoughtHistory[] = $trim;
        // Bounded history.
        if (count($this->thoughtHistory) > 50) {
            array_shift($this->thoughtHistory);
        }

        $occurrences = 0;
        foreach ($this->thoughtHistory as $t) {
            if ($t === $trim) {
                $occurrences++;
            }
        }
        if ($occurrences >= $this->thresholds['THOUGHT_REPEAT_THRESHOLD']) {
            return $this->lastViolation = new LoopViolation(
                LoopType::ThoughtLoop,
                "Same thought text repeated {$occurrences} times",
                ['thought' => $trim, 'count' => $occurrences],
            );
        }
        return null;
    }

    /**
     * Reset detector state. Typically called at the start of each
     * new user prompt (not per tool call) so cross-prompt state
     * doesn't leak. Cold-start exemption also resets — each new
     * prompt opens with fresh exploration tolerance.
     */
    public function reset(): void
    {
        $this->lastToolCallKey = null;
        $this->toolCallRepetitionCount = 0;
        $this->lastSeenToolName = '';
        $this->sameNameStreak = 0;
        $this->recentToolCalls = [];
        $this->hasSeenNonReadTool = false;
        $this->streamContentHistory = '';
        $this->contentStats = [];
        $this->thoughtHistory = [];
        $this->lastViolation = null;
    }

    public function lastViolation(): ?LoopViolation
    {
        return $this->lastViolation;
    }

    /** Test helper — exposes the current cold-start gate state. */
    public function hasSeenNonReadToolCall(): bool
    {
        return $this->hasSeenNonReadTool;
    }

    // ── Static helpers ────────────────────────────────────────────

    public static function isReadLike(string $toolName): bool
    {
        if (in_array($toolName, self::READ_LIKE_NAMES, true)) {
            return true;
        }
        $lower = strtolower($toolName);
        foreach (self::READ_LIKE_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Canonicalize tool args so TOOL_LOOP detection keys on
     * structural equality rather than key order / whitespace. JSON
     * with sorted keys is deterministic enough for this purpose.
     *
     * @param array<string, mixed> $args
     */
    public static function canonicalArgs(array $args): string
    {
        self::ksortRecursive($args);
        return json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @param array<int|string, mixed> $arr */
    private static function ksortRecursive(array &$arr): void
    {
        foreach ($arr as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
        unset($v);
        if (array_keys($arr) !== range(0, count($arr) - 1)) {
            ksort($arr);
        }
    }
}
