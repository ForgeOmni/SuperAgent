<?php

declare(strict_types=1);

namespace SuperAgent\CLI;

use SuperAgent\CLI\Commands\AuthCommand;
use SuperAgent\CLI\Commands\ChatCommand;
use SuperAgent\CLI\Commands\InitCommand;

/**
 * SuperAgent CLI Application.
 *
 * The main entry point for the standalone CLI tool.
 * Parses CLI arguments and dispatches to the appropriate command.
 *
 * When symfony/console is available, it provides rich argument parsing.
 * Otherwise, falls back to basic argv parsing.
 */
class SuperAgentApplication
{
    private const VERSION = '0.8.2';
    private const NAME = 'SuperAgent';

    public function run(): int
    {
        $args = $_SERVER['argv'] ?? [];
        $script = array_shift($args); // Remove script name

        // Handle flags first
        if (in_array('--version', $args, true) || in_array('-V', $args, true)) {
            $this->printVersion();
            return 0;
        }

        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->printHelp();
            return 0;
        }

        // Parse options
        $options = $this->parseOptions($args);

        // Route to subcommand or default chat
        $command = $options['command'] ?? 'chat';

        return match ($command) {
            'init'    => (new InitCommand())->execute($options),
            'auth',
            'login'   => (new AuthCommand())->execute($options),
            default   => (new ChatCommand())->execute($options),
        };
    }

    /**
     * Parse CLI arguments into a structured options array.
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'command' => null,
            'prompt' => null,
            'model' => null,
            'provider' => null,
            'max_turns' => null,
            'system_prompt' => null,
            'project' => null,
            'json' => false,
            'verbose' => false,
            // v0.8.5 real-time rendering flags
            'rich' => true,            // Claude Code-style renderer (default on)
            'thinking' => 'normal',    // normal | verbose | hidden
            'plain' => false,          // disable ANSI colors / cursor control
        ];

        $positional = [];
        $i = 0;
        $options['auth_args'] = [];

        while ($i < count($args)) {
            $arg = $args[$i];

            if ($arg === '--model' || $arg === '-m') {
                $options['model'] = $args[++$i] ?? null;
            } elseif ($arg === '--provider' || $arg === '-p') {
                $options['provider'] = $args[++$i] ?? null;
            } elseif ($arg === '--max-turns') {
                $options['max_turns'] = (int) ($args[++$i] ?? 50);
            } elseif ($arg === '--system-prompt' || $arg === '-s') {
                $options['system_prompt'] = $args[++$i] ?? null;
            } elseif ($arg === '--project') {
                $options['project'] = $args[++$i] ?? null;
            } elseif ($arg === '--json') {
                $options['json'] = true;
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $options['verbose'] = true;
            } elseif ($arg === '--no-rich' || $arg === '--legacy-renderer') {
                $options['rich'] = false;
            } elseif ($arg === '--verbose-thinking') {
                $options['thinking'] = 'verbose';
            } elseif ($arg === '--no-thinking') {
                $options['thinking'] = 'hidden';
            } elseif ($arg === '--plain') {
                $options['plain'] = true;
            } elseif (! str_starts_with($arg, '-')) {
                $positional[] = $arg;
            }

            $i++;
        }

        // First positional arg: subcommand or prompt
        if (! empty($positional)) {
            if (in_array($positional[0], ['init', 'chat', 'auth', 'login'], true)) {
                $options['command'] = array_shift($positional);
            }

            if (in_array($options['command'] ?? '', ['auth', 'login'], true)) {
                // For `login <provider>`, rewrite to `auth login <provider>`.
                if ($options['command'] === 'login') {
                    $options['auth_args'] = array_merge(['login'], $positional);
                } else {
                    $options['auth_args'] = $positional;
                }
                $positional = [];
            }

            // Remaining positional args joined as prompt
            if (! empty($positional)) {
                $options['prompt'] = implode(' ', $positional);
            }
        }

        return $options;
    }

    private function printVersion(): void
    {
        echo self::NAME . ' v' . self::VERSION . PHP_EOL;
    }

    private function printHelp(): void
    {
        echo <<<HELP

  \033[1m{$this->name()}\033[0m v{$this->version()} — Local AI Coding Assistant

  \033[1mUsage:\033[0m
    superagent                          Interactive REPL mode
    superagent "fix the login bug"      One-shot task execution
    superagent init                     Initialize configuration
    superagent auth login claude-code   Import Claude Code OAuth login
    superagent auth login codex         Import Codex OAuth login
    superagent auth status              Show stored credentials

  \033[1mOptions:\033[0m
    -m, --model <model>                 Model name (e.g. sonnet, opus, haiku)
    -p, --provider <provider>           Provider (anthropic, openai, ollama, etc.)
        --max-turns <n>                 Maximum agent turns (default: 50)
    -s, --system-prompt <prompt>        Custom system prompt
        --project <path>               Project working directory
        --json                          Output results as JSON
    -v, --verbose                       Verbose output
        --verbose-thinking              Show full thinking stream (default: 1-line preview)
        --no-thinking                   Hide thinking entirely
        --plain                         Disable ANSI colors / cursor control (good for pipes / logs)
        --no-rich                       Use the legacy minimal renderer instead of Claude Code-style UI
    -V, --version                       Show version
    -h, --help                          Show this help

  \033[1mInteractive Commands:\033[0m
    /help                               Show available commands
    /model <name>                       Switch model
    /cost                               Display cost tracking
    /compact                            Force context compaction
    /session save|load|list|delete      Session management
    /clear                              Clear conversation
    /quit                               Exit

  \033[1mExamples:\033[0m
    superagent                          Start interactive chat
    superagent "explain this codebase"  Quick question
    superagent -m opus "refactor auth"  Use a specific model
    superagent -p ollama "fix bug"      Use local Ollama model

HELP;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::VERSION;
    }
}
