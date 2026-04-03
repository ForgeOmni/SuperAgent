<?php

declare(strict_types=1);

namespace SuperAgent\AdaptiveFeedback;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Collects user corrections and denials, normalizes them into patterns,
 * and stores them in the CorrectionStore.
 *
 * This class is the "ears" of the adaptive feedback system — it listens
 * for denial events and user corrections, extracts the meaningful pattern,
 * and forwards it to the store for frequency tracking.
 *
 * Usage:
 *   $collector = new CorrectionCollector($store);
 *
 *   // Called by HookRegistry on PERMISSION_DENIED events:
 *   $collector->recordDenial('Bash', ['command' => 'rm -rf /'], 'User denied');
 *
 *   // Called by the system when user explicitly corrects behavior:
 *   $collector->recordCorrection('stop adding docstrings to every function');
 */
class CorrectionCollector
{
    private CorrectionStore $store;

    private LoggerInterface $logger;

    /** @var callable[] listeners for 'correction.recorded' events */
    private array $listeners = [];

    public function __construct(CorrectionStore $store, ?LoggerInterface $logger = null)
    {
        $this->store = $store;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Record a tool permission denial.
     *
     * Called when a user denies a tool execution via the permission system.
     */
    public function recordDenial(string $toolName, array $toolInput, string $reason): CorrectionPattern
    {
        $pattern = $this->extractToolPattern($toolName, $toolInput);
        $inputSummary = $this->summarizeInput($toolName, $toolInput);

        $correctionPattern = $this->store->record(
            category: CorrectionCategory::TOOL_DENIED,
            pattern: $pattern,
            reason: $reason,
            toolName: $toolName,
            toolInput: $inputSummary,
        );

        $this->logger->debug("CorrectionCollector: denial recorded", [
            'tool' => $toolName,
            'pattern' => $pattern,
            'occurrences' => $correctionPattern->occurrences,
        ]);

        $this->emit('correction.recorded', $correctionPattern);

        return $correctionPattern;
    }

    /**
     * Record an explicit user behavior correction.
     *
     * Called when the user gives explicit feedback like "don't add comments"
     * or "stop using TypeScript" etc.
     */
    public function recordCorrection(string $feedback, ?string $toolName = null): CorrectionPattern
    {
        $pattern = $this->normalizeFeedback($feedback);

        $correctionPattern = $this->store->record(
            category: CorrectionCategory::BEHAVIOR_CORRECTION,
            pattern: $pattern,
            reason: $feedback,
            toolName: $toolName,
        );

        $this->logger->debug("CorrectionCollector: correction recorded", [
            'pattern' => $pattern,
            'occurrences' => $correctionPattern->occurrences,
        ]);

        $this->emit('correction.recorded', $correctionPattern);

        return $correctionPattern;
    }

    /**
     * Record an edit reversion (user undid agent's file edit).
     */
    public function recordRevert(string $filePath, ?string $editDescription = null): CorrectionPattern
    {
        $pattern = "edit reverted: {$filePath}";
        $reason = $editDescription ?? "User reverted edit to {$filePath}";

        $correctionPattern = $this->store->record(
            category: CorrectionCategory::EDIT_REVERTED,
            pattern: $pattern,
            reason: $reason,
            toolName: 'Edit',
            toolInput: $filePath,
        );

        $this->emit('correction.recorded', $correctionPattern);

        return $correctionPattern;
    }

    /**
     * Record unwanted content (e.g., unnecessary comments, docstrings).
     */
    public function recordUnwantedContent(string $description, ?string $toolName = null): CorrectionPattern
    {
        $pattern = $this->normalizeFeedback($description);

        $correctionPattern = $this->store->record(
            category: CorrectionCategory::CONTENT_UNWANTED,
            pattern: $pattern,
            reason: $description,
            toolName: $toolName,
        );

        $this->emit('correction.recorded', $correctionPattern);

        return $correctionPattern;
    }

    /**
     * Record output rejection (user said "no", "wrong", rejected result).
     */
    public function recordRejection(string $reason, ?string $toolName = null): CorrectionPattern
    {
        $pattern = $this->normalizeFeedback($reason);

        $correctionPattern = $this->store->record(
            category: CorrectionCategory::OUTPUT_REJECTED,
            pattern: $pattern,
            reason: $reason,
            toolName: $toolName,
        );

        $this->emit('correction.recorded', $correctionPattern);

        return $correctionPattern;
    }

    /**
     * Register an event listener for correction events.
     */
    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Extract a normalized pattern from a tool execution denial.
     *
     * Converts specific tool inputs into generalizable patterns.
     */
    private function extractToolPattern(string $toolName, array $toolInput): string
    {
        return match ($toolName) {
            'Bash' => $this->extractBashPattern($toolInput),
            'Edit', 'Write' => $this->extractEditPattern($toolInput),
            'WebFetch', 'WebSearch' => "network access via {$toolName}",
            default => "denied: {$toolName}",
        };
    }

    /**
     * Extract a generalizable pattern from a bash command.
     *
     * "rm -rf /home/user/project/foo" → "rm -rf *"
     * "git push --force origin main" → "git push --force *"
     */
    private function extractBashPattern(array $toolInput): string
    {
        $command = $toolInput['command'] ?? '';

        if (empty($command)) {
            return 'bash: empty command';
        }

        // Extract the command and first subcommand/flag
        $parts = preg_split('/\s+/', trim($command));
        $baseCommand = $parts[0] ?? '';

        // For common commands, include the subcommand or dangerous flags
        $pattern = $baseCommand;
        if (isset($parts[1]) && str_starts_with($parts[1], '-')) {
            $pattern .= ' ' . $parts[1];
        } elseif (isset($parts[1]) && in_array($baseCommand, ['git', 'docker', 'npm', 'pip', 'apt'], true)) {
            $pattern .= ' ' . $parts[1];
            if (isset($parts[2]) && str_starts_with($parts[2], '-')) {
                $pattern .= ' ' . $parts[2];
            }
        }

        return "bash: {$pattern}";
    }

    /**
     * Extract a pattern from an edit/write operation.
     */
    private function extractEditPattern(array $toolInput): string
    {
        $filePath = $toolInput['file_path'] ?? $toolInput['path'] ?? '';

        if (empty($filePath)) {
            return 'edit: unknown file';
        }

        // Extract file extension as the pattern
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $filename = basename($filePath);

        // Sensitive files
        if (in_array($filename, ['.env', '.gitignore', 'composer.json', 'package.json'], true)) {
            return "edit: {$filename}";
        }

        return $extension ? "edit: *.{$extension} files" : "edit: {$filename}";
    }

    /**
     * Summarize tool input for storage (avoid storing sensitive data).
     */
    private function summarizeInput(string $toolName, array $toolInput): string
    {
        return match ($toolName) {
            'Bash' => $toolInput['command'] ?? '',
            'Edit', 'Write' => $toolInput['file_path'] ?? $toolInput['path'] ?? '',
            default => json_encode(array_keys($toolInput)),
        };
    }

    /**
     * Normalize user feedback into a canonical pattern string.
     */
    private function normalizeFeedback(string $feedback): string
    {
        // Lowercase, collapse whitespace, trim
        $normalized = trim(preg_replace('/\s+/', ' ', strtolower($feedback)));

        // Cap length
        if (strlen($normalized) > 200) {
            $normalized = substr($normalized, 0, 200);
        }

        return $normalized;
    }

    /**
     * Emit an event.
     */
    private function emit(string $event, CorrectionPattern $pattern): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($pattern);
            } catch (\Throwable $e) {
                $this->logger->warning("CorrectionCollector event error: {$e->getMessage()}");
            }
        }
    }
}
