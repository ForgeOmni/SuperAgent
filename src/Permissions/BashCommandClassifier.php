<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

class BashCommandClassifier
{
    private const SAFE_ENV_VARS = [
        'NODE_ENV',
        'PYTHONUNBUFFERED',
        'RUST_LOG',
        'DEBUG',
        'VERBOSE',
        'CI',
        'TERM',
        'LANG',
        'LC_ALL',
    ];
    
    private const DANGEROUS_ENV_VARS = [
        'PATH',
        'LD_PRELOAD',
        'LD_LIBRARY_PATH',
        'PYTHONPATH',
        'NODE_PATH',
        'PERL5LIB',
        'RUBYLIB',
        'CLASSPATH',
    ];
    
    private const DANGEROUS_COMMANDS = [
        'rm' => ['risk' => 'high', 'category' => 'destructive'],
        'mv' => ['risk' => 'medium', 'category' => 'destructive'],
        'chmod' => ['risk' => 'high', 'category' => 'permission'],
        'chown' => ['risk' => 'high', 'category' => 'permission'],
        'sudo' => ['risk' => 'critical', 'category' => 'privilege'],
        'su' => ['risk' => 'critical', 'category' => 'privilege'],
        'kill' => ['risk' => 'high', 'category' => 'process'],
        'pkill' => ['risk' => 'high', 'category' => 'process'],
        'killall' => ['risk' => 'high', 'category' => 'process'],
        'dd' => ['risk' => 'critical', 'category' => 'destructive'],
        'mkfs' => ['risk' => 'critical', 'category' => 'destructive'],
        'format' => ['risk' => 'critical', 'category' => 'destructive'],
        'fdisk' => ['risk' => 'critical', 'category' => 'destructive'],
        'curl' => ['risk' => 'medium', 'category' => 'network'],
        'wget' => ['risk' => 'medium', 'category' => 'network'],
        'nc' => ['risk' => 'high', 'category' => 'network'],
        'netcat' => ['risk' => 'high', 'category' => 'network'],
        'ssh' => ['risk' => 'medium', 'category' => 'network'],
        'scp' => ['risk' => 'medium', 'category' => 'network'],
    ];
    
    private const SAFE_COMMAND_PREFIXES = [
        'git status',
        'git diff',
        'git log',
        'git branch',
        'git show',
        'npm list',
        'npm view',
        'npm info',
        'yarn list',
        'yarn info',
        'composer show',
        'pip list',
        'pip show',
        'docker ps',
        'docker images',
        'docker logs',
        'ls',
        'cat',
        'echo',
        'pwd',
        'which',
        'whoami',
        'date',
        'env',
        'printenv',
    ];
    
    public function classify(string $command): CommandClassification
    {
        $command = trim($command);
        
        if (empty($command)) {
            return new CommandClassification(
                risk: 'low',
                category: 'empty',
                prefix: null,
                isTooComplex: false,
            );
        }
        
        $components = $this->parseCommand($command);
        
        if ($components['isTooComplex']) {
            return new CommandClassification(
                risk: 'high',
                category: 'complex',
                prefix: null,
                isTooComplex: true,
                reason: 'Command contains substitutions, expansions, or control flow',
            );
        }
        
        $prefix = $this->extractPrefix($components['commands']);
        
        foreach (self::SAFE_COMMAND_PREFIXES as $safePrefix) {
            if (str_starts_with($prefix ?? '', $safePrefix)) {
                return new CommandClassification(
                    risk: 'low',
                    category: 'safe',
                    prefix: $prefix,
                    isTooComplex: false,
                );
            }
        }
        
        $mainCommand = $components['commands'][0] ?? null;
        if ($mainCommand && isset(self::DANGEROUS_COMMANDS[$mainCommand])) {
            $info = self::DANGEROUS_COMMANDS[$mainCommand];
            return new CommandClassification(
                risk: $info['risk'],
                category: $info['category'],
                prefix: $prefix,
                isTooComplex: false,
                reason: "Command '{$mainCommand}' is classified as {$info['risk']} risk",
            );
        }
        
        if ($this->containsDangerousPatterns($command)) {
            return new CommandClassification(
                risk: 'high',
                category: 'dangerous-pattern',
                prefix: $prefix,
                isTooComplex: false,
                reason: 'Command contains dangerous patterns',
            );
        }
        
        return new CommandClassification(
            risk: 'medium',
            category: 'unknown',
            prefix: $prefix,
            isTooComplex: false,
        );
    }
    
    private function parseCommand(string $command): array
    {
        $isTooComplex = false;
        $commands = [];
        
        if (preg_match('/[\$\(\)\`\{\}]|\||&&|\|\||;/', $command)) {
            $isTooComplex = true;
        }
        
        if (str_contains($command, '<<') || str_contains($command, '>>')) {
            $isTooComplex = true;
        }
        
        if (!$isTooComplex) {
            $parts = preg_split('/\s+/', $command);
            $filtered = [];
            
            $skipNext = false;
            foreach ($parts as $part) {
                if ($skipNext) {
                    $skipNext = false;
                    continue;
                }
                
                if (str_contains($part, '=') && !str_contains($part, ' ')) {
                    $var = explode('=', $part)[0];
                    if (in_array($var, self::SAFE_ENV_VARS, true)) {
                        continue;
                    }
                }
                
                $filtered[] = $part;
                
                if (in_array($part, ['-c', '-e', '--command', '--exec'], true)) {
                    $skipNext = true;
                }
            }
            
            $commands = $filtered;
        }
        
        return [
            'isTooComplex' => $isTooComplex,
            'commands' => $commands,
        ];
    }
    
    private function extractPrefix(array $commands): ?string
    {
        if (count($commands) === 0) {
            return null;
        }
        
        if (count($commands) === 1) {
            return $commands[0];
        }
        
        $first = $commands[0];
        $second = $commands[1];
        
        if (str_starts_with($second, '-')) {
            return $first;
        }
        
        return "{$first} {$second}";
    }
    
    private function containsDangerousPatterns(string $command): bool
    {
        $patterns = [
            '/rm\s+-rf?\s+\//',
            '/>\s*\/dev\/[a-z]+/',
            '/chmod\s+777/',
            '/curl\s+.*\|\s*sh/',
            '/wget\s+.*\|\s*bash/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }
        
        return false;
    }
}

class CommandClassification
{
    public function __construct(
        public readonly string $risk, // low, medium, high, critical
        public readonly string $category,
        public readonly ?string $prefix,
        public readonly bool $isTooComplex,
        public readonly ?string $reason = null,
    ) {}
    
    public function isHighRisk(): bool
    {
        return in_array($this->risk, ['high', 'critical'], true);
    }
    
    public function requiresApproval(): bool
    {
        return $this->risk !== 'low';
    }
}