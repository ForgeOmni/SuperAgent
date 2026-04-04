<?php

namespace SuperAgent\Tools;

use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Tools\BuiltinToolRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 工具按需加载器
 * 负责管理和按需加载工具实例
 */
class ToolLoader
{
    private array $toolRegistry = [];
    private array $loadedTools = [];
    private array $config;
    private LoggerInterface $logger;
    
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();
        
        // 注册内置工具
        $this->registerBuiltinTools();
    }
    
    /**
     * 注册工具类
     */
    public function register(string $name, string|callable $toolClass, array $metadata = []): void
    {
        $this->toolRegistry[$name] = [
            'class' => $toolClass,
            'metadata' => array_merge([
                'category' => 'general',
                'cost' => 0.0001,
                'cacheable' => false,
                'safe' => true,
                'description' => '',
            ], $metadata),
            'loaded' => false,
        ];
        
        $this->logger->debug('Tool registered', ['name' => $name]);
    }
    
    /**
     * 加载单个工具
     */
    public function load(string $name): ?ToolInterface
    {
        // 已加载
        if (isset($this->loadedTools[$name])) {
            return $this->loadedTools[$name];
        }
        
        // 未注册
        if (!isset($this->toolRegistry[$name])) {
            $this->logger->warning('Tool not registered', ['name' => $name]);
            return null;
        }
        
        $registration = $this->toolRegistry[$name];
        $toolClass = $registration['class'];
        
        try {
            // 创建工具实例
            if (is_callable($toolClass)) {
                $tool = $toolClass();
            } elseif (is_string($toolClass)) {
                if (!class_exists($toolClass)) {
                    throw new \RuntimeException("Tool class not found: {$toolClass}");
                }
                $tool = new $toolClass();
            } else {
                throw new \InvalidArgumentException("Invalid tool class type");
            }
            
            if (!$tool instanceof ToolInterface) {
                throw new \RuntimeException("Tool must implement ToolInterface");
            }
            
            $this->loadedTools[$name] = $tool;
            $this->toolRegistry[$name]['loaded'] = true;
            
            $this->logger->info('Tool loaded', ['name' => $name]);
            
            return $tool;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to load tool', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * 批量加载工具
     */
    public function loadMany(array $names): array
    {
        $tools = [];
        
        foreach ($names as $name) {
            $tool = $this->load($name);
            if ($tool !== null) {
                $tools[] = $tool;
            }
        }
        
        return $tools;
    }
    
    /**
     * 根据类别加载工具
     */
    public function loadByCategory(string $category): array
    {
        $tools = [];
        
        foreach ($this->toolRegistry as $name => $registration) {
            if ($registration['metadata']['category'] === $category) {
                $tool = $this->load($name);
                if ($tool !== null) {
                    $tools[] = $tool;
                }
            }
        }
        
        return $tools;
    }
    
    /**
     * 根据任务智能选择工具
     */
    public function loadForTask(string $task): array
    {
        // 基础工具集
        $basicTools = $this->config['basic_tools'] ?? ['read_file', 'write_file', 'bash'];
        $tools = $this->loadMany($basicTools);
        
        // 根据任务关键词加载额外工具
        $taskLower = strtolower($task);
        
        if (str_contains($taskLower, 'search') || str_contains($taskLower, 'find')) {
            $tools = array_merge($tools, $this->loadMany(['grep', 'glob', 'search']));
        }
        
        if (str_contains($taskLower, 'web') || str_contains($taskLower, 'url')) {
            $tools = array_merge($tools, $this->loadMany(['web_fetch', 'web_search']));
        }
        
        if (str_contains($taskLower, 'test') || str_contains($taskLower, 'phpunit')) {
            $tools = array_merge($tools, $this->loadMany(['phpunit', 'test_runner']));
        }
        
        if (str_contains($taskLower, 'git') || str_contains($taskLower, 'commit')) {
            $tools = array_merge($tools, $this->loadMany(['git', 'github']));
        }
        
        if (str_contains($taskLower, 'edit') || str_contains($taskLower, 'modify')) {
            $tools = array_merge($tools, $this->loadMany(['edit_file', 'multi_edit']));
        }
        
        return array_unique($tools, SORT_REGULAR);
    }
    
    /**
     * 获取默认工具集
     */
    public function getDefaultTools(): array
    {
        $defaultNames = $this->config['default_tools'] ?? [
            'read_file',
            'write_file',
            'edit_file',
            'bash',
            'grep',
            'glob',
        ];
        
        return $this->loadMany($defaultNames);
    }
    
    /**
     * 获取所有可用工具
     */
    public function getAllTools(): array
    {
        $tools = [];
        
        foreach (array_keys($this->toolRegistry) as $name) {
            $tool = $this->load($name);
            if ($tool !== null) {
                $tools[] = $tool;
            }
        }
        
        return $tools;
    }
    
    /**
     * 获取已加载的工具
     */
    public function getLoadedTools(): array
    {
        return array_values($this->loadedTools);
    }
    
    /**
     * 卸载工具以释放内存
     */
    public function unload(string $name): void
    {
        if (isset($this->loadedTools[$name])) {
            unset($this->loadedTools[$name]);
            if (isset($this->toolRegistry[$name])) {
                $this->toolRegistry[$name]['loaded'] = false;
            }
            
            $this->logger->debug('Tool unloaded', ['name' => $name]);
        }
    }
    
    /**
     * 卸载所有工具
     */
    public function unloadAll(): void
    {
        $this->loadedTools = [];
        foreach ($this->toolRegistry as $name => $registration) {
            $this->toolRegistry[$name]['loaded'] = false;
        }
        
        $this->logger->info('All tools unloaded');
    }
    
    /**
     * 获取工具元数据
     */
    public function getMetadata(string $name): ?array
    {
        return $this->toolRegistry[$name]['metadata'] ?? null;
    }
    
    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        return [
            'registered' => count($this->toolRegistry),
            'loaded' => count($this->loadedTools),
            'categories' => array_unique(array_column(
                array_column($this->toolRegistry, 'metadata'),
                'category'
            )),
        ];
    }
    
    /**
     * Register all builtin tools from BuiltinToolRegistry::classMap().
     * This keeps ToolLoader perfectly in sync with BuiltinToolRegistry.
     */
    private function registerBuiltinTools(): void
    {
        foreach (BuiltinToolRegistry::classMap() as $name => $class) {
            // Instantiate a throw-away instance just to read category/description,
            // then discard it — the real instance is created lazily in load().
            try {
                $probe = new $class();
                $this->register($name, $class, [
                    'category'    => $probe->category(),
                    'description' => $probe->description(),
                    'safe'        => $probe->isReadOnly(),
                    'cacheable'   => $probe->isReadOnly(),
                ]);
            } catch (\Throwable) {
                // Fallback for tools whose constructor has required args
                $this->register($name, $class);
            }
        }
    }
    
    /**
     * 获取默认配置
     */
    private function getDefaultConfig(): array
    {
        return [
            'default_tools' => [
                'read_file',
                'write_file',
                'edit_file',
                'bash',
                'grep',
                'glob',
            ],
            'basic_tools' => [
                'read_file',
                'write_file',
                'bash',
            ],
            'auto_load' => true,
            'lazy_load' => true,
        ];
    }
}