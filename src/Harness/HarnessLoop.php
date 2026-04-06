<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Session\SessionManager;

/**
 * Interactive REPL loop for the Agent Harness.
 *
 * Manages the lifecycle of a multi-turn conversation:
 *   - Accepts user input (text or slash commands)
 *   - Dispatches commands via CommandRouter
 *   - Submits prompts to an agent callback
 *   - Emits StreamEvents
 *   - Supports session save/load/continue
 *   - Enforces a busy lock to prevent concurrent submissions
 *
 * The loop itself is headless — it calls an $inputProvider closure to
 * read input and an $outputHandler closure to display output. This
 * keeps it testable without a real terminal.
 */
class HarnessLoop
{
    private CommandRouter $router;
    private StreamEventEmitter $emitter;
    private ?AutoCompactor $autoCompactor;
    private ?SessionManager $sessionManager;

    /** @var Message[] Current conversation messages */
    private array $messages = [];

    private string $sessionId;
    private string $model;
    private ?string $systemPrompt;
    private string $cwd;
    private int $turnCount = 0;
    private float $totalCostUsd = 0.0;
    private bool $busy = false;
    private bool $running = false;

    /**
     * @param \Closure(string, array): \Generator $agentRunner
     *   Callback that takes (prompt, options) and returns a Generator of AssistantMessage.
     *   This is the bridge to Agent/QueryEngine.
     */
    public function __construct(
        private readonly \Closure $agentRunner,
        ?CommandRouter $router = null,
        ?StreamEventEmitter $emitter = null,
        ?AutoCompactor $autoCompactor = null,
        ?SessionManager $sessionManager = null,
        string $model = 'claude-sonnet-4-6',
        ?string $systemPrompt = null,
        ?string $sessionId = null,
        ?string $cwd = null,
    ) {
        $this->router = $router ?? new CommandRouter();
        $this->emitter = $emitter ?? new StreamEventEmitter();
        $this->autoCompactor = $autoCompactor;
        $this->sessionManager = $sessionManager;
        $this->model = $model;
        $this->systemPrompt = $systemPrompt;
        $this->sessionId = $sessionId ?? ('session-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)));
        $this->cwd = $cwd ?? (getcwd() ?: '.');
    }

    // ── Main loop ─────────────────────────────────────────────────

    /**
     * Run the interactive loop.
     *
     * @param \Closure(): ?string       $inputProvider   Returns next input line, or null to quit
     * @param \Closure(string): void    $outputHandler   Displays output to the user
     */
    public function run(\Closure $inputProvider, \Closure $outputHandler): void
    {
        $this->running = true;

        while ($this->running) {
            $input = $inputProvider();

            if ($input === null) {
                $this->running = false;
                break;
            }

            $input = trim($input);
            if ($input === '') {
                continue;
            }

            $this->handleInput($input, $outputHandler);
        }

        // Auto-save session on exit
        $this->autoSaveSession();
    }

    /**
     * Process a single input line (command or prompt).
     * Can be used outside the run() loop for programmatic control.
     *
     * @return string|null  Output text, or null if prompt was submitted to agent
     */
    public function handleInput(string $input, ?\Closure $outputHandler = null): ?string
    {
        $output = $outputHandler ?? fn(string $s) => null;

        // Slash command?
        if ($this->router->isCommand($input)) {
            return $this->handleCommand($input, $output);
        }

        // Regular prompt — submit to agent
        if ($this->busy) {
            $msg = 'Agent is busy. Wait for completion or use /continue.';
            $output($msg);
            return $msg;
        }

        $this->submitPrompt($input, $output);
        return null;
    }

    /**
     * Stop the loop (callable from command handler or externally).
     */
    public function stop(): void
    {
        $this->running = false;
    }

    // ── Command handling ──────────────────────────────────────────

    private function handleCommand(string $input, \Closure $output): string
    {
        $result = $this->router->dispatch($input, $this->buildContext());

        // Handle control signals
        if ($result->isSignal('__QUIT__')) {
            $this->running = false;
            $output('Goodbye.');
            return 'Goodbye.';
        }

        if ($result->isSignal('__CLEAR__')) {
            $this->messages = [];
            $this->turnCount = 0;
            $this->totalCostUsd = 0.0;
            $output('Conversation cleared.');
            return 'Conversation cleared.';
        }

        if ($result->isSignal('__CONTINUE__')) {
            if ($this->hasPendingContinuation()) {
                $this->continuePending($output);
                return 'Continuing...';
            }
            $msg = 'No pending tool results to continue.';
            $output($msg);
            return $msg;
        }

        if ($result->isSignal('__MODEL__:')) {
            $newModel = $result->signalPayload('__MODEL__:');
            $this->model = $newModel;
            $this->autoCompactor?->setModel($newModel);
            $msg = "Model changed to: {$newModel}";
            $output($msg);
            return $msg;
        }

        if ($result->isSignal('__SESSION_LOAD__:')) {
            $data = json_decode($result->signalPayload('__SESSION_LOAD__:'), true);
            if ($data !== null) {
                $this->restoreFromSnapshot($data);
                $msg = "Session loaded: {$data['session_id']} ({$data['message_count']} messages)";
                $output($msg);
                return $msg;
            }
        }

        $output($result->output);
        return $result->output;
    }

    // ── Prompt submission ─────────────────────────────────────────

    private function submitPrompt(string $prompt, \Closure $output): void
    {
        $this->busy = true;

        try {
            // Auto-compact before submitting
            if ($this->autoCompactor !== null) {
                $compacted = $this->autoCompactor->maybeCompact($this->messages);
                if ($compacted) {
                    $this->emitter->emit(new StatusEvent(
                        "Auto-compacted context ({$this->autoCompactor->getTotalTokensSaved()} tokens saved)",
                    ));
                }
            }

            $this->messages[] = new UserMessage($prompt);

            $generator = ($this->agentRunner)($prompt, [
                'messages' => $this->messages,
                'model' => $this->model,
                'system_prompt' => $this->systemPrompt,
                'session_id' => $this->sessionId,
                'cwd' => $this->cwd,
            ]);

            $lastMessage = null;
            foreach ($generator as $assistantMessage) {
                if ($assistantMessage instanceof AssistantMessage) {
                    $this->messages[] = $assistantMessage;
                    $this->turnCount++;
                    $lastMessage = $assistantMessage;

                    if ($assistantMessage->usage) {
                        $cost = \SuperAgent\CostCalculator::calculate($this->model, $assistantMessage->usage);
                        $this->totalCostUsd += $cost;
                    }

                    // Output text
                    $text = $assistantMessage->text();
                    if ($text !== '') {
                        $output($text);
                    }
                }
            }

            if ($lastMessage !== null) {
                $this->emitter->emit(new AgentCompleteEvent(
                    totalTurns: $this->turnCount,
                    totalCostUsd: $this->totalCostUsd,
                    finalMessage: $lastMessage,
                ));
            }

        } catch (\Throwable $e) {
            $this->emitter->emit(new ErrorEvent(
                message: $e->getMessage(),
                recoverable: true,
            ));
            $output("Error: {$e->getMessage()}");
        } finally {
            $this->busy = false;
        }
    }

    // ── Continue pending ──────────────────────────────────────────

    /**
     * Check if the last message is a tool result waiting for model response.
     */
    public function hasPendingContinuation(): bool
    {
        if (empty($this->messages)) {
            return false;
        }

        $last = end($this->messages);
        return $last instanceof ToolResultMessage;
    }

    /**
     * Continue the agent loop without a new user message.
     */
    private function continuePending(\Closure $output): void
    {
        $this->busy = true;

        try {
            $generator = ($this->agentRunner)('', [
                'messages' => $this->messages,
                'model' => $this->model,
                'system_prompt' => $this->systemPrompt,
                'session_id' => $this->sessionId,
                'cwd' => $this->cwd,
                'continue_pending' => true,
            ]);

            foreach ($generator as $assistantMessage) {
                if ($assistantMessage instanceof AssistantMessage) {
                    $this->messages[] = $assistantMessage;
                    $this->turnCount++;
                    $text = $assistantMessage->text();
                    if ($text !== '') {
                        $output($text);
                    }
                }
            }
        } catch (\Throwable $e) {
            $output("Error: {$e->getMessage()}");
        } finally {
            $this->busy = false;
        }
    }

    // ── Session management ────────────────────────────────────────

    /**
     * Auto-save the current session if SessionManager is available.
     */
    public function autoSaveSession(): void
    {
        if ($this->sessionManager === null || empty($this->messages)) {
            return;
        }

        $serialized = array_map(fn(Message $m) => $m->toArray(), $this->messages);
        $this->sessionManager->save($this->sessionId, $serialized, [
            'model' => $this->model,
            'cwd' => $this->cwd,
            'system_prompt' => $this->systemPrompt,
            'total_cost_usd' => $this->totalCostUsd,
        ]);
    }

    /**
     * Restore conversation state from a session snapshot.
     */
    public function restoreFromSnapshot(array $snapshot): void
    {
        $this->sessionId = $snapshot['session_id'] ?? $this->sessionId;
        $this->model = $snapshot['model'] ?? $this->model;
        $this->systemPrompt = $snapshot['system_prompt'] ?? $this->systemPrompt;
        $this->cwd = $snapshot['cwd'] ?? $this->cwd;
        $this->totalCostUsd = (float) ($snapshot['total_cost_usd'] ?? 0.0);

        // Restore messages from serialized arrays
        $this->messages = [];
        foreach ($snapshot['messages'] ?? [] as $msgData) {
            $role = $msgData['role'] ?? 'user';
            if ($role === 'user') {
                $this->messages[] = new UserMessage($msgData['content'] ?? '');
            } elseif ($role === 'assistant') {
                $msg = new AssistantMessage();
                $msg->content = array_map(
                    fn($b) => new \SuperAgent\Messages\ContentBlock(
                        type: $b['type'] ?? 'text',
                        text: $b['text'] ?? null,
                        toolUseId: $b['id'] ?? $b['tool_use_id'] ?? null,
                        toolName: $b['name'] ?? null,
                        toolInput: $b['input'] ?? null,
                        content: $b['content'] ?? null,
                        isError: $b['is_error'] ?? null,
                    ),
                    $msgData['content'] ?? [],
                );
                $this->messages[] = $msg;
            }
        }

        $this->turnCount = (int) ($snapshot['message_count'] ?? count($this->messages)) / 2;
    }

    // ── Accessors ─────────────────────────────────────────────────

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    public function getTotalCostUsd(): float
    {
        return $this->totalCostUsd;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getRouter(): CommandRouter
    {
        return $this->router;
    }

    public function getEmitter(): StreamEventEmitter
    {
        return $this->emitter;
    }

    // ── Internal ──────────────────────────────────────────────────

    private function buildContext(): array
    {
        return [
            'turn_count' => $this->turnCount,
            'total_cost_usd' => $this->totalCostUsd,
            'model' => $this->model,
            'message_count' => count($this->messages),
            'messages' => &$this->messages,
            'messages_serialized' => array_map(fn(Message $m) => $m->toArray(), $this->messages),
            'auto_compactor' => $this->autoCompactor,
            'session_manager' => $this->sessionManager,
            'session_id' => $this->sessionId,
            'cwd' => $this->cwd,
            'tasks' => $this->getTasksSummary(),
        ];
    }

    private function getTasksSummary(): array
    {
        try {
            $mgr = \SuperAgent\Tasks\TaskManager::getInstance();
            return $mgr->listTasks()->map(fn($t) => $t->toArray())->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
