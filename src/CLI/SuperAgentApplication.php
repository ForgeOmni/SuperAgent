<?php

declare(strict_types=1);

namespace SuperAgent\CLI;

use SuperAgent\CLI\Commands\AuthCommand;
use SuperAgent\CLI\Commands\AutoCommand;
use SuperAgent\CLI\Commands\ChatCommand;
use SuperAgent\CLI\Commands\EvalCommand;
use SuperAgent\CLI\Commands\HealthCommand;
use SuperAgent\CLI\Commands\InitCommand;
use SuperAgent\CLI\Commands\McpCommand;
use SuperAgent\CLI\Commands\ModelsCommand;
use SuperAgent\CLI\Commands\ResumeCommand;
use SuperAgent\CLI\Commands\SkillsCommand;
use SuperAgent\CLI\Commands\SmartCommand;
use SuperAgent\CLI\Commands\SubtaskCommand;
use SuperAgent\CLI\Commands\SwarmCommand;

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
    private const VERSION = '1.0.1';
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

        // Opt-in background refresh of the model catalog. No-op unless
        // SUPERAGENT_MODELS_AUTO_UPDATE=1 AND SUPERAGENT_MODELS_URL is set AND
        // the local override is stale (>7d). Swallows network failures.
        \SuperAgent\Providers\ModelCatalog::maybeAutoUpdate();

        // Route to subcommand or default chat
        $command = $options['command'] ?? 'chat';

        return match ($command) {
            'init'    => (new InitCommand())->execute($options),
            'auth',
            'login'   => (new AuthCommand())->execute($options),
            'models'  => (new ModelsCommand())->execute($options),
            'mcp'     => (new McpCommand())->execute($options),
            'skills'  => (new SkillsCommand())->execute($options),
            'swarm'   => (new SwarmCommand())->execute($options),
            // Manually run capability evaluations on selected models × dimensions
            // and persist scores to ~/.superagent/model_scores.json — the input
            // AutoModelStrategy uses for score-aware routing.
            'eval'    => (new EvalCommand())->execute($options),
            // Heuristic auto-mode (keyword + structure analysis). Single
            // model handles every subtask. See AutoCommand docblock for
            // the distinction from `smart`.
            'auto'    => (new AutoCommand())->execute($options),
            // Eval-score-driven task orchestration: brain model plans + splits,
            // subtasks routed to (best | cheapest-passing) by dim, then merged.
            // Distinct from the existing keyword-heuristic AutoMode.
            'smart'   => (new SmartCommand())->execute($options),
            // INTERNAL — single-subtask worker spawned by SmartOrchestrator's
            // parallel path. Not exposed in `--help`; reads stdin JSON, emits
            // stdout JSON. See SubtaskCommand for the protocol.
            '_subtask' => (new SubtaskCommand())->execute($options),
            // 0.9.7+ — cross-harness session resume (jcode-style). Imports
            // Claude Code / Codex CLI session logs into Message[] that any
            // SuperAgent provider can replay (typically via `Transcoder`).
            'resume'  => (new ResumeCommand())->execute($options),
            'health',
            'doctor'  => (new HealthCommand())->execute($options),
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
        $options['models_args'] = [];
        $options['mcp_args'] = [];
        $options['skills_args'] = [];
        $options['swarm_args'] = [];
        $options['health_args'] = [];
        $options['eval_args'] = [];
        $options['smart_args'] = [];
        $options['auto_args'] = [];
        // 0.9.7+ — `superagent resume <list|show|load> --from <harness> ...`
        $options['resume_args'] = [];

        // Raw args captured after a subcommand boundary — flags global parser
        // doesn't recognize (e.g. `eval run --models X --dims Y`) end up here
        // and are routed below to the subcommand's `<name>_args` slot.
        $subcommandRaw = null;
        $knownSubcommands = ['init', 'chat', 'auth', 'login', 'models', 'mcp', 'skills', 'swarm', 'health', 'doctor', 'resume', 'eval', 'smart', 'auto', '_subtask'];

        while ($i < count($args)) {
            $arg = $args[$i];

            if ($subcommandRaw !== null) {
                $subcommandRaw[] = $arg;
                $i++;
                continue;
            }

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
            } elseif ($arg === '--output') {
                // `--output json-stream` — emit one-line NDJSON per
                // wire event (v1 protocol) to stdout. See
                // docs/WIRE_PROTOCOL.md. Other values reserved for
                // future transport modes (e.g. `acp`).
                $options['output'] = (string) ($args[++$i] ?? '');
            } elseif (! str_starts_with($arg, '-')) {
                // Only the FIRST positional may be a subcommand. Words
                // appearing after an earlier positional are part of the
                // chat prompt — e.g. `fix the login bug` must NOT treat
                // `login` as the `login` subcommand. Without this guard,
                // ordinary prompt text gets misrouted whenever it happens
                // to include any reserved word.
                if (empty($positional)
                    && $options['command'] === null
                    && in_array($arg, $knownSubcommands, true)
                ) {
                    $options['command'] = $arg;
                    $subcommandRaw = [];
                } else {
                    $positional[] = $arg;
                }
            }

            $i++;
        }

        // If the in-loop scanner found a subcommand, route the raw post-
        // subcommand args (including flags like `--models X`) to the right
        // slot. Otherwise fall back to the legacy positional-only path
        // (preserves behavior for `chat <prompt>` and the older subcommands
        // that only used positional sub-subcommands).
        if ($options['command'] !== null && $subcommandRaw !== null) {
            $cmd = $options['command'];
            $slot = match ($cmd) {
                'auth', 'login' => 'auth_args',
                'models'        => 'models_args',
                'mcp'           => 'mcp_args',
                'skills'        => 'skills_args',
                'swarm'         => 'swarm_args',
                'health',
                'doctor'        => 'health_args',
                'resume'        => 'resume_args',
                'eval'          => 'eval_args',
                'smart'         => 'smart_args',
                'auto'          => 'auto_args',
                '_subtask'      => null,  // takes JSON on stdin, no args
                default         => null,
            };
            if ($cmd === 'login') {
                // `login <provider>` → reuse `auth_args` with leading 'login'.
                $options['auth_args'] = array_merge(['login'], $subcommandRaw);
            } elseif ($slot !== null) {
                $options[$slot] = $subcommandRaw;
            } else {
                // `chat <prompt>` etc. — join the positional remnants.
                $promptParts = array_values(array_filter(
                    $subcommandRaw,
                    fn ($a) => ! str_starts_with((string) $a, '-'),
                ));
                if (! empty($promptParts)) {
                    $options['prompt'] = implode(' ', $promptParts);
                }
            }
        } elseif (! empty($positional)) {
            // No subcommand detected — everything left is the chat prompt.
            $options['prompt'] = implode(' ', $positional);
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
    superagent auth login gemini        Import Gemini CLI login (OAuth or API key)
    superagent auth status              Show stored credentials
    superagent models list              List bundled + overridden models
    superagent models update            Fetch latest model catalog from remote URL
    superagent models status            Show catalog source + age
    superagent models reset             Delete user override and fall back to bundled
    superagent eval                     Run capability evals on selected models × dims
    superagent eval list                Show available eval dimensions
    superagent eval show                Print current model_scores.json as a table
    superagent smart "<task>"           Score-driven plan+route+merge for a single task
    superagent smart "<task>" --dry-run Show the plan without executing
    superagent smart "<task>" --max-cost 0.5  Abort if running spend exceeds the cap
    superagent smart show               List recent smart-run logs
    superagent smart show <id|--last>   Print one run's summary
    superagent smart replay <id|--last> Re-execute a saved plan (skip planning)
    superagent auto "<task>"            Heuristic auto-mode (single vs multi-agent)
    superagent auto "<task>" --analyze-only  Show complexity analysis without running
    superagent health                   5s cURL probe of every configured provider
    superagent health --all             Probe every known provider (surface missing keys)
    superagent health --json            Machine-readable output

  \033[1mOptions:\033[0m
    -m, --model <model>                 Model name (e.g. sonnet, opus, haiku)
    -p, --provider <provider>           Provider (anthropic, openai, gemini, ollama, openrouter, bedrock)
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
