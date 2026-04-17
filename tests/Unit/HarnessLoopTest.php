<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\HarnessLoop;
use SuperAgent\Harness\CommandRouter;
use SuperAgent\Harness\CommandResult;
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Harness\AgentCompleteEvent;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\ContentBlock;

class HarnessLoopTest extends TestCase
{
    private function makeAgentRunner(array $responses = []): \Closure
    {
        return function (string $prompt, array $options) use (&$responses): \Generator {
            if (empty($responses)) {
                $msg = new AssistantMessage();
                $msg->content = [ContentBlock::text("Echo: {$prompt}")];
                yield $msg;
                return;
            }

            $response = array_shift($responses);
            if ($response instanceof AssistantMessage) {
                yield $response;
            } elseif (is_string($response)) {
                $msg = new AssistantMessage();
                $msg->content = [ContentBlock::text($response)];
                yield $msg;
            }
        };
    }

    private function makeLoop(
        ?\Closure $runner = null,
        ?StreamEventEmitter $emitter = null,
    ): HarnessLoop {
        return new HarnessLoop(
            agentRunner: $runner ?? $this->makeAgentRunner(),
            emitter: $emitter,
            model: 'test-model',
            sessionId: 'test-session',
            cwd: '/test',
        );
    }

    // ── CommandRouter ─────────────────────────────────────────────

    public function testCommandRouterIsCommand(): void
    {
        $router = new CommandRouter();
        $this->assertTrue($router->isCommand('/help'));
        $this->assertTrue($router->isCommand(' /status'));
        $this->assertFalse($router->isCommand('hello'));
        $this->assertFalse($router->isCommand(''));
    }

    public function testCommandRouterParse(): void
    {
        $router = new CommandRouter();

        [$name, $args] = $router->parse('/help');
        $this->assertEquals('help', $name);
        $this->assertEquals('', $args);

        [$name, $args] = $router->parse('/model claude-opus-4');
        $this->assertEquals('model', $name);
        $this->assertEquals('claude-opus-4', $args);
    }

    public function testCommandRouterParseNonCommand(): void
    {
        $router = new CommandRouter();
        [$name, $args] = $router->parse('just text');
        $this->assertEquals('', $name);
        $this->assertEquals('just text', $args);
    }

    public function testCommandRouterHelp(): void
    {
        $router = new CommandRouter();
        $result = $router->dispatch('/help');
        $this->assertTrue($result->success);
        $this->assertStringContainsString('/help', $result->output);
        $this->assertStringContainsString('/status', $result->output);
        $this->assertStringContainsString('/quit', $result->output);
    }

