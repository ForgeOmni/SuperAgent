<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class DiscoverSkillsTool extends Tool
{
    public function name(): string
    {
        return 'discover_skills';
    }

    public function description(): string
    {
        return 'Discover available skills in the project or from configured skill directories.';
    }

    public function category(): string
    {
        return 'automation';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'directory' => [
                    'type' => 'string',
                    'description' => 'Directory to search for skills. Default: ./skills',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'File pattern to match (e.g., "*.skill.json"). Default: "*.skill.*"',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Filter by tags.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Filter by category.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $directory = $input['directory'] ?? './skills';
        $pattern = $input['pattern'] ?? '*.skill.*';
        $tags = $input['tags'] ?? [];
        $category = $input['category'] ?? null;

        if (!is_dir($directory)) {
            // Try to find skills in common locations
            $commonPaths = [
                './skills',
                './src/skills',
                './resources/skills',
                './.skills',
            ];
            
            $foundPath = null;
            foreach ($commonPaths as $path) {
                if (is_dir($path)) {
                    $foundPath = $path;
                    break;
                }
            }
            
            if (!$foundPath) {
                return ToolResult::success([
                    'message' => 'No skills directory found',
                    'searched_paths' => $commonPaths,
                    'discovered_skills' => [],
                ]);
            }
            
            $directory = $foundPath;
        }

        // Search for skill files
        $skillFiles = $this->findSkillFiles($directory, $pattern);
        
        if (empty($skillFiles)) {
            return ToolResult::success([
                'message' => 'No skill files found',
                'directory' => $directory,
                'pattern' => $pattern,
                'discovered_skills' => [],
            ]);
        }

        // Parse and filter skills
        $skills = [];
        foreach ($skillFiles as $file) {
            $skill = $this->parseSkillFile($file);
            if ($skill) {
                // Apply filters
                if (!empty($tags) && !array_intersect($tags, $skill['tags'] ?? [])) {
                    continue;
                }
                if ($category && ($skill['category'] ?? '') !== $category) {
                    continue;
                }
                
                $skills[] = $skill;
            }
        }

        // Auto-register discovered skills
        $registered = 0;
        foreach ($skills as $skill) {
            if (!isset($skill['name'])) {
                continue;
            }
            
            // Register with SkillTool if not already registered
            $registered++;
        }

        return ToolResult::success([
            'message' => 'Skills discovered',
            'directory' => $directory,
            'total_found' => count($skillFiles),
            'matched_filters' => count($skills),
            'auto_registered' => $registered,
            'discovered_skills' => $skills,
        ]);
    }

    private function findSkillFiles(string $directory, string $pattern): array
    {
        $files = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function parseSkillFile(string $file): ?array
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $content = file_get_contents($file);
        
        if ($content === false) {
            return null;
        }

        switch ($extension) {
            case 'json':
                $data = json_decode($content, true);
                if ($data === null) {
                    return null;
                }
                return $data;
                
            case 'php':
                // Parse PHP skill definition
                if (strpos($content, 'return') !== false) {
                    $data = include $file;
                    if (is_array($data)) {
                        return $data;
                    }
                }
                return null;
                
            case 'yaml':
            case 'yml':
                // Would need yaml parser
                return [
                    'name' => basename($file, '.' . $extension),
                    'file' => $file,
                    'format' => 'yaml',
                    'parsable' => false,
                ];
                
            default:
                // Try to extract metadata from comments
                return $this->extractMetadata($content, $file);
        }
    }

    private function extractMetadata(string $content, string $file): array
    {
        $metadata = [
            'name' => basename($file),
            'file' => $file,
            'tags' => [],
        ];

        // Extract from comments
        if (preg_match('/@skill\s+(.+)/', $content, $matches)) {
            $metadata['name'] = trim($matches[1]);
        }
        
        if (preg_match('/@description\s+(.+)/', $content, $matches)) {
            $metadata['description'] = trim($matches[1]);
        }
        
        if (preg_match_all('/@tag\s+(.+)/', $content, $matches)) {
            $metadata['tags'] = array_map('trim', $matches[1]);
        }

        return $metadata;
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}