<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class REPLTool extends Tool
{
    private static array $sessions = [];

    public function name(): string
    {
        return 'repl';
    }

    public function description(): string
    {
        return 'Execute code in an interactive REPL (Read-Eval-Print Loop) environment. Supports PHP, Python, Node.js, and Ruby.';
    }

    public function category(): string
    {
        return 'execution';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => [
                    'type' => 'string',
                    'enum' => ['php', 'python', 'node', 'ruby'],
                    'description' => 'The programming language for the REPL.',
                ],
                'code' => [
                    'type' => 'string',
                    'description' => 'Code to execute in the REPL.',
                ],
                'session_id' => [
                    'type' => 'string',
                    'description' => 'Optional session ID to maintain state between calls.',
                ],
                'reset' => [
                    'type' => 'boolean',
                    'description' => 'Reset the session before executing code.',
                ],
            ],
            'required' => ['language', 'code'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $language = $input['language'] ?? '';
        $code = $input['code'] ?? '';
        $sessionId = $input['session_id'] ?? 'default';
        $reset = $input['reset'] ?? false;

        if (empty($language)) {
            return ToolResult::error('Language is required.');
        }

        if (empty($code)) {
            return ToolResult::error('Code is required.');
        }

        // Reset session if requested
        if ($reset && isset(self::$sessions[$sessionId])) {
            unset(self::$sessions[$sessionId]);
        }

        try {
            $result = match ($language) {
                'php' => $this->executePhp($code, $sessionId),
                'python' => $this->executePython($code, $sessionId),
                'node' => $this->executeNode($code, $sessionId),
                'ruby' => $this->executeRuby($code, $sessionId),
                default => throw new \Exception("Unsupported language: {$language}"),
            };

            return ToolResult::success($result);
        } catch (\Exception $e) {
            return ToolResult::error('Execution failed: ' . $e->getMessage());
        }
    }

    private function executePhp(string $code, string $sessionId): string
    {
        // Create a temporary file for the PHP code
        $tempFile = tempnam(sys_get_temp_dir(), 'repl_php_');
        
        // Prepare the code with error handling
        $fullCode = "<?php\n";
        $fullCode .= "error_reporting(E_ALL);\n";
        $fullCode .= "ini_set('display_errors', 1);\n";
        
        // Load session state if exists
        if (isset(self::$sessions[$sessionId]['php'])) {
            $fullCode .= self::$sessions[$sessionId]['php'] . "\n";
        }
        
        $fullCode .= "\n// User code:\n";
        $fullCode .= $code;
        
        file_put_contents($tempFile, $fullCode);
        
        // Execute the PHP code
        $output = [];
        $returnVar = 0;
        exec('php ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnVar);
        
        unlink($tempFile);
        
        if ($returnVar !== 0) {
            throw new \Exception(implode("\n", $output));
        }
        
        // Store session state
        if (!isset(self::$sessions[$sessionId])) {
            self::$sessions[$sessionId] = [];
        }
        self::$sessions[$sessionId]['php'] = (self::$sessions[$sessionId]['php'] ?? '') . "\n" . $code;
        
        return implode("\n", $output);
    }

    private function executePython(string $code, string $sessionId): string
    {
        // Create a temporary file for Python code
        $tempFile = tempnam(sys_get_temp_dir(), 'repl_py_');
        
        // Prepare the code
        $fullCode = '';
        
        // Load session state if exists
        if (isset(self::$sessions[$sessionId]['python'])) {
            $fullCode .= self::$sessions[$sessionId]['python'] . "\n";
        }
        
        $fullCode .= "\n# User code:\n";
        $fullCode .= $code;
        
        file_put_contents($tempFile, $fullCode);
        
        // Execute the Python code
        $output = [];
        $returnVar = 0;
        exec('python3 ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnVar);
        
        if ($returnVar !== 0 && empty($output)) {
            // Try python if python3 is not available
            exec('python ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnVar);
        }
        
        unlink($tempFile);
        
        if ($returnVar !== 0) {
            throw new \Exception(implode("\n", $output));
        }
        
        // Store session state
        if (!isset(self::$sessions[$sessionId])) {
            self::$sessions[$sessionId] = [];
        }
        self::$sessions[$sessionId]['python'] = (self::$sessions[$sessionId]['python'] ?? '') . "\n" . $code;
        
        return implode("\n", $output);
    }

    private function executeNode(string $code, string $sessionId): string
    {
        // Create a temporary file for Node.js code
        $tempFile = tempnam(sys_get_temp_dir(), 'repl_js_');
        
        // Prepare the code
        $fullCode = '';
        
        // Load session state if exists
        if (isset(self::$sessions[$sessionId]['node'])) {
            $fullCode .= self::$sessions[$sessionId]['node'] . "\n";
        }
        
        $fullCode .= "\n// User code:\n";
        $fullCode .= $code;
        
        file_put_contents($tempFile, $fullCode);
        
        // Execute the Node.js code
        $output = [];
        $returnVar = 0;
        exec('node ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnVar);
        
        unlink($tempFile);
        
        if ($returnVar !== 0) {
            throw new \Exception(implode("\n", $output));
        }
        
        // Store session state
        if (!isset(self::$sessions[$sessionId])) {
            self::$sessions[$sessionId] = [];
        }
        self::$sessions[$sessionId]['node'] = (self::$sessions[$sessionId]['node'] ?? '') . "\n" . $code;
        
        return implode("\n", $output);
    }

    private function executeRuby(string $code, string $sessionId): string
    {
        // Create a temporary file for Ruby code
        $tempFile = tempnam(sys_get_temp_dir(), 'repl_rb_');
        
        // Prepare the code
        $fullCode = '';
        
        // Load session state if exists
        if (isset(self::$sessions[$sessionId]['ruby'])) {
            $fullCode .= self::$sessions[$sessionId]['ruby'] . "\n";
        }
        
        $fullCode .= "\n# User code:\n";
        $fullCode .= $code;
        
        file_put_contents($tempFile, $fullCode);
        
        // Execute the Ruby code
        $output = [];
        $returnVar = 0;
        exec('ruby ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnVar);
        
        unlink($tempFile);
        
        if ($returnVar !== 0) {
            throw new \Exception(implode("\n", $output));
        }
        
        // Store session state
        if (!isset(self::$sessions[$sessionId])) {
            self::$sessions[$sessionId] = [];
        }
        self::$sessions[$sessionId]['ruby'] = (self::$sessions[$sessionId]['ruby'] ?? '') . "\n" . $code;
        
        return implode("\n", $output);
    }

    /**
     * Clear all REPL sessions.
     */
    public static function clearSessions(): void
    {
        self::$sessions = [];
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}