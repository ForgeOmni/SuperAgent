<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\AgentResult;
use SuperAgent\Coordinator\PhaseContextInjector;
use SuperAgent\Coordinator\PhaseResult;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Swarm\AgentSpawnConfig;

class PhaseContextInjectorTest extends TestCase
{
    // ─── Helpers ───

    /**
     * Create an AgentResult whose text() returns the given string.
     */
    private function makeAgentResult(string $text): AgentResult
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text($text)];

        return new AgentResult(message: $msg);
    }

    /**
     * Create a completed PhaseResult with the given agent outputs.
     *
     * @param array<string, string> $agents  agentName => text
     */
    private function makeCompletedPhase(string $phaseName, array $agents): PhaseResult
    {
        $phase = new PhaseResult($phaseName);
        $phase->markRunning();

        foreach ($agents as $name => $text) {
            $phase->addAgentResult($name, $this->makeAgentResult($text));
        }

        $phase->markCompleted();

        return $phase;
    }

    /**
     * Create a failed PhaseResult.
     */
    private function makeFailedPhase(string $phaseName, string $error, array $agents = []): PhaseResult
    {
        $phase = new PhaseResult($phaseName);
        $phase->markRunning();

        foreach ($agents as $name => $text) {
            $phase->addAgentResult($name, $this->makeAgentResult($text));
        }

        $phase->markFailed($error);

        return $phase;
    }

    /**
     * Build an AgentSpawnConfig with all fields populated for field-preservation tests.
     */
    private function makeFullConfig(): AgentSpawnConfig
    {
        return new AgentSpawnConfig(
            name: 'test-agent',
            prompt: 'Do the task',
            teamName: 'alpha-team',
            model: 'claude-opus-4-20250514',
            systemPrompt: 'You are a helpful assistant.',
            permissionMode: null,
            backend: null,
            isolation: null,
            runInBackground: true,
            allowedTools: ['Read', 'Write'],
            deniedTools: ['Bash'],
            workingDirectory: '/tmp/work',
            environment: ['FOO' => 'bar'],
            color: '#ff0000',
            planModeRequired: true,
            readOnly: true,
            forkContext: null,
            providerConfig: ['key' => 'value'],
        );
    }

    // ─── buildContext ───

    public function testBuildContextFromSinglePhase(): void
    {
        $injector = new PhaseContextInjector();

        $phase = $this->makeCompletedPhase('research', [
            'researcher' => 'Found 3 security vulnerabilities in the auth module.',
        ]);

        $context = $injector->buildContext(['research' => $phase]);

        $this->assertStringStartsWith('<prior-phase-results>', $context);
        $this->assertStringEndsWith('</prior-phase-results>', $context);
        $this->assertStringContainsString('Phase: research', $context);
        $this->assertStringContainsString('completed', $context);
        $this->assertStringContainsString('1 agent', $context);
        $this->assertStringContainsString('[researcher]', $context);
        $this->assertStringContainsString('security vulnerabilities', $context);
    }

    public function testBuildContextFromMultiplePhases(): void
    {
        $injector = new PhaseContextInjector();

        $research = $this->makeCompletedPhase('research', [
            'agent-a' => 'Research finding A.',
            'agent-b' => 'Research finding B.',
        ]);

        $analysis = $this->makeCompletedPhase('analysis', [
            'analyst' => 'Analysis complete: all findings confirmed.',
        ]);

        $context = $injector->buildContext([
            'research' => $research,
            'analysis' => $analysis,
        ]);

        $this->assertStringContainsString('Phase: research', $context);
        $this->assertStringContainsString('2 agents', $context);
        $this->assertStringContainsString('[agent-a]', $context);
        $this->assertStringContainsString('[agent-b]', $context);
        $this->assertStringContainsString('Phase: analysis', $context);
        $this->assertStringContainsString('1 agent)', $context);
        $this->assertStringContainsString('[analyst]', $context);
    }

    public function testBuildContextEmptyPriorResults(): void
    {
        $injector = new PhaseContextInjector();

        $context = $injector->buildContext([]);

        $this->assertSame('', $context);
    }

    public function testBuildContextTruncatesLongOutput(): void
    {
        // Set a very small per-phase token budget: 10 tokens = 40 chars.
        $injector = new PhaseContextInjector(maxSummaryTokens: 10, maxTotalTokens: 100000);

        $longText = str_repeat('A', 500);
        $phase = $this->makeCompletedPhase('verbose', [
            'talker' => $longText,
        ]);

        $context = $injector->buildContext(['verbose' => $phase]);

        // The per-phase budget is 40 chars, so the full 500 chars cannot appear.
        // The output should be truncated (contain "..." or "[...truncated]").
        $this->assertStringContainsString('Phase: verbose', $context);
        // The entire long text should NOT be present.
        $this->assertStringNotContainsString($longText, $context);
        // Should contain truncation indicator.
        $this->assertTrue(
            str_contains($context, '...') || str_contains($context, '[...truncated]'),
            'Expected truncation indicator in output'
        );
    }

    public function testBuildContextRespectsMaxTotalTokens(): void
    {
        // Total budget: 20 tokens = 80 chars. Each phase will try to use ~200 chars.
        $injector = new PhaseContextInjector(maxSummaryTokens: 100000, maxTotalTokens: 20);

        $phase1 = $this->makeCompletedPhase('phase-one', [
            'worker1' => str_repeat('X', 200),
        ]);
        $phase2 = $this->makeCompletedPhase('phase-two', [
            'worker2' => str_repeat('Y', 200),
        ]);

        $context = $injector->buildContext([
            'phase-one' => $phase1,
            'phase-two' => $phase2,
        ]);

        // Extract inner content between the tags.
        $inner = strip_tags($context);

        // Total chars should be constrained. Max total chars = 80.
        // The full combined output would be well over 400 chars.
        // The second phase should either be absent or heavily truncated.
        $this->assertLessThan(
            200,
            mb_strlen($inner),
            'Total context should be constrained by maxTotalTokens'
        );
    }

    public function testBuildContextIncludesFailedPhaseError(): void
    {
        $injector = new PhaseContextInjector();

        $failed = $this->makeFailedPhase('deploy', 'Connection refused to production server');

        $context = $injector->buildContext(['deploy' => $failed]);

        $this->assertStringContainsString('Phase: deploy', $context);
        $this->assertStringContainsString('failed', $context);
        $this->assertStringContainsString('Connection refused to production server', $context);
    }

    // ─── injectIntoConfig ───

    public function testInjectIntoConfigPreservesExistingSystemPrompt(): void
    {
        $injector = new PhaseContextInjector();
        $config = new AgentSpawnConfig(
            name: 'writer',
            prompt: 'Write code',
            systemPrompt: 'You are a senior engineer.',
        );

        $phase = $this->makeCompletedPhase('research', [
            'reader' => 'The codebase uses PHP 8.3.',
        ]);

        $newConfig = $injector->injectIntoConfig($config, ['research' => $phase]);

        // Original system prompt is preserved.
        $this->assertStringContainsString('You are a senior engineer.', $newConfig->systemPrompt);
        // Context is also present.
        $this->assertStringContainsString('<prior-phase-results>', $newConfig->systemPrompt);
        $this->assertStringContainsString('PHP 8.3', $newConfig->systemPrompt);
    }

    public function testInjectIntoConfigAppendsContext(): void
    {
        $injector = new PhaseContextInjector();
        $config = new AgentSpawnConfig(
            name: 'coder',
            prompt: 'Implement feature',
            systemPrompt: 'Base prompt.',
        );

        $phase = $this->makeCompletedPhase('planning', [
            'planner' => 'Plan: create 3 new files.',
        ]);

        $newConfig = $injector->injectIntoConfig($config, ['planning' => $phase]);

        // The original system prompt should come first.
        $promptParts = explode('<prior-phase-results>', $newConfig->systemPrompt);
        $this->assertCount(2, $promptParts, 'Expected exactly one prior-phase-results block');
        $this->assertStringContainsString('Base prompt.', $promptParts[0]);
    }

    public function testInjectIntoConfigNoChangeOnEmptyResults(): void
    {
        $injector = new PhaseContextInjector();
        $config = new AgentSpawnConfig(
            name: 'agent',
            prompt: 'Do stuff',
            systemPrompt: 'Original prompt.',
        );

        $newConfig = $injector->injectIntoConfig($config, []);

        // Should return the exact same object.
        $this->assertSame($config, $newConfig);
        $this->assertSame('Original prompt.', $newConfig->systemPrompt);
    }

    public function testInjectIntoConfigPreservesAllFields(): void
    {
        $injector = new PhaseContextInjector();
        $config = $this->makeFullConfig();

        $phase = $this->makeCompletedPhase('prep', [
            'helper' => 'Preparation done.',
        ]);

        $newConfig = $injector->injectIntoConfig($config, ['prep' => $phase]);

        // systemPrompt should be modified.
        $this->assertStringContainsString('<prior-phase-results>', $newConfig->systemPrompt);
        $this->assertStringContainsString('You are a helpful assistant.', $newConfig->systemPrompt);

        // All other fields should be unchanged.
        $this->assertSame('test-agent', $newConfig->name);
        $this->assertSame('Do the task', $newConfig->prompt);
        $this->assertSame('alpha-team', $newConfig->teamName);
        $this->assertSame('claude-opus-4-20250514', $newConfig->model);
        $this->assertNull($newConfig->permissionMode);
        $this->assertNull($newConfig->backend);
        $this->assertNull($newConfig->isolation);
        $this->assertTrue($newConfig->runInBackground);
        $this->assertSame(['Read', 'Write'], $newConfig->allowedTools);
        $this->assertSame(['Bash'], $newConfig->deniedTools);
        $this->assertSame('/tmp/work', $newConfig->workingDirectory);
        $this->assertSame(['FOO' => 'bar'], $newConfig->environment);
        $this->assertSame('#ff0000', $newConfig->color);
        $this->assertTrue($newConfig->planModeRequired);
        $this->assertTrue($newConfig->readOnly);
        $this->assertNull($newConfig->forkContext);
        $this->assertSame(['key' => 'value'], $newConfig->providerConfig);
    }

    // ─── Strategy ───

    public function testFullStrategyIncludesMoreText(): void
    {
        // Create a text longer than 500 chars so extractSummary would truncate in 'summary' mode.
        $longText = str_repeat('Word ', 150); // 750 chars

        $summaryInjector = new PhaseContextInjector(strategy: 'summary');
        $fullInjector = new PhaseContextInjector(strategy: 'full');

        $phaseSummary = $this->makeCompletedPhase('data', ['agent' => $longText]);
        $phaseFull = $this->makeCompletedPhase('data', ['agent' => $longText]);

        $summaryContext = $summaryInjector->buildContext(['data' => $phaseSummary]);
        $fullContext = $fullInjector->buildContext(['data' => $phaseFull]);

        // 'full' strategy should include more text than 'summary'.
        $this->assertGreaterThan(
            mb_strlen($summaryContext),
            mb_strlen($fullContext),
            'Full strategy should produce longer output than summary strategy'
        );

        // The full context should contain the original text (not truncated with ...).
        $this->assertStringContainsString(trim($longText), $fullContext);
    }

    // ─── Edge cases ───

    public function testPhaseWithNoAgentOutputShowsNoOutput(): void
    {
        $injector = new PhaseContextInjector();

        // Agent result with null message => text() returns ''.
        $emptyResult = new AgentResult(message: null);

        $phase = new PhaseResult('silent');
        $phase->markRunning();
        $phase->addAgentResult('quiet-agent', $emptyResult);
        $phase->markCompleted();

        $context = $injector->buildContext(['silent' => $phase]);

        $this->assertStringContainsString('Phase: silent', $context);
        $this->assertStringContainsString('(no output)', $context);
    }
}
