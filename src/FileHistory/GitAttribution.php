<?php

namespace SuperAgent\FileHistory;

use Symfony\Component\Process\Process;
use Illuminate\Support\Collection;

class GitAttribution
{
    private static ?self $instance = null;
    private bool $enabled = true;
    private array $config;

    public function __construct()
    {
        $this->config = [
            'co_author_name' => 'SuperAgent AI',
            'co_author_email' => 'ai@superagent.local',
            'message_suffix' => "\n\n🤖 Generated with SuperAgent\nCo-Authored-By: SuperAgent AI <ai@superagent.local>",
        ];
    }

    /**
     * @deprecated Use constructor injection instead
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a commit with AI attribution.
     */
    public function createCommit(string $message, array $files = [], array $options = []): bool
    {
        if (!$this->enabled || !$this->isGitRepository()) {
            return false;
        }

        // Stage files if specified
        if (!empty($files)) {
            if (!$this->stageFiles($files)) {
                return false;
            }
        }

        // Check if there are changes to commit
        if (!$this->hasStagedChanges()) {
            return false;
        }

        // Prepare commit message with attribution
        $fullMessage = $this->prepareCommitMessage($message, $options);

        // Create commit
        $process = new Process(['git', 'commit', '-m', $fullMessage]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Prepare commit message with AI attribution.
     */
    public function prepareCommitMessage(string $message, array $options = []): string
    {
        $fullMessage = $message;

        // Add context if provided
        if (isset($options['context'])) {
            $fullMessage .= "\n\n" . $options['context'];
        }

        // Add file changes summary
        if ($options['include_summary'] ?? true) {
            $summary = $this->getChangesSummary();
            if ($summary) {
                $fullMessage .= "\n\nChanges:\n" . $summary;
            }
        }

        // Add AI attribution
        $fullMessage .= $this->config['message_suffix'];

        return $fullMessage;
    }

    /**
     * Stage files for commit.
     */
    public function stageFiles(array $files): bool
    {
        foreach ($files as $file) {
            $process = new Process(['git', 'add', $file]);
            $process->run();
            
            if (!$process->isSuccessful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a summary of staged changes.
     */
    public function getChangesSummary(): ?string
    {
        $process = new Process(['git', 'diff', '--cached', '--stat']);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    /**
     * Get detailed diff of staged changes.
     */
    public function getStagedDiff(): ?string
    {
        $process = new Process(['git', 'diff', '--cached']);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }

    /**
     * Get list of modified files.
     */
    public function getModifiedFiles(): Collection
    {
        $process = new Process(['git', 'status', '--porcelain']);
        $process->run();

        if (!$process->isSuccessful()) {
            return collect();
        }

        $lines = explode("\n", trim($process->getOutput()));
        $files = collect();

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $status = substr($line, 0, 2);
            $file = trim(substr($line, 3));

            $files->push([
                'file' => $file,
                'status' => $this->parseGitStatus($status),
                'staged' => $status[0] !== ' ' && $status[0] !== '?',
                'modified' => $status[1] !== ' ' && $status[1] !== '?',
            ]);
        }

        return $files;
    }

    /**
     * Parse git status code.
     */
    private function parseGitStatus(string $status): string
    {
        return match (trim($status)) {
            'M' => 'modified',
            'A' => 'added',
            'D' => 'deleted',
            'R' => 'renamed',
            'C' => 'copied',
            'U' => 'unmerged',
            '??' => 'untracked',
            default => 'unknown',
        };
    }

    /**
     * Check if there are staged changes.
     */
    public function hasStagedChanges(): bool
    {
        $process = new Process(['git', 'diff', '--cached', '--quiet']);
        $process->run();

        // Git returns exit code 1 if there are changes
        return !$process->isSuccessful();
    }

    /**
     * Check if current directory is a git repository.
     */
    public function isGitRepository(): bool
    {
        $process = new Process(['git', 'rev-parse', '--git-dir']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get current branch name.
     */
    public function getCurrentBranch(): ?string
    {
        $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    /**
     * Get last commit info.
     */
    public function getLastCommit(): ?array
    {
        $process = new Process(['git', 'log', '-1', '--pretty=format:%H|%an|%ae|%s|%ai']);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $parts = explode('|', $process->getOutput());
        if (count($parts) < 5) {
            return null;
        }

        return [
            'hash' => $parts[0],
            'author_name' => $parts[1],
            'author_email' => $parts[2],
            'subject' => $parts[3],
            'date' => $parts[4],
        ];
    }

    /**
     * Set configuration.
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Get configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set enabled state.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}