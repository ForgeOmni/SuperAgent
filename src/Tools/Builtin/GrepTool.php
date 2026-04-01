<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class GrepTool extends Tool
{
    public function name(): string
    {
        return 'grep';
    }

    public function description(): string
    {
        return 'Search file contents using regular expressions. Supports context lines, line numbers, and various output modes.';
    }

    public function category(): string
    {
        return 'search';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Regular expression pattern to search for in file contents.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'File or directory to search in. Default: current working directory.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Glob pattern to filter files (e.g., "*.php", "*.{js,ts}").',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'File type to search (e.g., "php", "js", "py").',
                ],
                'output_mode' => [
                    'type' => 'string',
                    'enum' => ['content', 'files_with_matches', 'count'],
                    'description' => 'Output mode: "content" shows matching lines, "files_with_matches" shows file paths, "count" shows match counts. Default: "files_with_matches".',
                ],
                'case_insensitive' => [
                    'type' => 'boolean',
                    'description' => 'Case insensitive search. Default: false.',
                ],
                'show_line_numbers' => [
                    'type' => 'boolean',
                    'description' => 'Show line numbers in output (only for "content" mode). Default: true.',
                ],
                'context_before' => [
                    'type' => 'integer',
                    'description' => 'Number of lines to show before each match (only for "content" mode).',
                ],
                'context_after' => [
                    'type' => 'integer',
                    'description' => 'Number of lines to show after each match (only for "content" mode).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return. Default: 100.',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $pattern = $input['pattern'] ?? '';
        $path = $input['path'] ?? getcwd();
        $glob = $input['glob'] ?? null;
        $type = $input['type'] ?? null;
        $outputMode = $input['output_mode'] ?? 'files_with_matches';
        $caseInsensitive = $input['case_insensitive'] ?? false;
        $showLineNumbers = $input['show_line_numbers'] ?? true;
        $contextBefore = max(0, $input['context_before'] ?? 0);
        $contextAfter = max(0, $input['context_after'] ?? 0);
        $limit = min(max(1, $input['limit'] ?? 100), 1000);

        if (empty($pattern)) {
            return ToolResult::error('Pattern cannot be empty.');
        }

        // Prepare regex flags
        $regexFlags = $caseInsensitive ? 'i' : '';

        // Validate regex pattern
        if (@preg_match('/' . $pattern . '/' . $regexFlags, '') === false) {
            return ToolResult::error('Invalid regular expression pattern.');
        }

        // Get files to search
        $files = $this->getFilesToSearch($path, $glob, $type);

        if (empty($files)) {
            return ToolResult::success('No files found to search.');
        }

        // Perform search based on output mode
        switch ($outputMode) {
            case 'content':
                return $this->searchContent($files, $pattern, $regexFlags, $showLineNumbers, $contextBefore, $contextAfter, $limit);
            case 'count':
                return $this->searchCount($files, $pattern, $regexFlags, $limit);
            case 'files_with_matches':
            default:
                return $this->searchFiles($files, $pattern, $regexFlags, $limit);
        }
    }

    private function getFilesToSearch(string $path, ?string $glob, ?string $type): array
    {
        $files = [];

        if (is_file($path)) {
            $files[] = $path;
        } elseif (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $filePath = $file->getPathname();
                    
                    // Apply glob filter if specified
                    if ($glob && !fnmatch($glob, basename($filePath))) {
                        continue;
                    }
                    
                    // Apply type filter if specified
                    if ($type) {
                        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                        if ($extension !== $type) {
                            continue;
                        }
                    }
                    
                    $files[] = $filePath;
                }
            }
        }

        return $files;
    }

    private function searchFiles(array $files, string $pattern, string $flags, int $limit): ToolResult
    {
        $matches = [];

        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            if (preg_match('/' . $pattern . '/' . $flags, $content)) {
                $matches[] = $this->getRelativePath($file);
                
                if (count($matches) >= $limit) {
                    break;
                }
            }
        }

        if (empty($matches)) {
            return ToolResult::success('No matches found.');
        }

        $result = implode("\n", $matches);
        
        if (count($matches) >= $limit) {
            $result .= "\n\n(Results limited to {$limit} files)";
        }

        return ToolResult::success($result);
    }

    private function searchContent(array $files, string $pattern, string $flags, bool $showLineNumbers, int $contextBefore, int $contextAfter, int $limit): ToolResult
    {
        $output = [];
        $totalMatches = 0;

        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $lines = file($file);
            if ($lines === false) {
                continue;
            }

            $fileMatches = [];
            
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/' . $pattern . '/' . $flags, $line)) {
                    $match = [
                        'line_num' => $lineNum + 1,
                        'line' => rtrim($line),
                        'context_before' => [],
                        'context_after' => [],
                    ];

                    // Add context lines before
                    for ($i = max(0, $lineNum - $contextBefore); $i < $lineNum; $i++) {
                        $match['context_before'][] = [
                            'line_num' => $i + 1,
                            'line' => rtrim($lines[$i]),
                        ];
                    }

                    // Add context lines after
                    for ($i = $lineNum + 1; $i < min(count($lines), $lineNum + $contextAfter + 1); $i++) {
                        $match['context_after'][] = [
                            'line_num' => $i + 1,
                            'line' => rtrim($lines[$i]),
                        ];
                    }

                    $fileMatches[] = $match;
                    $totalMatches++;

                    if ($totalMatches >= $limit) {
                        break 2;
                    }
                }
            }

            if (!empty($fileMatches)) {
                $output[] = $this->formatFileMatches($file, $fileMatches, $showLineNumbers);
            }
        }

        if (empty($output)) {
            return ToolResult::success('No matches found.');
        }

        $result = implode("\n\n", $output);
        
        if ($totalMatches >= $limit) {
            $result .= "\n\n(Results limited to {$limit} matches)";
        }

        return ToolResult::success($result);
    }

    private function searchCount(array $files, string $pattern, string $flags, int $limit): ToolResult
    {
        $counts = [];
        $processed = 0;

        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $matchCount = preg_match_all('/' . $pattern . '/' . $flags, $content);
            
            if ($matchCount > 0) {
                $counts[$this->getRelativePath($file)] = $matchCount;
                $processed++;
                
                if ($processed >= $limit) {
                    break;
                }
            }
        }

        if (empty($counts)) {
            return ToolResult::success('No matches found.');
        }

        // Sort by count (descending)
        arsort($counts);

        $output = [];
        foreach ($counts as $file => $count) {
            $output[] = "{$file}: {$count}";
        }

        $result = implode("\n", $output);
        
        if ($processed >= $limit) {
            $result .= "\n\n(Results limited to {$limit} files)";
        }

        return ToolResult::success($result);
    }

    private function formatFileMatches(string $file, array $matches, bool $showLineNumbers): string
    {
        $output = $this->getRelativePath($file) . ':';

        foreach ($matches as $match) {
            $output .= "\n";

            // Show context before
            foreach ($match['context_before'] as $contextLine) {
                if ($showLineNumbers) {
                    $output .= sprintf("  %5d | %s\n", $contextLine['line_num'], $contextLine['line']);
                } else {
                    $output .= "  " . $contextLine['line'] . "\n";
                }
            }

            // Show matching line
            if ($showLineNumbers) {
                $output .= sprintf("* %5d | %s", $match['line_num'], $match['line']);
            } else {
                $output .= "* " . $match['line'];
            }

            // Show context after
            foreach ($match['context_after'] as $contextLine) {
                $output .= "\n";
                if ($showLineNumbers) {
                    $output .= sprintf("  %5d | %s", $contextLine['line_num'], $contextLine['line']);
                } else {
                    $output .= "  " . $contextLine['line'];
                }
            }
        }

        return $output;
    }

    private function getRelativePath(string $file): string
    {
        $cwd = getcwd();
        $file = realpath($file);
        
        if (strpos($file, $cwd) === 0) {
            return ltrim(substr($file, strlen($cwd)), DIRECTORY_SEPARATOR);
        }
        
        return $file;
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}