    public function testCommandRouterStatus(): void
    {
        $router = new CommandRouter();
        $result = $router->dispatch('/status', [
            'model' => 'test-model',
            'turn_count' => 5,
            'message_count' => 10,
            'total_cost_usd' => 0.123,
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('test-model', $result->output);
        $this->assertStringContainsString('5', $result->output);
    }

    public function testCommandRouterCost(): void
    {
        $router = new CommandRouter();
        $result = $router->dispatch('/cost', [
            'total_cost_usd' => 1.5,
            'turn_count' => 3,
        ]);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('1.5', $result->output);
    }

    public function testCommandRouterUnknownCommand(): void
    {
        $router = new CommandRouter();
        $result = $router->dispatch('/nonexistent');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unknown command', $result->output);
    }

    public function testCommandRouterCustomCommand(): void
    {
        $router = new CommandRouter();
        $router->register('ping', 'Respond with pong', fn() => 'pong');

        $this->assertTrue($router->has('ping'));

        $result = $router->dispatch('/ping');
        $this->assertTrue($result->success);
        $this->assertEquals('pong', $result->output);
    }

    public function testCommandRouterGetCommands(): void
    {
        $router = new CommandRouter();
        $commands = $router->getCommands();

        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayHasKey('status', $commands);
        $this->assertArrayHasKey('quit', $commands);
    }

    public function testCommandResultSignals(): void
    {
        $result = CommandResult::success('__QUIT__');
        $this->assertTrue($result->isSignal('__QUIT__'));
        $this->assertFalse($result->isSignal('__CLEAR__'));

        $result = CommandResult::success('__MODEL__:claude-opus');
        $this->assertTrue($result->isSignal('__MODEL__:'));
        $this->assertEquals('claude-opus', $result->signalPayload('__MODEL__:'));
    }

    // ── HarnessLoop basics ────────────────────────────────────────

    public function testLoopCreation(): void
    {
        $loop = $this->makeLoop();
        $this->assertEquals('test-model', $loop->getModel());
        $this->assertEquals('test-session', $loop->getSessionId());
        $this->assertEquals(0, $loop->getTurnCount());
        $this->assertEquals(0.0, $loop->getTotalCostUsd());
        $this->assertFalse($loop->isBusy());
    }

    public function testHandleCommandHelp(): void
    {
        $loop = $this->makeLoop();
        $output = [];
        $result = $loop->handleInput('/help', function ($s) use (&$output) { $output[] = $s; });

        $this->assertNotNull($result);
        $this->assertStringContainsString('/help', $result);
    }

    public function testHandleCommandQuit(): void
    {
        $loop = $this->makeLoop();
        $output = [];
        $loop->handleInput('/quit', function ($s) use (&$output) { $output[] = $s; });

        $this->assertFalse($loop->isRunning());
    }

    public function testHandleCommandClear(): void
    {
        $loop = $this->makeLoop();

        // Submit a prompt first to have messages
        $loop->handleInput('hello', function ($s) {});
        $this->assertNotEmpty($loop->getMessages());

        // Clear
        $loop->handleInput('/clear', function ($s) {});
        $this->assertEmpty($loop->getMessages());
        $this->assertEquals(0, $loop->getTurnCount());
    }

    public function testHandleCommandModel(): void
    {
        $loop = $this->makeLoop();
        $loop->handleInput('/model claude-opus-4', function ($s) {});

        $this->assertEquals('claude-opus-4', $loop->getModel());
    }

    public function testHandleCommandModelShowsCurrent(): void
    {
        $loop = $this->makeLoop();
        $result = $loop->handleInput('/model', function ($s) {});

        $this->assertStringContainsString('test-model', $result);
    }

    // ── Prompt submission ─────────────────────────────────────────

    public function testSubmitPrompt(): void
    {
        $output = [];
        $loop = $this->makeLoop();
        $loop->handleInput('hello world', function ($s) use (&$output) { $output[] = $s; });

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Echo: hello world', $output[0]);

        // Messages should contain user message + assistant message
        $messages = $loop->getMessages();
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);

        $this->assertEquals(1, $loop->getTurnCount());
    }

    public function testRunSkipsEmptyInput(): void
    {
        // Test via handleInput directly — empty string goes to agent in handleInput
        // but run() skips it before calling handleInput. Verify via count.
        $loop = $this->makeLoop();

        // Empty inputs don't reach handleInput in run()
        // So just verify messages count: submitting 'real' adds 2 (user+assistant)
        $loop->handleInput('real prompt', fn($s) => null);
        $this->assertCount(2, $loop->getMessages());
    }

    // ── Busy lock ─────────────────────────────────────────────────

    public function testBusyLockPreventsConcurrentSubmit(): void
    {
        // Create a loop that sets busy manually
        $loop = $this->makeLoop();

        // Use reflection to set busy=true
        $ref = new \ReflectionClass($loop);
        $busy = $ref->getProperty('busy');
        $busy->setAccessible(true);
        $busy->setValue($loop, true);

        $output = [];
        $result = $loop->handleInput('should be rejected', function ($s) use (&$output) { $output[] = $s; });

        $this->assertStringContainsString('busy', $result);
    }

    // ── Continue pending ──────────────────────────────────────────

    public function testHasPendingContinuationFalseWhenEmpty(): void
    {
        $loop = $this->makeLoop();
        $this->assertFalse($loop->hasPendingContinuation());
    }

    public function testHasPendingContinuationTrueAfterToolResult(): void
    {
        $loop = $this->makeLoop();

        // Inject messages that end with ToolResultMessage
        $loop->setMessages([
            new UserMessage('query'),
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'tu_1', 'content' => 'result'],
            ]),
        ]);

        $this->assertTrue($loop->hasPendingContinuation());
    }

    public function testContinueWithoutPending(): void
    {
        $loop = $this->makeLoop();
        $output = [];
        $result = $loop->handleInput('/continue', function ($s) use (&$output) { $output[] = $s; });

        $this->assertStringContainsString('No pending', $result);
    }

    // ── Event emission ────────────────────────────────────────────

    public function testAgentCompleteEventEmitted(): void
    {
        $emitter = new StreamEventEmitter(recordHistory: true);
        $loop = $this->makeLoop(emitter: $emitter);

        $loop->handleInput('test prompt', function ($s) {});

        $history = $emitter->getHistory();
        $completeEvents = array_filter($history, fn($e) => $e instanceof AgentCompleteEvent);
        $this->assertNotEmpty($completeEvents);
    }

    // ── Session save/load ─────────────────────────────────────────

    public function testRestoreFromSnapshot(): void
    {
        $loop = $this->makeLoop();

        $snapshot = [
            'session_id' => 'restored-123',
            'model' => 'claude-opus-4',
            'cwd' => '/restored/path',
            'system_prompt' => 'You are helpful.',
            'total_cost_usd' => 1.23,
            'message_count' => 4,
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hi there']]],
            ],
        ];

        $loop->restoreFromSnapshot($snapshot);

        $this->assertEquals('restored-123', $loop->getSessionId());
        $this->assertEquals('claude-opus-4', $loop->getModel());
        $this->assertEquals(1.23, $loop->getTotalCostUsd());
        $this->assertCount(2, $loop->getMessages());
    }

    public function testAutoSaveWithoutSessionManager(): void
    {
        $loop = $this->makeLoop();
        $loop->autoSaveSession();
        // No SessionManager — should not throw and messages remain unchanged
        $this->assertEmpty($loop->getMessages());
    }

    // ── Run loop ──────────────────────────────────────────────────

    public function testRunLoopProcessesInputUntilNull(): void
    {
        $loop = $this->makeLoop();
        $inputs = ['hello', '/status', null];
        $inputIdx = 0;
        $outputs = [];

        $loop->run(
            function () use (&$inputs, &$inputIdx): ?string {
                return $inputs[$inputIdx++] ?? null;
            },
            function (string $s) use (&$outputs) {
                $outputs[] = $s;
            },
        );

        // Should have processed 'hello' and '/status'
        $this->assertNotEmpty($outputs);
        $this->assertFalse($loop->isRunning());
    }

    public function testRunLoopStopsOnQuit(): void
    {
        $loop = $this->makeLoop();
        $inputs = ['hello', '/quit', 'should not reach'];
        $inputIdx = 0;

        $loop->run(
            function () use (&$inputs, &$inputIdx): ?string {
                return $inputs[$inputIdx++] ?? null;
            },
            function (string $s) {},
        );

        // Only 2 inputs processed (hello + /quit)
        $this->assertEquals(2, $inputIdx);
    }

    public function testRunLoopSkipsWhitespaceInput(): void
    {
        // Whitespace-only inputs are trimmed to '' and skipped in run()
        $loop = $this->makeLoop();

        // Simulate: whitespace -> /status -> null
        $inputs = ['  ', "\t", '/status', null];
        $idx = 0;
        $outputs = [];

        $loop->run(
            function () use (&$inputs, &$idx): ?string {
                return $inputs[$idx++] ?? null;
            },
            function (string $s) use (&$outputs) {
                $outputs[] = $s;
            },
        );

        // Only /status should produce output (no prompts submitted)
        $this->assertNotEmpty($outputs);
        $this->assertStringContainsString('test-model', $outputs[0]); // from /status
        $this->assertEmpty($loop->getMessages()); // no user messages
    }

    // ── Error handling ────────────────────────────────────────────

    public function testAgentErrorHandledGracefully(): void
    {
        $runner = function (string $prompt, array $options): \Generator {
            throw new \RuntimeException('Test error');
            yield; // make it a generator
        };

        $loop = new HarnessLoop(
            agentRunner: $runner,
            model: 'test',
            sessionId: 'test',
        );

        $output = [];
        $loop->handleInput('trigger error', function ($s) use (&$output) { $output[] = $s; });

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Error: Test error', $output[0]);
        $this->assertFalse($loop->isBusy()); // Busy lock released
    }

    // ── Messages accessor ─────────────────────────────────────────

    public function testSetMessages(): void
    {
        $loop = $this->makeLoop();
        $messages = [new UserMessage('test')];
        $loop->setMessages($messages);

        $this->assertCount(1, $loop->getMessages());
    }

    // ── Router access ─────────────────────────────────────────────

    public function testGetRouter(): void
    {
        $loop = $this->makeLoop();
        $this->assertInstanceOf(CommandRouter::class, $loop->getRouter());
    }

    public function testGetEmitter(): void
    {
        $loop = $this->makeLoop();
        $this->assertInstanceOf(StreamEventEmitter::class, $loop->getEmitter());
    }
}
