<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * KAIROS-style append-only daily log system ported from Claude Code.
 *
 * Log path structure: {memoryDir}/logs/YYYY/MM/YYYY-MM-DD.md
 *
 * The daily log is an append-only stream of timestamped entries.
 * Auto-dream consolidation (AutoDreamConsolidator) periodically merges
 * daily logs into topic-organized memory files and updates MEMORY.md.
 *
 * What to log:
 *  - User corrections and preferences
 *  - Facts about user, role, goals
 *  - Project context not in code (deadlines, incidents, decisions)
 *  - Pointers to external systems
 *  - Anything user explicitly asks to remember
 */
class DailyLog
{
    public function __construct(
        private string $memoryDir,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Append an entry to today's daily log.
     */
    public function append(string $entry, ?\DateTimeInterface $timestamp = null): void
    {
        $timestamp ??= new \DateTimeImmutable();
        $path = $this->getLogPath($timestamp);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timeStr = $timestamp->format('H:i:s');
        $line = "- [{$timeStr}] {$entry}\n";

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        $this->logger->debug('Daily log entry appended', [
            'path' => $path,
            'entry_length' => strlen($entry),
        ]);
    }

    /**
     * Read today's log content.
     */
    public function readToday(): string
    {
        $path = $this->getLogPath(new \DateTimeImmutable());
        if (!file_exists($path)) {
            return '';
        }
        return file_get_contents($path) ?: '';
    }

    /**
     * Read a specific day's log.
     */
    public function readDate(\DateTimeInterface $date): string
    {
        $path = $this->getLogPath($date);
        if (!file_exists($path)) {
            return '';
        }
        return file_get_contents($path) ?: '';
    }

    /**
     * Get logs for the last N days.
     *
     * @return array<string, string> date => content
     */
    public function getRecentLogs(int $days = 7): array
    {
        $logs = [];
        $now = new \DateTimeImmutable();

        for ($i = 0; $i < $days; $i++) {
            $date = $now->modify("-{$i} days");
            $dateStr = $date->format('Y-m-d');
            $content = $this->readDate($date);
            if ($content !== '') {
                $logs[$dateStr] = $content;
            }
        }

        return $logs;
    }

    /**
     * Get all log files since a given timestamp.
     *
     * @return array<string, string> date => content
     */
    public function getLogsSince(\DateTimeInterface $since): array
    {
        $logs = [];
        $logsDir = $this->memoryDir . '/logs';

        if (!is_dir($logsDir)) {
            return [];
        }

        $sinceTs = $since->getTimestamp();

        // Walk year/month/file structure
        $years = glob($logsDir . '/[0-9][0-9][0-9][0-9]');
        foreach ($years ?: [] as $yearDir) {
            $months = glob($yearDir . '/[0-9][0-9]');
            foreach ($months ?: [] as $monthDir) {
                $files = glob($monthDir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].md');
                foreach ($files ?: [] as $file) {
                    if (filemtime($file) >= $sinceTs) {
                        $dateStr = basename($file, '.md');
                        $logs[$dateStr] = file_get_contents($file) ?: '';
                    }
                }
            }
        }

        ksort($logs);
        return $logs;
    }

    /**
     * Get the log file path for a given date.
     *
     * Format: {memoryDir}/logs/YYYY/MM/YYYY-MM-DD.md
     */
    public function getLogPath(\DateTimeInterface $date): string
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $day = $date->format('Y-m-d');

        return "{$this->memoryDir}/logs/{$year}/{$month}/{$day}.md";
    }

    /**
     * Get the assistant prompt for daily log mode.
     * Injected into system prompt when KAIROS mode is active.
     */
    public static function getAssistantPrompt(string $memoryDir): string
    {
        $today = date('Y/m/Y-m-d');

        return <<<PROMPT
# auto memory

You have a persistent file-based memory at: {$memoryDir}

Record anything worth remembering by appending to:
{$memoryDir}/logs/{$today}.md

## What to log
- User corrections and preferences
- Facts about user, role, goals
- Project context not in code (deadlines, incidents, decisions)
- Pointers to external systems (dashboards, Slack, etc.)
- Anything user explicitly asks to remember

## MEMORY.md
MEMORY.md is the distilled index (maintained nightly by auto-dream consolidation).
Read it for orientation, but do NOT edit directly — record new info in today's log.
PROMPT;
    }

    /**
     * Count total log entries across all files.
     */
    public function countEntries(): int
    {
        $count = 0;
        $logsDir = $this->memoryDir . '/logs';

        if (!is_dir($logsDir)) {
            return 0;
        }

        $files = glob($logsDir . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].md');
        foreach ($files ?: [] as $file) {
            $content = file_get_contents($file);
            if ($content) {
                $count += substr_count($content, "\n- [");
            }
        }

        return $count;
    }
}
