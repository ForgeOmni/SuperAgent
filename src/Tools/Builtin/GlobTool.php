<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class GlobTool extends Tool
{
    public function name(): string
    {
        return 'glob';
    }

    public function description(): string
    {
        return 'Find files matching glob patterns like "**/*.php" or "src/**/*.json". Returns matching file paths sorted by modification time.';
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
                    'description' => 'Glob pattern to match files against (e.g., "*.php", "**/*.json", "src/**/*.ts").',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Base directory to search in. Default: current working directory.',
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
        $basePath = $input['path'] ?? getcwd();
        $limit = min(max(1, $input['limit'] ?? 100), 1000);

        if (empty($pattern)) {
            return ToolResult::error('Pattern cannot be empty.');
        }

        if (! is_dir($basePath)) {
            return ToolResult::error("Base path is not a directory: {$basePath}");
        }

        $files = $this->findFiles($basePath, $pattern, $limit);

        if (empty($files)) {
            return ToolResult::success("No files found matching pattern: {$pattern}");
        }

        // Sort by modification time (newest first)
        usort($files, function ($a, $b) {
            $timeA = file_exists($a) ? filemtime($a) : 0;
            $timeB = file_exists($b) ? filemtime($b) : 0;
            return $timeB - $timeA;
        });

        // Limit results
        $files = array_slice($files, 0, $limit);

        // Format output
        $output = [];
        foreach ($files as $file) {
            $relPath = $this->getRelativePath($file, $basePath);
            $output[] = $relPath;
        }

        $count = count($output);
        $result = implode("\n", $output);
        
        if ($count >= $limit) {
            $result .= "\n\n(Results limited to {$limit} files)";
        }

        return ToolResult::success($result);
    }

    private function findFiles(string $basePath, string $pattern, int $limit): array
    {
        $results = [];
        
        // Handle ** for recursive matching
        if (strpos($pattern, '**') !== false) {
            // Use RecursiveDirectoryIterator for ** patterns
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            // Simple pattern matching for common cases
            if (preg_match('/^\*\*\/\*\.(.+)$/', $pattern, $matches)) {
                // Pattern like **/*.php - match by extension
                $extension = $matches[1];
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === $extension) {
                        $results[] = $file->getPathname();
                        if (count($results) >= $limit * 2) {
                            break;
                        }
                    }
                }
            } elseif (preg_match('/^\*\*\/(.+)$/', $pattern, $matches)) {
                // Pattern like **/filename or **/pattern
                $filePattern = $matches[1];
                foreach ($iterator as $file) {
                    if ($file->isFile() && fnmatch($filePattern, $file->getFilename())) {
                        $results[] = $file->getPathname();
                        if (count($results) >= $limit * 2) {
                            break;
                        }
                    }
                }
            } elseif (preg_match('/^(.+)\/\*\*$/', $pattern, $matches)) {
                // Pattern like src/** - all files in directory recursively
                $dir = $matches[1];
                $targetPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $dir;
                if (is_dir($targetPath)) {
                    $dirIterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($targetPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($dirIterator as $file) {
                        if ($file->isFile()) {
                            $results[] = $file->getPathname();
                            if (count($results) >= $limit * 2) {
                                break;
                            }
                        }
                    }
                }
            } else {
                // Generic ** pattern - try to match the pattern
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relPath = $this->getRelativePath($file->getPathname(), $basePath);
                        $simplePattern = str_replace('**', '*', $pattern);
                        if (fnmatch($simplePattern, $relPath)) {
                            $results[] = $file->getPathname();
                            if (count($results) >= $limit * 2) {
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            // Use standard glob for simple patterns
            $fullPattern = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pattern;
            $results = glob($fullPattern, GLOB_BRACE);
            if ($results === false) {
                $results = [];
            }
        }

        return $results;
    }

    private function getRelativePath(string $file, string $basePath): string
    {
        $file = realpath($file) ?: $file;
        $basePath = realpath($basePath) ?: $basePath;
        
        if (strpos($file, $basePath) === 0) {
            $relative = substr($file, strlen($basePath));
            return ltrim($relative, DIRECTORY_SEPARATOR);
        }
        
        return $file;
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}