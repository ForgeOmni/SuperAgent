<?php

declare(strict_types=1);

namespace SuperAgent\Console\Output;

use SuperAgent\Harness\AgentCompleteEvent;
use SuperAgent\Harness\CompactionEvent;
use SuperAgent\Harness\ErrorEvent;
use SuperAgent\Harness\StatusEvent;
use SuperAgent\Harness\StreamEvent;
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Harness\TextDeltaEvent;
use SuperAgent\Harness\ThinkingDeltaEvent;
use SuperAgent\Harness\ToolCompletedEvent;
use SuperAgent\Harness\ToolStartedEvent;
use SuperAgent\Harness\TurnCompleteEvent;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Real-time CLI renderer for StreamEvent streams — Claude Code style.
 *
 * Subscribes to a StreamEventEmitter and turns each event into a formatted
 * terminal line. Handles thinking blocks, tool invocations, text deltas,
 * turn boundaries, and a running totals footer.
 *
 * Modes:
 *   - decorated (TTY + color)   → full experience with ANSI + symbols
 *   - plain                     → no color, no cursor control (for pipes / CI)
 *
 * Verbosity:
 *   - normal   → thinking collapsed to a single preview line
 *   - verbose  → full thinking text
 *   - quiet    → thinking hidden entirely
 *
 * Design: one class, no external deps beyond Symfony OutputInterface. Pure
 * write-through; no background threads, no timers. Spinner frames are
 * printed only when a ToolStartedEvent arrives and cleared on completion.
 */
class RealTimeCliRenderer
{
    public const THINKING_NORMAL = 'normal';
    public const THINKING_VERBOSE = 'verbose';
    public const THINKING_HIDDEN = 'hidden';

    private bool $decorated;
    private string $thinkingMode;

    /** @var array<string, array{tool: string, summary: string}> key = toolUseId */
    private array $activeTools = [];

    /** Accumulated thinking text for the current turn. */
    private string $thinkingBuffer = '';
    private bool $thinkingHeaderPrinted = false;

    /** True once a TextDelta has started inside the current turn. */
    private bool $textStarted = false;

    /** Running totals. */
    private int $turns = 0;
    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;
    private float $totalCostUsd = 0.0;

    public function __construct(
        private readonly OutputInterface $output,
        ?bool $decorated = null,
        string $thinkingMode = self::THINKING_NORMAL,
    ) {
        $this->decorated = $decorated ?? $this->detectDecorated($output);
        $this->thinkingMode = $thinkingMode;
    }

    /**
     * Subscribe to an emitter. Returns the listener id so caller can unsubscribe.
     */
    public function attach(StreamEventEmitter $emitter): int
    {
        return $emitter->on(function (StreamEvent $event): void {
            $this->handle($event);
        });
    }

    /**
     * Dispatch a single event. Public so renderers can be driven in tests.
     */
    public function handle(StreamEvent $event): void
    {
        match (true) {
            $event instanceof ThinkingDeltaEvent => $this->onThinkingDelta($event),
            $event instanceof TextDeltaEvent => $this->onTextDelta($event),
            $event instanceof ToolStartedEvent => $this->onToolStarted($event),
            $event instanceof ToolCompletedEvent => $this->onToolCompleted($event),
            $event instanceof TurnCompleteEvent => $this->onTurnComplete($event),
            $event instanceof CompactionEvent => $this->onCompaction($event),
            $event instanceof StatusEvent => $this->onStatus($event),
            $event instanceof ErrorEvent => $this->onError($event),
            $event instanceof AgentCompleteEvent => $this->onAgentComplete($event),
            default => null,
        };
    }

    /**
     * Force the footer (turn count / tokens / cost) to print now.
     * Useful at the end of a run.
     */
    public function flushFooter(): void
    {
        if ($this->turns === 0) {
            return;
        }
        $this->output->writeln('');
        $this->output->writeln($this->color('gray', $this->footerLine()));
    }

    // ── Thinking ───────────────────────────────────────────────────

    private function onThinkingDelta(ThinkingDeltaEvent $event): void
    {
        if ($this->thinkingMode === self::THINKING_HIDDEN) {
            return;
        }
        $this->thinkingBuffer .= $event->text;

        if (!$this->thinkingHeaderPrinted) {
            $this->ensureNewline();
            $this->output->writeln($this->color('magenta', ' ✻ Thinking…'));
            $this->thinkingHeaderPrinted = true;
        }

        if ($this->thinkingMode === self::THINKING_VERBOSE) {
            // Stream raw text underneath the header. Each delta appended.
            $this->output->write($this->color('gray', $event->text));
        }
    }

    /**
     * Finalize the thinking block for the current turn.
     * In normal mode, prints a single-line preview after the turn's thinking
     * buffer is complete. In verbose mode, inserts a trailing newline.
     */
    private function closeThinkingBlock(): void
    {
        if (!$this->thinkingHeaderPrinted || $this->thinkingMode === self::THINKING_HIDDEN) {
            $this->thinkingBuffer = '';
            $this->thinkingHeaderPrinted = false;
            return;
        }

        if ($this->thinkingMode === self::THINKING_NORMAL) {
            $preview = $this->firstNonEmptyLine($this->thinkingBuffer);
            if ($preview !== '') {
                $preview = $this->truncate($preview, 120);
                $this->output->writeln($this->color('gray', '   ╰ ' . $preview));
            }
        } elseif ($this->thinkingMode === self::THINKING_VERBOSE) {
            // Ensure we end the verbose thinking block with a newline.
            $this->output->writeln('');
        }

        $this->thinkingBuffer = '';
        $this->thinkingHeaderPrinted = false;
    }

