<?php

namespace SuperAgent\Tools;

use SuperAgent\Contracts\ToolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 延迟工具解析器
 * 在模型调用工具时才实际加载工具实例
 */
class LazyToolResolver
{
    private ToolLoader $loader;
    private array $availableTools = [];
    private array $loadedTools = [];
    private LoggerInterface $logger;
    private bool $autoLoad;
    
    public function __construct(
        ?ToolLoader $loader = null,
        bool $autoLoad = true,
        ?LoggerInterface $logger = null
    ) {
        $this->loader = $loader ?? new ToolLoader();
        $this->autoLoad = $autoLoad;
        $this->logger = $logger ?? new NullLogger();
        
        // 初始化可用工具列表（只注册，不加载）
        $this->initializeAvailableTools();
    }
    
    /**
     * 初始化可用工具列表
     */
    private function initializeAvailableTools(): void
    {
        // 从ToolLoader获取所有注册的工具元数据
        $this->availableTools = [
            // 文件操作
            'read_file' => ['loaded' => false, 'description' => 'Read file contents'],
            'write_file' => ['loaded' => false, 'description' => 'Write content to file'],
            'edit_file' => ['loaded' => false, 'description' => 'Edit file content'],
            'multi_edit' => ['loaded' => false, 'description' => 'Multiple edits in one operation'],
            
            // 搜索
            'grep' => ['loaded' => false, 'description' => 'Search text in files'],
            'glob' => ['loaded' => false, 'description' => 'Find files by pattern'],
            'search' => ['loaded' => false, 'description' => 'Advanced code search'],
            
            // 系统
            'bash' => ['loaded' => false, 'description' => 'Execute shell commands'],
            'phpunit' => ['loaded' => false, 'description' => 'Run PHPUnit tests'],
            
            // Web
            'web_fetch' => ['loaded' => false, 'description' => 'Fetch web content'],
            'web_search' => ['loaded' => false, 'description' => 'Search the web'],
            
            // Git
            'git' => ['loaded' => false, 'description' => 'Git version control'],
            'github' => ['loaded' => false, 'description' => 'GitHub operations'],
            
            // 任务管理
            'todo' => ['loaded' => false, 'description' => 'Manage todo list'],
            'task_create' => ['loaded' => false, 'description' => 'Create new task'],
            'task_update' => ['loaded' => false, 'description' => 'Update task status'],
            
            // 计划模式
            'enter_plan_mode' => ['loaded' => false, 'description' => 'Enter planning mode'],
            'exit_plan_mode' => ['loaded' => false, 'description' => 'Exit planning mode'],
        ];
    }
    
    /**
     * 解析工具调用请求
     * 如果工具未加载，自动加载它
     */
    public function resolve(string $toolName): ?ToolInterface
    {
        // 检查是否已加载
        if (isset($this->loadedTools[$toolName])) {
            $this->logger->debug('Tool already loaded', ['tool' => $toolName]);
            return $this->loadedTools[$toolName];
        }
        
        // 检查是否可用
        if (!isset($this->availableTools[$toolName])) {
            $this->logger->warning('Tool not available', ['tool' => $toolName]);
            return null;
        }
        
        // 如果启用自动加载，加载工具
        if ($this->autoLoad) {
            return $this->loadTool($toolName);
        }
        
        $this->logger->info('Tool available but not loaded (auto-load disabled)', ['tool' => $toolName]);
        return null;
    }
    
    /**
     * 加载工具
     */
    public function loadTool(string $toolName): ?ToolInterface
    {
        if (isset($this->loadedTools[$toolName])) {
            return $this->loadedTools[$toolName];
        }
        
        $tool = $this->loader->load($toolName);
        if ($tool !== null) {
            $this->loadedTools[$toolName] = $tool;
            $this->availableTools[$toolName]['loaded'] = true;
            $this->logger->info('Tool loaded on-demand', ['tool' => $toolName]);
        }
        
        return $tool;
    }
    
    /**
     * 预加载基础工具集
     */
    public function preloadBasicTools(): array
    {
        $basicTools = ['read_file', 'write_file', 'bash', 'grep', 'glob', 'edit_file'];
        
        $loaded = [];
        foreach ($basicTools as $toolName) {
            $tool = $this->loadTool($toolName);
            if ($tool !== null) {
                $loaded[] = $tool;
            }
        }
        
        $this->logger->info('Basic tools preloaded', ['count' => count($loaded)]);
        
        return $loaded;
    }
    
