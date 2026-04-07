<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class ConfigTool extends Tool
{
    public function name(): string
    {
        return 'config';
    }

    public function description(): string
    {
        return 'Get or set configuration values for the agent session.';
    }

    public function category(): string
    {
        return 'system';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['get', 'set', 'list', 'load', 'save', 'reset'],
                    'description' => 'Action to perform: get, set, list, load, save, or reset.',
                ],
                'key' => [
                    'type' => 'string',
                    'description' => 'Configuration key (dot notation supported, e.g., "database.host").',
                ],
                'value' => [
                    'type' => ['string', 'number', 'boolean', 'array', 'object'],
                    'description' => 'Value to set (required for set action).',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Configuration file path (for load/save actions).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $key = $input['key'] ?? null;
        $value = $input['value'] ?? null;
        $file = $input['file'] ?? null;

        switch ($action) {
            case 'get':
                return $this->getConfig($key);
                
            case 'set':
                return $this->setConfig($key, $value);
                
            case 'list':
                return $this->listConfig();
                
            case 'load':
                return $this->loadConfig($file);
                
            case 'save':
                return $this->saveConfig($file);
                
            case 'reset':
                return $this->resetConfig();
                
            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    private function getConfig(?string $key): ToolResult
    {
        if ($key === null) {
            return ToolResult::error('Key is required for get action.');
        }

        $config = $this->state()->get($this->name(), 'config', []);
        $value = $this->getNestedValue($config, $key);
        
        if ($value === null) {
            return ToolResult::success([
                'key' => $key,
                'value' => null,
                'exists' => false,
            ]);
        }

        return ToolResult::success([
            'key' => $key,
            'value' => $value,
            'exists' => true,
        ]);
    }

    private function setConfig(?string $key, $value): ToolResult
    {
        if ($key === null) {
            return ToolResult::error('Key is required for set action.');
        }

        if ($value === null) {
            return ToolResult::error('Value is required for set action.');
        }

        $config = $this->state()->get($this->name(), 'config', []);
        $this->setNestedValue($config, $key, $value);
        $this->state()->set($this->name(), 'config', $config);

        return ToolResult::success([
            'message' => 'Configuration updated',
            'key' => $key,
            'value' => $value,
        ]);
    }

    private function listConfig(): ToolResult
    {
        $config = $this->state()->get($this->name(), 'config', []);

        return ToolResult::success([
            'config' => $config,
            'total_keys' => $this->countKeys($config),
        ]);
    }

    private function loadConfig(?string $file): ToolResult
    {
        if ($file === null) {
            return ToolResult::error('File path is required for load action.');
        }

        if (!file_exists($file)) {
            return ToolResult::error("Configuration file not found: {$file}");
        }

        $content = file_get_contents($file);
        
        if ($content === false) {
            return ToolResult::error("Failed to read configuration file: {$file}");
        }

        // Try to parse as JSON
        $data = json_decode($content, true);
        
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            // Try to parse as PHP array
            if (str_ends_with($file, '.php')) {
                $data = include $file;
                if (!is_array($data)) {
                    return ToolResult::error('PHP configuration file must return an array.');
                }
            } else {
                return ToolResult::error('Invalid configuration format. Expected JSON or PHP array.');
            }
        }

        $config = $this->state()->get($this->name(), 'config', []);
        $this->state()->set($this->name(), 'config', array_merge($config, $data));
        $this->state()->set($this->name(), 'configFile', $file);

        return ToolResult::success([
            'message' => 'Configuration loaded successfully',
            'file' => $file,
            'keys_loaded' => $this->countKeys($data),
        ]);
    }

    private function saveConfig(?string $file): ToolResult
    {
        if ($file === null) {
            $file = $this->state()->get($this->name(), 'configFile', '');
            if (empty($file)) {
                return ToolResult::error('No file specified and no default configuration file set.');
            }
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        $config = $this->state()->get($this->name(), 'config', []);

        // Save as JSON
        $content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($file, $content) === false) {
            return ToolResult::error("Failed to write configuration file: {$file}");
        }

        $this->state()->set($this->name(), 'configFile', $file);

        return ToolResult::success([
            'message' => 'Configuration saved successfully',
            'file' => $file,
            'keys_saved' => $this->countKeys($config),
        ]);
    }

    private function resetConfig(): ToolResult
    {
        $config = $this->state()->get($this->name(), 'config', []);
        $keyCount = $this->countKeys($config);
        $this->state()->clearTool($this->name());

        return ToolResult::success([
            'message' => 'Configuration reset',
            'keys_removed' => $keyCount,
        ]);
    }

    private function getNestedValue(array $array, string $key)
    {
        $keys = explode('.', $key);
        $value = $array;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return null;
            }
            $value = $value[$k];
        }
        
        return $value;
    }

    private function setNestedValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;
        
        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }

    private function countKeys(array $array): int
    {
        $count = 0;
        
        foreach ($array as $value) {
            $count++;
            if (is_array($value)) {
                $count += $this->countKeys($value);
            }
        }
        
        return $count;
    }

    /**
     * Get current configuration (for testing/inspection).
     */
    public function getConfiguration(): array
    {
        return $this->state()->get($this->name(), 'config', []);
    }

    /**
     * Reset configuration (for testing).
     */
    public function resetConfiguration(): void
    {
        $this->state()->clearTool($this->name());
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}