    // ── Tool ───────────────────────────────────────────────────────

    private function onToolStarted(ToolStartedEvent $event): void
    {
        $this->closeThinkingBlock();
        $this->ensureNewline();

        $summary = $this->summarizeToolInput($event->toolName, $event->toolInput);
        $this->activeTools[$event->toolUseId] = [
            'tool' => $event->toolName,
            'summary' => $summary,
        ];

        $line = ' ' . $this->color('cyan', '●') . ' ' . $event->toolName;
        if ($summary !== '') {
            $line .= '(' . $summary . ')';
        }
        $this->output->writeln($line);
    }

    private function onToolCompleted(ToolCompletedEvent $event): void
    {
        $tool = $this->activeTools[$event->toolUseId] ?? null;
        unset($this->activeTools[$event->toolUseId]);

        $symbol = $event->isError
            ? $this->color('red', '✗')
            : $this->color('green', '✓');

        $preview = $this->summarizeToolOutput($event->output, $event->isError);
        $this->output->writeln('   ' . $this->color('gray', '⎿ ') . $symbol . ' ' . $preview);
    }

    // ── Text ───────────────────────────────────────────────────────

    private function onTextDelta(TextDeltaEvent $event): void
    {
        if ($this->thinkingHeaderPrinted) {
            $this->closeThinkingBlock();
        }
        if (!$this->textStarted) {
            $this->ensureNewline();
            $this->textStarted = true;
        }
        $this->output->write($event->text);
    }

    // ── Turn ───────────────────────────────────────────────────────

    private function onTurnComplete(TurnCompleteEvent $event): void
    {
        $this->turns = max($this->turns, $event->turnNumber);
        if (is_array($event->usage)) {
            $this->totalInputTokens += (int) ($event->usage['input_tokens'] ?? 0);
            $this->totalOutputTokens += (int) ($event->usage['output_tokens'] ?? 0);
        }
        if ($this->textStarted) {
            $this->output->writeln('');
            $this->textStarted = false;
        }
    }

    // ── Status / Compaction / Error / AgentComplete ─────────────────

    private function onCompaction(CompactionEvent $event): void
    {
        $this->ensureNewline();
        $this->output->writeln($this->color('yellow', ' ⚡ Context compacted'));
    }

    private function onStatus(StatusEvent $event): void
    {
        if ($event->message === '') {
            return;
        }
        $this->ensureNewline();
        $this->output->writeln($this->color('gray', ' · ' . $event->message));
    }

    private function onError(ErrorEvent $event): void
    {
        $this->ensureNewline();
        $this->output->writeln($this->color('red', ' ✗ ' . $event->message));
    }

    private function onAgentComplete(AgentCompleteEvent $event): void
    {
        if ($this->textStarted) {
            $this->output->writeln('');
            $this->textStarted = false;
        }
        $this->closeThinkingBlock();

        $this->turns = max($this->turns, $event->totalTurns);
        $this->totalCostUsd = $event->totalCostUsd;
        $this->flushFooter();
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function footerLine(): string
    {
        $tokens = sprintf('%s in / %s out', $this->fmtTokens($this->totalInputTokens), $this->fmtTokens($this->totalOutputTokens));
        $cost = $this->totalCostUsd > 0 ? sprintf(' · $%.4f', $this->totalCostUsd) : '';

        return sprintf(' ⧖ %d turn%s · %s%s', $this->turns, $this->turns === 1 ? '' : 's', $tokens, $cost);
    }

    private function fmtTokens(int $n): string
    {
        if ($n >= 1000) {
            return sprintf('%.1fK', $n / 1000.0);
        }

        return (string) $n;
    }

    /**
     * Short one-line summary of tool inputs for display after the tool name.
     */
    private function summarizeToolInput(string $tool, array $input): string
    {
        // Prefer obvious keys commonly seen in tool schemas.
        foreach (['file_path', 'path', 'file', 'pattern', 'query', 'url', 'command', 'prompt'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                return $this->truncate($input[$key], 70);
            }
        }
        if ($input === []) {
            return '';
        }
        // Fallback: first scalar value.
        foreach ($input as $v) {
            if (is_scalar($v)) {
                return $this->truncate((string) $v, 70);
            }
        }

        return '';
    }

    private function summarizeToolOutput(string $output, bool $isError): string
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return $isError ? 'error (empty)' : 'done';
        }
        $lineCount = substr_count($trimmed, "\n") + 1;
        if ($lineCount > 1) {
            return sprintf('%d line%s', $lineCount, $lineCount === 1 ? '' : 's');
        }

        return $this->truncate($trimmed, 100);
    }

    private function firstNonEmptyLine(string $s): string
    {
        foreach (preg_split('/\R/', $s) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    private function truncate(string $s, int $max): string
    {
        $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);

        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1) . '…';
    }

    private function ensureNewline(): void
    {
        // We can't read the output buffer state reliably — keep it simple.
        if ($this->textStarted) {
            $this->output->writeln('');
            $this->textStarted = false;
        }
    }

    private function color(string $name, string $text): string
    {
        if (!$this->decorated) {
            return $text;
        }
        static $codes = [
            'gray' => "\033[90m",
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'magenta' => "\033[35m",
            'cyan' => "\033[36m",
            'reset' => "\033[0m",
        ];
        $code = $codes[$name] ?? '';

        return $code . $text . $codes['reset'];
    }

    private function detectDecorated(OutputInterface $output): bool
    {
        if (method_exists($output, 'isDecorated')) {
            return (bool) $output->isDecorated();
        }

        return false;
    }
}
