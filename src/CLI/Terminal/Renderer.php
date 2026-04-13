<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Terminal;

use SuperAgent\Harness\StreamEvent;
use SuperAgent\Harness\Events\TextDeltaEvent;
use SuperAgent\Harness\Events\ThinkingDeltaEvent;
use SuperAgent\Harness\Events\ToolStartedEvent;
use SuperAgent\Harness\Events\ToolCompletedEvent;
use SuperAgent\Harness\Events\TurnCompleteEvent;
use SuperAgent\Harness\Events\ErrorEvent;

/**
 * Terminal renderer for SuperAgent CLI.
 *
 * Handles all terminal I/O: input prompts, streaming output,
 * tool execution display, markdown rendering, and status messages.
 */
class Renderer
{
    private bool $supportsAnsi;
    private int $termWidth;
    private bool $inStreamingBlock = false;

    public function __construct()
    {
        $this->supportsAnsi = $this->detectAnsiSupport();
        $this->termWidth = $this->detectTermWidth();
    }

    // --- Output methods ---

    public function banner(): void
    {
        $this->newLine();
        $this->write($this->bold('SuperAgent') . ' — AI Coding Assistant', true);
        $this->write($this->dim('Type /help for commands, /quit to exit'), true);
        $this->newLine();
    }

    public function info(string $message): void
    {
        $this->write($this->color($message, '36'), true); // Cyan
    }

    public function success(string $message): void
    {
        $this->write($this->color('✓ ' . $message, '32'), true); // Green
    }

    public function warning(string $message): void
    {
        $this->write($this->color('⚠ ' . $message, '33'), true); // Yellow
    }

    public function error(string $message): void
    {
        $this->write($this->color('✗ ' . $message, '31'), true); // Red
    }

    public function hint(string $message): void
    {
        $this->write($this->dim('  ' . $message), true);
    }

    public function line(string $message): void
    {
        $this->write($message, true);
    }

