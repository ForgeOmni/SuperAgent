<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Hooks\AsyncHookManager;
use SuperAgent\Hooks\CallbackHook;
use SuperAgent\Hooks\CommandHook;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookMatcher;
use SuperAgent\Hooks\HookRegistry;
use SuperAgent\Hooks\HookResult;
use SuperAgent\Hooks\HookType;
use SuperAgent\Hooks\HttpHook;

class Phase4HooksTest extends TestCase
{
    public function testHookEvents(): void
    {
        $this->assertEquals('PreToolUse', HookEvent::PRE_TOOL_USE->value);
        $this->assertEquals('PostToolUse', HookEvent::POST_TOOL_USE->value);
        $this->assertEquals('SessionStart', HookEvent::SESSION_START->value);
        
        $description = HookEvent::PRE_TOOL_USE->getDescription();
        $this->assertStringContainsString('before a tool is executed', $description);
    }
    
    public function testHookInput(): void
    {
        $input = HookInput::preToolUse(
            sessionId: 'session-123',
            cwd: '/project',
            toolName: 'Bash',
            toolInput: ['command' => 'ls -la'],
            toolUseId: 'tool-456',
            gitRepoRoot: '/project/.git',
        );
        
        $this->assertEquals(HookEvent::PRE_TOOL_USE, $input->hookEvent);
        $this->assertEquals('session-123', $input->sessionId);
        $this->assertEquals('/project', $input->cwd);
        $this->assertEquals('Bash', $input->additionalData['tool_name']);
        $this->assertEquals(['command' => 'ls -la'], $input->additionalData['tool_input']);
        
        $array = $input->toArray();
        $this->assertEquals('PreToolUse', $array['hook_event_name']);
        $this->assertEquals('Bash', $array['tool_name']);
    }
    
    public function testHookResult(): void
    {
        $result = HookResult::continue('Hook executed successfully');
        $this->assertTrue($result->continue);
        $this->assertEquals('Hook executed successfully', $result->systemMessage);
        
        $stopResult = HookResult::stop('Security violation detected');
        $this->assertFalse($stopResult->continue);
        $this->assertEquals('Security violation detected', $stopResult->stopReason);
        
        $errorResult = HookResult::error('Connection failed');
        $this->assertFalse($errorResult->continue);
        $this->assertEquals('Connection failed', $errorResult->errorMessage);
    }
    
    public function testHookResultMerge(): void
    {
        $results = [
            HookResult::continue('First hook'),
            HookResult::continue('Second hook'),
            HookResult::stop('Third hook stopped'),
            HookResult::continue('Fourth hook'),
        ];
        
        $merged = HookResult::merge($results);
        
        $this->assertFalse($merged->continue);
        $this->assertEquals('Third hook stopped', $merged->stopReason);
        $this->assertStringContainsString('First hook', $merged->systemMessage);
        $this->assertStringContainsString('Second hook', $merged->systemMessage);
    }
    
    public function testCommandHook(): void
    {
        $hook = new CommandHook(
            command: 'echo "Hello from hook"',
            shell: 'bash',
            timeout: 5,
            async: false,
            once: false,
        );
        
        $this->assertEquals(HookType::COMMAND, $hook->getType());
        $this->assertFalse($hook->isAsync());
        $this->assertFalse($hook->isOnce());
        
        $input = HookInput::preToolUse(
            sessionId: 'test-session',
            cwd: '/tmp',
            toolName: 'Bash',
            toolInput: ['command' => 'ls'],
            toolUseId: 'test-tool',
        );
        
        $result = $hook->execute($input);
        
        $this->assertTrue($result->continue);
        $this->assertEquals('Hello from hook', trim($result->systemMessage ?? ''));
    }
    
