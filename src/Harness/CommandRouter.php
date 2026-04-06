<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

/**
 * Routes slash commands from the REPL loop to handler closures.
 *
 * Built-in commands: /help, /status, /tasks, /compact, /continue,
 * /session, /clear, /model, /cost, /quit
 *
 * Custom commands can be registered via `register()`.
 */
class CommandRouter
{
    /** @var array<string, CommandDefinition> */
    private array $commands = [];

    public function __construct()
    {
        $this->registerBuiltins();
    }

    /**
     * Register a custom slash command.
     */
    public function register(string $name, string $description, \Closure $handler): void
    {
        $this->commands[strtolower($name)] = new CommandDefinition($name, $description, $handler);
    }

    /**
     * Check if input is a slash command (starts with /).
     */
    public function isCommand(string $input): bool
    {
        return str_starts_with(trim($input), '/');
    }

    /**
     * Parse a command line into [name, args].
     *
     * @return array{0: string, 1: string} [command_name, arguments]
     */
    public function parse(string $input): array
    {
        $input = trim($input);
        if (!str_starts_with($input, '/')) {
            return ['', $input];
        }

        $parts = explode(' ', substr($input, 1), 2);
        $name = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        return [$name, $args];
    }

    /**
     * Execute a command. Returns the handler result or null if unknown.
     *
     * @param  string $input      Full input line (e.g. "/status --verbose")
     * @param  array  $context    Shared harness context for handlers
     * @return CommandResult
     */
    public function dispatch(string $input, array $context = []): CommandResult
    {
        [$name, $args] = $this->parse($input);

        if ($name === '' || !isset($this->commands[$name])) {
            $available = implode(', ', array_map(fn($c) => "/{$c->name}", $this->commands));
            return CommandResult::error("Unknown command: /{$name}. Available: {$available}");
        }

        try {
            $output = ($this->commands[$name]->handler)($args, $context);
            return CommandResult::success((string) $output);
        } catch (\Throwable $e) {
            return CommandResult::error("Command /{$name} failed: {$e->getMessage()}");
        }
    }

