<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

use SuperAgent\Permissions\PermissionRuleParser;

class HookMatcher
{
    /**
     * @var HookInterface[]
     */
    private array $hooks = [];
    
    public function __construct(
        public readonly ?string $matcher = null,
        array $hooks = [],
        public readonly ?string $pluginName = null,
    ) {
        foreach ($hooks as $hook) {
            $this->addHook($hook);
        }
    }
    
    public function addHook(HookInterface $hook): void
    {
        $this->hooks[] = $hook;
    }
    
    /**
     * @return HookInterface[]
     */
    public function getHooks(): array
    {
        return $this->hooks;
    }
    
    public function matches(string $toolName = null, array $context = []): bool
    {
        if ($this->matcher === null) {
            return true; // No matcher means always match
        }
        
        if ($toolName === null) {
            return false;
        }
        
        try {
            $parser = new PermissionRuleParser();
            $rule = $parser->parse($this->matcher);
            
            if ($rule->toolName !== $toolName) {
                return false;
            }
            
            if ($rule->ruleContent === null) {
                return true;
            }
            
            // Check if the rule content matches the context
            $content = $this->extractContentFromContext($toolName, $context);
            
            if (str_ends_with($rule->ruleContent, '*')) {
                $prefix = substr($rule->ruleContent, 0, -1);
                return str_starts_with($content, $prefix);
            }
            
            return $content === $rule->ruleContent;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function extractContentFromContext(string $toolName, array $context): string
    {
        return match ($toolName) {
            'Bash' => $context['command'] ?? '',
            'Read', 'Write', 'Edit' => $context['file_path'] ?? '',
            'WebFetch' => $context['url'] ?? '',
            default => '',
        };
    }
    
    /**
     * Create a HookMatcher from configuration array
     */
    public static function fromConfig(array $config, ?string $pluginName = null): self
    {
        $matcher = $config['matcher'] ?? null;
        $hooks = [];
        
        foreach ($config['hooks'] ?? [] as $hookConfig) {
            $hook = self::createHookFromConfig($hookConfig);
            if ($hook !== null) {
                $hooks[] = $hook;
            }
        }
        
        return new self($matcher, $hooks, $pluginName);
    }
    
    private static function createHookFromConfig(array $config): ?HookInterface
    {
        $type = $config['type'] ?? null;
        
        return match ($type) {
            'command' => new CommandHook(
                command: $config['command'],
                shell: $config['shell'] ?? 'bash',
                timeout: $config['timeout'] ?? 30,
                async: $config['async'] ?? false,
                asyncRewake: $config['asyncRewake'] ?? false,
                once: $config['once'] ?? false,
                condition: $config['if'] ?? null,
                statusMessage: $config['statusMessage'] ?? null,
            ),
            'http' => new HttpHook(
                url: $config['url'],
                headers: $config['headers'] ?? [],
                allowedEnvVars: $config['allowedEnvVars'] ?? [],
                timeout: $config['timeout'] ?? 30,
                once: $config['once'] ?? false,
                condition: $config['if'] ?? null,
                statusMessage: $config['statusMessage'] ?? null,
            ),
            default => null,
        };
    }
}