    public function testCommandHookWithCondition(): void
    {
        $hook = new CommandHook(
            command: 'echo "Git command detected"',
            condition: 'Bash(git*)',
        );
        
        // Test with matching condition
        $gitInput = HookInput::preToolUse(
            sessionId: 'test',
            cwd: '/tmp',
            toolName: 'Bash',
            toolInput: ['command' => 'git status'],
            toolUseId: 'test',
        );
        
        $result = $hook->execute($gitInput);
        $this->assertStringContainsString('Git command detected', $result->systemMessage ?? '');
        
        // Test with non-matching condition
        $lsInput = HookInput::preToolUse(
            sessionId: 'test',
            cwd: '/tmp',
            toolName: 'Bash',
            toolInput: ['command' => 'ls -la'],
            toolUseId: 'test',
        );
        
        $result = $hook->execute($lsInput);
        $this->assertEquals('Hook condition not met', $result->systemMessage);
    }
    
    public function testCallbackHook(): void
    {
        $callbackExecuted = false;
        $capturedInput = null;
        
        $hook = new CallbackHook(
            callback: function (HookInput $input) use (&$callbackExecuted, &$capturedInput) {
                $callbackExecuted = true;
                $capturedInput = $input;
                
                return HookResult::continue('Callback executed');
            },
        );
        
        $this->assertEquals(HookType::CALLBACK, $hook->getType());
        
        $input = HookInput::sessionStart(
            sessionId: 'test',
            cwd: '/project',
            source: 'cli',
            agentType: 'general',
            model: 'gpt-4',
        );
        
        $result = $hook->execute($input);
        
        $this->assertTrue($callbackExecuted);
        $this->assertEquals($input, $capturedInput);
        $this->assertTrue($result->continue);
        $this->assertEquals('Callback executed', $result->systemMessage);
    }
    
    public function testCallbackHookOnce(): void
    {
        $executionCount = 0;
        
        $hook = new CallbackHook(
            callback: function () use (&$executionCount) {
                $executionCount++;
                return HookResult::continue('Executed');
            },
            once: true,
        );
        
        $input = HookInput::sessionStart(
            sessionId: 'test',
            cwd: '/project',
            source: 'cli',
            agentType: 'general',
            model: 'gpt-4',
        );
        
        $result1 = $hook->execute($input);
        $this->assertEquals('Executed', $result1->systemMessage);
        
        $result2 = $hook->execute($input);
        $this->assertEquals('Hook already executed (once=true)', $result2->systemMessage);
        
        $this->assertEquals(1, $executionCount);
    }
    
    public function testHookMatcher(): void
    {
        $matcher = new HookMatcher('Bash(git*)');
        
        $this->assertTrue($matcher->matches('Bash', ['command' => 'git status']));
        $this->assertTrue($matcher->matches('Bash', ['command' => 'git commit']));
        $this->assertFalse($matcher->matches('Bash', ['command' => 'ls -la']));
        $this->assertFalse($matcher->matches('Read', ['file_path' => 'test.txt']));
        
        $noMatcher = new HookMatcher();
        $this->assertTrue($noMatcher->matches('Bash', ['command' => 'any']));
        $this->assertTrue($noMatcher->matches('Read', []));
    }
    
    public function testHookMatcherFromConfig(): void
    {
        $config = [
            'matcher' => 'Bash(npm*)',
            'hooks' => [
                [
                    'type' => 'command',
                    'command' => 'echo "NPM command"',
                    'timeout' => 10,
                    'once' => true,
                ],
            ],
        ];
        
        $matcher = HookMatcher::fromConfig($config, 'test-plugin');
        
        $this->assertEquals('Bash(npm*)', $matcher->matcher);
        $this->assertEquals('test-plugin', $matcher->pluginName);
        $this->assertCount(1, $matcher->getHooks());
        
        $hook = $matcher->getHooks()[0];
        $this->assertEquals(HookType::COMMAND, $hook->getType());
        $this->assertTrue($hook->isOnce());
    }
    