    /**
     * Get all registered command definitions.
     *
     * @return CommandDefinition[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Check if a specific command is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->commands[strtolower($name)]);
    }

    // ── Built-ins ─────────────────────────────────────────────────

    private function registerBuiltins(): void
    {
        $this->register('help', 'Show available commands', function (string $args, array $ctx): string {
            $lines = ["Available commands:"];
            foreach ($this->commands as $cmd) {
                $lines[] = "  /{$cmd->name} — {$cmd->description}";
            }
            return implode("\n", $lines);
        });

        $this->register('status', 'Show session status (turns, cost, tokens)', function (string $args, array $ctx): string {
            $turns = $ctx['turn_count'] ?? 0;
            $cost = $ctx['total_cost_usd'] ?? 0.0;
            $model = $ctx['model'] ?? 'unknown';
            $messageCount = $ctx['message_count'] ?? 0;
            return sprintf(
                "Model: %s | Turns: %d | Messages: %d | Cost: \$%.4f",
                $model, $turns, $messageCount, $cost,
            );
        });

        $this->register('tasks', 'List current tasks', function (string $args, array $ctx): string {
            $tasks = $ctx['tasks'] ?? [];
            if (empty($tasks)) {
                return 'No tasks.';
            }
            $lines = [];
            foreach ($tasks as $task) {
                $status = $task['status'] ?? 'unknown';
                $subject = $task['subject'] ?? 'Untitled';
                $id = $task['id'] ?? '?';
                $lines[] = "  [{$status}] #{$id} {$subject}";
            }
            return implode("\n", $lines);
        });

        $this->register('compact', 'Force context compaction', function (string $args, array $ctx): string {
            $compactor = $ctx['auto_compactor'] ?? null;
            if ($compactor === null) {
                return 'AutoCompactor not available.';
            }
            $messages = &$ctx['messages'];
            if ($messages === null) {
                return 'No messages to compact.';
            }
            $compacted = $compactor->maybeCompact($messages);
            return $compacted
                ? "Compaction done. Total tokens saved: {$compactor->getTotalTokensSaved()}"
                : 'No compaction needed or all strategies failed.';
        });

        $this->register('continue', 'Continue a pending tool loop', function (string $args, array $ctx): string {
            // Sets a flag that HarnessLoop reads
            return '__CONTINUE__';
        });

        $this->register('session', 'Session management (list|save|load|delete)', function (string $args, array $ctx): string {
            $parts = explode(' ', trim($args), 2);
            $subCommand = $parts[0] ?? 'list';
            $subArgs = $parts[1] ?? '';
            $sessionManager = $ctx['session_manager'] ?? null;

            if ($sessionManager === null) {
                return 'SessionManager not available (persistence disabled).';
            }

            return match ($subCommand) {
                'list' => $this->sessionList($sessionManager),
                'save' => $this->sessionSave($sessionManager, $subArgs, $ctx),
                'load' => $this->sessionLoad($sessionManager, $subArgs),
                'delete' => $this->sessionDelete($sessionManager, $subArgs),
                default => "Unknown subcommand: {$subCommand}. Use: list, save, load, delete",
            };
        });

        $this->register('clear', 'Clear conversation history', function (string $args, array $ctx): string {
            return '__CLEAR__';
        });

        $this->register('model', 'Show or change the current model', function (string $args, array $ctx): string {
            if ($args === '') {
                return 'Current model: ' . ($ctx['model'] ?? 'unknown');
            }
            return '__MODEL__:' . trim($args);
        });

        $this->register('cost', 'Show cost breakdown', function (string $args, array $ctx): string {
            $cost = $ctx['total_cost_usd'] ?? 0.0;
            $turns = $ctx['turn_count'] ?? 0;
            $perTurn = $turns > 0 ? $cost / $turns : 0;
            return sprintf("Total cost: \$%.4f | Turns: %d | Avg/turn: \$%.4f", $cost, $turns, $perTurn);
        });

        $this->register('quit', 'Exit the session', function (string $args, array $ctx): string {
            return '__QUIT__';
        });
    }

    // ── Session sub-commands ──────────────────────────────────────

    private function sessionList(object $mgr): string
    {
        $sessions = $mgr->listSessions(10);
        if (empty($sessions)) {
            return 'No saved sessions.';
        }
        $lines = ['Recent sessions:'];
        foreach ($sessions as $s) {
            $summary = mb_substr($s['summary'] ?? '', 0, 50);
            $lines[] = sprintf(
                "  %s — %s (%d msgs, \$%.3f)",
                $s['session_id'], $summary, $s['message_count'] ?? 0, $s['total_cost_usd'] ?? 0,
            );
        }
        return implode("\n", $lines);
    }

    private function sessionSave(object $mgr, string $id, array $ctx): string
    {
        if ($id === '') {
            $id = 'session-' . date('Ymd-His');
        }
        $messages = $ctx['messages_serialized'] ?? [];
        $mgr->save($id, $messages, [
            'model' => $ctx['model'] ?? null,
            'cwd' => $ctx['cwd'] ?? getcwd(),
            'total_cost_usd' => $ctx['total_cost_usd'] ?? 0.0,
        ]);
        return "Session saved: {$id}";
    }

    private function sessionLoad(object $mgr, string $id): string
    {
        if ($id === '') {
            return 'Usage: /session load <session-id>';
        }
        $data = $mgr->loadById(trim($id));
        if ($data === null) {
            return "Session not found: {$id}";
        }
        return '__SESSION_LOAD__:' . json_encode($data);
    }

    private function sessionDelete(object $mgr, string $id): string
    {
        if ($id === '') {
            return 'Usage: /session delete <session-id>';
        }
        $deleted = $mgr->delete(trim($id));
        return $deleted ? "Session deleted: {$id}" : "Session not found: {$id}";
    }
}

