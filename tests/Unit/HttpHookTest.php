<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Hooks\HttpHook;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookInput;
use SuperAgent\Hooks\HookResult;
use SuperAgent\Hooks\HookType;

class HttpHookTest extends TestCase
{
    private function makeInput(
        HookEvent $event = HookEvent::PRE_TOOL_USE,
        string $sessionId = 'sess-123',
        string $cwd = '/project',
        array $data = [],
    ): HookInput {
        return new HookInput(
            hookEvent: $event,
            sessionId: $sessionId,
            cwd: $cwd,
            gitRepoRoot: '/project',
            additionalData: $data,
        );
    }

    public function testGetTypeReturnsHttp(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook');

        $this->assertEquals(HookType::HTTP, $hook->getType());
    }

    public function testIsAsyncReturnsFalse(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook');

        $this->assertFalse($hook->isAsync());
    }

    public function testIsOnceReturnsFalseByDefault(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook');

        $this->assertFalse($hook->isOnce());
    }

    public function testIsOnceReturnsTrueWhenConfigured(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook', once: true);

        $this->assertTrue($hook->isOnce());
    }

    public function testMatchesWithNoMatcherReturnsTrue(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook');

        $this->assertTrue($hook->matches('Bash', []));
        $this->assertTrue($hook->matches('Read', []));
        $this->assertTrue($hook->matches(null, []));
    }

    public function testMatchesWithMatcherAndMatchingTool(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook', matcher: 'Bash');

        $this->assertTrue($hook->matches('Bash', []));
    }

    public function testMatchesWithMatcherAndNonMatchingTool(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook', matcher: 'Bash');

        $this->assertFalse($hook->matches('Read', []));
    }

    public function testMatchesWithWildcardMatcher(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook', matcher: 'Bash*');

        $this->assertTrue($hook->matches('Bash', []));
        $this->assertTrue($hook->matches('BashExtended', []));
        $this->assertFalse($hook->matches('Read', []));
    }

    public function testMatchesReturnsNullToolWithMatcher(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook', matcher: 'Bash');

        $this->assertFalse($hook->matches(null, []));
    }

    public function testGetConditionReturnsConditionOrMatcher(): void
    {
        $hookWithCondition = new HttpHook(
            url: 'https://example.com/hook',
            condition: 'some_condition',
        );
        $this->assertEquals('some_condition', $hookWithCondition->getCondition());

        $hookWithMatcher = new HttpHook(
            url: 'https://example.com/hook',
            matcher: 'Bash*',
        );
        $this->assertEquals('Bash*', $hookWithMatcher->getCondition());

        $hookWithBoth = new HttpHook(
            url: 'https://example.com/hook',
            condition: 'cond',
            matcher: 'match',
        );
        $this->assertEquals('cond', $hookWithBoth->getCondition());
    }

    public function testDefaultMethodIsPost(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook');

        // We verify the default via reflection since method is private
        $reflection = new \ReflectionClass($hook);
        $prop = $reflection->getProperty('method');
        $prop->setAccessible(true);

        $this->assertEquals('POST', $prop->getValue($hook));
    }

    public function testBlockOnFailureDefaultsFalse(): void
    {
        $hook = new HttpHook(url: 'https://example.com/hook');

        $reflection = new \ReflectionClass($hook);
        $prop = $reflection->getProperty('blockOnFailure');
        $prop->setAccessible(true);

        $this->assertFalse($prop->getValue($hook));
    }

    public function testOnceSkipsSecondExecution(): void
    {
        // We can test the once guard by using a hook that would fail on real HTTP
        // but setting once=true and simulating that hasExecuted is true
        $hook = new HttpHook(url: 'https://example.com/hook', once: true);

        $reflection = new \ReflectionClass($hook);
        $prop = $reflection->getProperty('hasExecuted');
        $prop->setAccessible(true);
        $prop->setValue($hook, true);

        $input = $this->makeInput();
        $result = $hook->execute($input);

        $this->assertTrue($result->continue);
        $this->assertStringContainsString('already executed', $result->systemMessage);
    }

    public function testExecuteHandlesConnectionErrorWithoutBlock(): void
    {
        // This will fail to connect to a non-existent server
        $hook = new HttpHook(
            url: 'http://192.0.2.1:1/hook', // RFC 5737 TEST-NET, should fail
            timeout: 1,
            blockOnFailure: false,
        );

        $input = $this->makeInput();
        $result = $hook->execute($input);

        // Without blockOnFailure, it should continue
        $this->assertTrue($result->continue);
    }

    public function testExecuteHandlesConnectionErrorWithBlock(): void
    {
        $hook = new HttpHook(
            url: 'http://192.0.2.1:1/hook',
            timeout: 1,
            blockOnFailure: true,
        );

        $input = $this->makeInput();
        $result = $hook->execute($input);

        // With blockOnFailure, it should stop
        $this->assertFalse($result->continue);
    }
}