    public function testHookRegistry(): void
    {
        $registry = new HookRegistry();
        
        $executedHooks = [];
        
        $hook1 = new CallbackHook(function () use (&$executedHooks) {
            $executedHooks[] = 'hook1';
            return HookResult::continue('Hook 1');
        });
        
        $hook2 = new CallbackHook(function () use (&$executedHooks) {
            $executedHooks[] = 'hook2';
            return HookResult::continue('Hook 2');
        });
        
        $matcher1 = new HookMatcher(null, [$hook1]);
        $matcher2 = new HookMatcher('Bash', [$hook2]);
        
        $registry->register(HookEvent::PRE_TOOL_USE, $matcher1);
        $registry->register(HookEvent::PRE_TOOL_USE, $matcher2);
        
        $input = HookInput::preToolUse(
            sessionId: 'test',
            cwd: '/project',
            toolName: 'Bash',
            toolInput: ['command' => 'ls'],
            toolUseId: 'test',
        );
        
        $result = $registry->executeHooks(HookEvent::PRE_TOOL_USE, $input);
        
        $this->assertTrue($result->continue);
        $this->assertContains('hook1', $executedHooks);
        $this->assertContains('hook2', $executedHooks);
        $this->assertStringContainsString('Hook 1', $result->systemMessage);
        $this->assertStringContainsString('Hook 2', $result->systemMessage);
    }
    
    public function testHookRegistryWithStop(): void
    {
        $registry = new HookRegistry();
        
        $executedHooks = [];
        
        $hook1 = new CallbackHook(function () use (&$executedHooks) {
            $executedHooks[] = 'hook1';
            return HookResult::continue('Hook 1');
        });
        
        $hook2 = new CallbackHook(function () use (&$executedHooks) {
            $executedHooks[] = 'hook2';
            return HookResult::stop('Stopped by hook 2');
        });
        
        $hook3 = new CallbackHook(function () use (&$executedHooks) {
            $executedHooks[] = 'hook3';
            return HookResult::continue('Hook 3');
        });
        
        $matcher = new HookMatcher(null, [$hook1, $hook2, $hook3]);
        $registry->register(HookEvent::ON_STOP, $matcher);
        
        $input = new HookInput(
            hookEvent: HookEvent::ON_STOP,
            sessionId: 'test',
            cwd: '/project',
        );
        
        $result = $registry->executeHooks(HookEvent::ON_STOP, $input);
        
        $this->assertFalse($result->continue);
        $this->assertEquals('Stopped by hook 2', $result->stopReason);
        $this->assertContains('hook1', $executedHooks);
        $this->assertContains('hook2', $executedHooks);
        $this->assertNotContains('hook3', $executedHooks); // Should not execute after stop
    }
    
    public function testHookRegistryLoadFromConfig(): void
    {
        $registry = new HookRegistry();
        
        $config = [
            'PreToolUse' => [
                [
                    'matcher' => 'Bash',
                    'hooks' => [
                        [
                            'type' => 'command',
                            'command' => 'echo "Bash hook"',
                        ],
                    ],
                ],
            ],
            'PostToolUse' => [
                [
                    'hooks' => [
                        [
                            'type' => 'command',
                            'command' => 'echo "Post tool"',
                        ],
                    ],
                ],
            ],
        ];
        
        $registry->loadFromConfig($config, 'test-plugin');
        
        $stats = $registry->getStatistics();
        
        $this->assertArrayHasKey('PreToolUse', $stats['events']);
        $this->assertArrayHasKey('PostToolUse', $stats['events']);
        $this->assertEquals(1, $stats['events']['PreToolUse']['hook_count']);
        $this->assertEquals(1, $stats['events']['PostToolUse']['hook_count']);
    }
    
    public function testAsyncHookManager(): void
    {
        $manager = new AsyncHookManager();
        
        $this->assertEquals(0, $manager->getRunningCount());
        $this->assertEmpty($manager->getRunningHookIds());
        
        // Note: Testing actual async hooks would require more complex setup
        // This test verifies the basic structure works
    }
}