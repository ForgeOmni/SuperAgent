<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Terminal;

use SuperAgent\Permissions\PermissionDecision;

/**
 * Terminal-based permission prompt for standalone CLI.
 *
 * Replaces Laravel's ConsolePermissionCallback with a pure PHP
 * implementation that works without the Illuminate Console framework.
 */
class PermissionPrompt
{
    private Renderer $renderer;

    /** @var array<string, bool> Remembered decisions for this session */
    private array $remembered = [];

    public function __construct(?Renderer $renderer = null)
    {
        $this->renderer = $renderer ?? new Renderer();
    }

    /**
     * Ask the user for permission to execute a tool.
     *
     * @param  string  $toolName   The tool being requested
     * @param  array   $input      The tool input parameters
     * @param  string  $riskLevel  Risk assessment (low, medium, high)
     * @return PermissionDecision
     */
    public function ask(string $toolName, array $input, string $riskLevel = 'medium'): PermissionDecision
    {
        // Check remembered decisions
        $key = $toolName . ':' . md5(json_encode($input));
        if (isset($this->remembered[$key])) {
            return $this->remembered[$key]
                ? PermissionDecision::ALLOW
                : PermissionDecision::DENY;
        }

        // Check if tool type is remembered (e.g., "always allow Read")
        if (isset($this->remembered[$toolName])) {
            return $this->remembered[$toolName]
                ? PermissionDecision::ALLOW
                : PermissionDecision::DENY;
        }

        // Display the permission prompt
        $this->renderer->newLine();
        $this->renderer->separator();

        $riskColor = match ($riskLevel) {
            'high' => '31',   // Red
            'medium' => '33', // Yellow
            default => '36',  // Cyan
        };

        $this->renderer->line(
            "\033[{$riskColor}m⚡ Permission required\033[0m: "
            . "\033[1m{$toolName}\033[0m"
        );

        // Show relevant input details
        $this->displayToolInput($toolName, $input);

        $this->renderer->newLine();
        $this->renderer->line('  [y] Allow once');
        $this->renderer->line('  [n] Deny');
        $this->renderer->line('  [a] Always allow this tool');
        $this->renderer->line('  [d] Always deny this tool');
        $this->renderer->newLine();

        $answer = strtolower($this->renderer->ask('Permission [y/n/a/d]: ') ?: 'n');

        $this->renderer->separator();

        return match ($answer) {
            'y', 'yes' => PermissionDecision::ALLOW,
            'a', 'always' => $this->remember($toolName, true),
            'd' => $this->remember($toolName, false),
            default => PermissionDecision::DENY,
        };
    }

    private function remember(string $toolName, bool $allow): PermissionDecision
    {
        $this->remembered[$toolName] = $allow;
        return $allow ? PermissionDecision::ALLOW : PermissionDecision::DENY;
    }

    /**
     * Display tool input details in a readable format.
     */
    private function displayToolInput(string $toolName, array $input): void
    {
        // Show the most relevant fields based on tool type
        $displayFields = match (true) {
            str_contains(strtolower($toolName), 'bash') => ['command'],
            str_contains(strtolower($toolName), 'edit'),
            str_contains(strtolower($toolName), 'write') => ['file_path', 'path'],
            str_contains(strtolower($toolName), 'read') => ['file_path', 'path'],
            str_contains(strtolower($toolName), 'glob'),
            str_contains(strtolower($toolName), 'grep') => ['pattern', 'path'],
            default => array_keys(array_slice($input, 0, 3)),
        };

        foreach ($displayFields as $field) {
            if (isset($input[$field])) {
                $value = is_string($input[$field])
                    ? $input[$field]
                    : json_encode($input[$field]);

                // Truncate long values
                if (strlen($value) > 100) {
                    $value = substr($value, 0, 97) . '...';
                }

                $this->renderer->line("  \033[2m{$field}:\033[0m {$value}");
            }
        }
    }
}
