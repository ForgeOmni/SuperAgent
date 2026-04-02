<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Injects cross-session memories into the system prompt.
 *
 * Reads memories from the configured memory directory and appends
 * relevant ones to the system prompt, giving the model context about
 * user preferences, project state, and past decisions.
 */
class MemoryInjectionEnhancer implements EnhancerInterface
{
    private ?string $memoryDir;

    public function __construct(?string $memoryDir = null)
    {
        $this->memoryDir = $memoryDir;
    }

    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        $memories = $this->loadMemories();

        if (empty($memories)) {
            return;
        }

        $memoryBlock = "# Memories (from prior sessions)\n\n" . implode("\n\n---\n\n", $memories);

        if ($systemPrompt === null) {
            $systemPrompt = $memoryBlock;
        } else {
            $systemPrompt .= "\n\n" . $memoryBlock;
        }
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        return $message;
    }

    /**
     * Load memory files from the memory directory.
     *
     * Reads all .md files, extracts frontmatter name/description and content.
     */
    private function loadMemories(): array
    {
        $dir = $this->memoryDir ?? $this->resolveMemoryDir();

        if ($dir === null || ! is_dir($dir)) {
            return [];
        }

        $memories = [];
        $files = glob($dir . '/*.md');

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $basename = basename($file);

            // Skip index file
            if ($basename === 'MEMORY.md') {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false || trim($content) === '') {
                continue;
            }

            // Extract frontmatter name if present
            if (preg_match('/^---\s*\n(.+?)\n---\s*\n(.+)$/s', $content, $m)) {
                $frontmatter = $m[1];
                $body = trim($m[2]);

                $name = '';
                if (preg_match('/^name:\s*(.+)$/m', $frontmatter, $nm)) {
                    $name = trim($nm[1]);
                }

                $type = '';
                if (preg_match('/^type:\s*(.+)$/m', $frontmatter, $tm)) {
                    $type = trim($tm[1]);
                }

                if ($body !== '') {
                    $header = $name !== '' ? "**{$name}**" . ($type !== '' ? " ({$type})" : '') : $basename;
                    $memories[] = "{$header}\n{$body}";
                }
            } else {
                $memories[] = "**{$basename}**\n" . trim($content);
            }
        }

        return $memories;
    }

    private function resolveMemoryDir(): ?string
    {
        // Try common memory locations
        $candidates = [
            getcwd() . '/.claude/memory',
            ($_SERVER['HOME'] ?? '') . '/.claude/memory',
        ];

        // Also check project-specific memory path
        $cwd = getcwd();
        if ($cwd !== false) {
            $projectKey = str_replace('/', '-', $cwd);
            $candidates[] = ($_SERVER['HOME'] ?? '') . '/.claude/projects/' . $projectKey . '/memory';
        }

        foreach ($candidates as $path) {
            if ($path !== '' && is_dir($path)) {
                return $path;
            }
        }

        return null;
    }
}