    /**
     * 获取所有已加载的工具
     */
    public function getLoadedTools(): array
    {
        return array_values($this->loadedTools);
    }
    
    /**
     * 获取工具定义（用于发送给模型）
     */
    public function getToolDefinitions(bool $onlyLoaded = false): array
    {
        $definitions = [];
        
        foreach ($this->availableTools as $name => $info) {
            if ($onlyLoaded && !$info['loaded']) {
                continue;
            }
            
            // 如果已加载，从实际工具获取定义
            if (isset($this->loadedTools[$name])) {
                $tool = $this->loadedTools[$name];
                $definitions[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'input_schema' => $tool->getInputSchema(),
                ];
            } else {
                // 否则提供基础定义，让模型知道工具存在
                $definitions[] = [
                    'name' => $name,
                    'description' => $info['description'],
                    'available' => true,
                    'loaded' => false,
                ];
            }
        }
        
        return $definitions;
    }
    
    /**
     * 根据任务内容预测需要的工具并预加载
     */
    public function predictAndPreload(string $task): array
    {
        $predicted = [];
        $taskLower = strtolower($task);
        
        // 基础工具总是需要
        $predicted = array_merge($predicted, ['read_file', 'write_file', 'bash']);
        
        // 根据关键词预测
        if (str_contains($taskLower, 'search') || str_contains($taskLower, 'find')) {
            $predicted = array_merge($predicted, ['grep', 'glob', 'search']);
        }
        
        if (str_contains($taskLower, 'edit') || str_contains($taskLower, 'modify') || str_contains($taskLower, 'change')) {
            $predicted = array_merge($predicted, ['edit_file', 'multi_edit']);
        }
        
        if (str_contains($taskLower, 'test') || str_contains($taskLower, 'phpunit')) {
            $predicted[] = 'phpunit';
        }
        
        if (str_contains($taskLower, 'git') || str_contains($taskLower, 'commit') || str_contains($taskLower, 'push')) {
            $predicted = array_merge($predicted, ['git', 'github']);
        }
        
        if (str_contains($taskLower, 'web') || str_contains($taskLower, 'http') || str_contains($taskLower, 'url')) {
            $predicted = array_merge($predicted, ['web_fetch', 'web_search']);
        }
        
        if (str_contains($taskLower, 'task') || str_contains($taskLower, 'todo') || str_contains($taskLower, 'plan')) {
            $predicted = array_merge($predicted, ['todo', 'task_create', 'task_update', 'enter_plan_mode']);
        }
        
        // 预加载预测的工具
        $loaded = [];
        foreach (array_unique($predicted) as $toolName) {
            $tool = $this->loadTool($toolName);
            if ($tool !== null) {
                $loaded[] = $tool;
            }
        }
        
        $this->logger->info('Tools predicted and preloaded', [
            'task_preview' => substr($task, 0, 100),
            'predicted' => $predicted,
            'loaded_count' => count($loaded),
        ]);
        
        return $loaded;
    }
    
    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        $loaded = count($this->loadedTools);
        $available = count($this->availableTools);
        
        return [
            'available' => $available,
            'loaded' => $loaded,
            'load_ratio' => $loaded / max(1, $available),
            'tools' => array_map(function($name, $info) {
                return [
                    'name' => $name,
                    'loaded' => $info['loaded'],
                ];
            }, array_keys($this->availableTools), array_values($this->availableTools)),
        ];
    }
    
    /**
     * 卸载未使用的工具以释放内存
     */
    public function unloadUnused(array $keepTools = []): void
    {
        $unloaded = 0;
        
        foreach ($this->loadedTools as $name => $tool) {
            if (!in_array($name, $keepTools)) {
                unset($this->loadedTools[$name]);
                $this->availableTools[$name]['loaded'] = false;
                $unloaded++;
            }
        }
        
        if ($unloaded > 0) {
            $this->logger->info('Unloaded unused tools', ['count' => $unloaded]);
        }
    }
}