    public function newLine(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    public function separator(): void
    {
        $this->write($this->dim(str_repeat('─', min($this->termWidth, 60))), true);
    }

    // --- Assistant output ---

    public function assistantMessage(string $content): void
    {
        // Simple markdown rendering: bold, code blocks, headers
        $content = $this->renderMarkdown($content);
        $this->write($content, true);
    }

    public function streamDelta(string $text): void
    {
        // Write without newline for streaming
        $this->write($text, false);
        $this->inStreamingBlock = true;
    }

    public function endStream(): void
    {
        if ($this->inStreamingBlock) {
            $this->newLine();
            $this->inStreamingBlock = false;
        }
    }

    // --- Tool display ---

    public function toolStarted(string $name, string $id): void
    {
        $this->endStream();
        $this->write($this->dim("  ⚡ ") . $this->bold($name) . $this->dim(" ..."), true);
    }

    public function toolCompleted(string $name, bool $success, ?string $preview = null): void
    {
        $icon = $success ? $this->color('✓', '32') : $this->color('✗', '31');
        $msg = "  {$icon} {$name}";
        if ($preview) {
            $maxLen = $this->termWidth - strlen($name) - 10;
            if (strlen($preview) > $maxLen && $maxLen > 20) {
                $preview = substr($preview, 0, $maxLen) . '...';
            }
            $msg .= $this->dim(" → " . $this->singleLine($preview));
        }
        $this->write($msg, true);
    }

    // --- Cost display ---

    public function cost(float $usd, int $turns): void
    {
        $formatted = number_format($usd, 4);
        $this->write($this->dim("Cost: \${$formatted} | Turns: {$turns}"), true);
    }

    // --- Input methods ---

    /**
     * Show the main prompt and read user input.
     */
    public function prompt(): ?string
    {
        $promptStr = $this->bold($this->color('> ', '36'));
        $this->write($promptStr, false);

        $line = fgets(STDIN);

        if ($line === false) {
            return null; // EOF
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Ask a question and return the answer.
     */
    public function ask(string $question): string
    {
        $this->write($question, false);

        $answer = fgets(STDIN);

        return $answer !== false ? rtrim($answer, "\r\n") : '';
    }

    /**
     * Ask for sensitive input (API keys, etc.).
     */
    public function askSecret(string $question): string
    {
        $this->write($question, false);

        // Try to disable echo on Unix systems
        $sttyOriginal = null;
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
            $sttyOriginal = shell_exec('stty -g 2>/dev/null');
            if ($sttyOriginal) {
                shell_exec('stty -echo');
            }
        }

        $answer = fgets(STDIN);

        // Restore echo
        if ($sttyOriginal) {
            shell_exec("stty {$sttyOriginal}");
        }

        $this->newLine();

        return $answer !== false ? rtrim($answer, "\r\n") : '';
    }

    /**
     * Ask for a yes/no confirmation.
     */
    public function confirm(string $question, bool $default = false): bool
    {
        $hint = $default ? '[Y/n]' : '[y/N]';
        $answer = $this->ask("{$question} {$hint} ");

        if ($answer === '') {
            return $default;
        }

        return in_array(strtolower($answer), ['y', 'yes', 'true', '1'], true);
    }

    // --- Stream event handler ---

    /**
     * Handle a StreamEvent from the HarnessLoop/StreamEventEmitter.
     */
    public function handleStreamEvent(StreamEvent $event): void
    {
        if ($event instanceof TextDeltaEvent) {
            $this->streamDelta($event->text);
        } elseif ($event instanceof ThinkingDeltaEvent) {
            // Optionally show thinking (dimmed)
            $this->streamDelta($this->dim($event->text));
        } elseif ($event instanceof ToolStartedEvent) {
            $this->toolStarted($event->toolName, $event->toolUseId);
        } elseif ($event instanceof ToolCompletedEvent) {
            $this->toolCompleted(
                $event->toolName,
                $event->success ?? true,
                $event->resultPreview ?? null,
            );
        } elseif ($event instanceof TurnCompleteEvent) {
            $this->endStream();
        } elseif ($event instanceof ErrorEvent) {
            $this->endStream();
            $this->error($event->message ?? 'Unknown error');
        }
    }

    // --- Markdown rendering (lightweight) ---

    private function renderMarkdown(string $text): string
    {
        $lines = explode("\n", $text);
        $output = [];
        $inCodeBlock = false;

        foreach ($lines as $line) {
            // Code blocks
            if (preg_match('/^```/', $line)) {
                $inCodeBlock = ! $inCodeBlock;
                $output[] = $this->dim($line);
                continue;
            }

            if ($inCodeBlock) {
                $output[] = $this->color($line, '33'); // Yellow for code
                continue;
            }

            // Headers
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $output[] = $this->bold($m[2]);
                continue;
            }

            // Bold: **text**
            $line = preg_replace_callback('/\*\*(.+?)\*\*/', function ($m) {
                return $this->bold($m[1]);
            }, $line);

            // Inline code: `text`
            $line = preg_replace_callback('/`([^`]+)`/', function ($m) {
                return $this->color($m[1], '33');
            }, $line);

            $output[] = $line;
        }

        return implode("\n", $output);
    }

    // --- ANSI helpers ---

    private function bold(string $text): string
    {
        return $this->supportsAnsi ? "\033[1m{$text}\033[0m" : $text;
    }

    private function dim(string $text): string
    {
        return $this->supportsAnsi ? "\033[2m{$text}\033[0m" : $text;
    }

    private function color(string $text, string $code): string
    {
        return $this->supportsAnsi ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    private function write(string $text, bool $newline): void
    {
        echo $text;
        if ($newline) {
            echo PHP_EOL;
        }
    }

    private function singleLine(string $text): string
    {
        return str_replace(["\n", "\r", "\t"], [' ', '', ' '], $text);
    }

    // --- Terminal detection ---

    private function detectAnsiSupport(): bool
    {
        // Windows: check for ConEmu, Windows Terminal, etc.
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('TERM_PROGRAM') === 'Hyper'
                || getenv('WT_SESSION') !== false
                || str_contains(getenv('TERM') ?: '', 'xterm');
        }

        // Unix: check if stdout is a TTY
        return function_exists('posix_isatty') ? posix_isatty(STDOUT) : true;
    }

    private function detectTermWidth(): int
    {
        // Try tput
        if (function_exists('shell_exec')) {
            $cols = (int) @shell_exec('tput cols 2>/dev/null');
            if ($cols > 0) {
                return $cols;
            }
        }

        // Try environment
        $cols = (int) (getenv('COLUMNS') ?: 0);
        if ($cols > 0) {
            return $cols;
        }

        return 80; // Default
    }
}
