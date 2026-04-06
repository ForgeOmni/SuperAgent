<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\Scenario;
use SuperAgent\Harness\ScenarioResult;
use SuperAgent\Harness\ScenarioRunner;
use SuperAgent\AgentResult;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

class ScenarioTest extends TestCase
{
    // ── Scenario data class ───────────────────────────────────────

    public function testScenarioCreation(): void
    {
        $scenario = new Scenario(
            name: 'test_basic',
            prompt: 'Hello world',
        );

        $this->assertEquals('test_basic', $scenario->name);
        $this->assertEquals('Hello world', $scenario->prompt);
        $this->assertEmpty($scenario->requiredTools);
        $this->assertNull($scenario->expectedText);
        $this->assertNull($scenario->setup);
        $this->assertNull($scenario->validate);
        $this->assertEquals(20, $scenario->maxTurns);
        $this->assertEmpty($scenario->tags);
    }

    public function testScenarioFluentBuilder(): void
    {
        $scenario = Scenario::create('file_io', 'Write a test file')
            ->withRequiredTools(['write', 'read'])
            ->withExpectedText('success')
            ->withMaxTurns(10)
            ->withTags(['core', 'io']);

        $this->assertEquals('file_io', $scenario->name);
        $this->assertEquals(['write', 'read'], $scenario->requiredTools);
        $this->assertEquals('success', $scenario->expectedText);
        $this->assertEquals(10, $scenario->maxTurns);
        $this->assertEquals(['core', 'io'], $scenario->tags);
    }

    public function testScenarioWithSetup(): void
    {
        $setupCalled = false;
        $scenario = Scenario::create('setup_test', 'test')
            ->withSetup(function (string $workspace) use (&$setupCalled) {
                $setupCalled = true;
                $this->assertIsString($workspace);
            });

        // Setup is stored as closure
        $this->assertNotNull($scenario->setup);
        ($scenario->setup)('/tmp/test');
        $this->assertTrue($setupCalled);
    }

    public function testScenarioWithValidation(): void
    {
        $scenario = Scenario::create('validation_test', 'test')
            ->withValidation(function (string $workspace, $result, array $toolsUsed) {
                return true; // pass
            });

        $this->assertNotNull($scenario->validate);
    }

    public function testScenarioWithTools(): void
    {
        $tool = new ClosureTool(
            toolName: 'test_tool',
            toolDescription: 'A test tool',
            toolInputSchema: ['type' => 'object', 'properties' => []],
            handler: fn($input) => ToolResult::success('ok'),
        );

        $scenario = Scenario::create('tool_test', 'Use the tool')
            ->withTools([$tool]);

        $this->assertCount(1, $scenario->tools);
    }

    public function testScenarioHasTag(): void
    {
        $scenario = Scenario::create('tag_test', 'test')
            ->withTags(['core', 'smoke']);

        $this->assertTrue($scenario->hasTag('core'));
        $this->assertTrue($scenario->hasTag('smoke'));
        $this->assertFalse($scenario->hasTag('integration'));
    }

    public function testScenarioImmutability(): void
    {
        $original = Scenario::create('immutable', 'test');
        $modified = $original->withMaxTurns(5);

        // Original unchanged
        $this->assertEquals(20, $original->maxTurns);
        $this->assertEquals(5, $modified->maxTurns);
    }

    // ── ScenarioResult ────────────────────────────────────────────

    public function testScenarioResultPass(): void
    {
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('Hello')];
        $agentResult = new AgentResult(message: $msg, allResponses: [$msg]);

        $result = ScenarioResult::pass('test', $agentResult, ['read' => 1], 42.5);

        $this->assertTrue($result->passed);
        $this->assertEquals('test', $result->scenarioName);
        $this->assertEmpty($result->failures);
        $this->assertEquals(['read' => 1], $result->toolsUsed);
        $this->assertEquals(42.5, $result->durationMs);
        $this->assertNull($result->error);
    }

    public function testScenarioResultFail(): void
    {
        $result = ScenarioResult::fail('test', ['Tool X not called']);

        $this->assertFalse($result->passed);
        $this->assertEquals(['Tool X not called'], $result->failures);
    }

    public function testScenarioResultError(): void
    {
        $result = ScenarioResult::error('test', 'Connection timeout', 100.0);

        $this->assertFalse($result->passed);
        $this->assertEquals('Connection timeout', $result->error);
        $this->assertEquals(100.0, $result->durationMs);
    }

    public function testScenarioResultToArray(): void
    {
        $result = ScenarioResult::fail('scenario_1', ['Missing tool']);
        $arr = $result->toArray();

        $this->assertEquals('scenario_1', $arr['scenario']);
        $this->assertFalse($arr['passed']);
        $this->assertEquals(['Missing tool'], $arr['failures']);
        $this->assertArrayHasKey('duration_ms', $arr);
        $this->assertArrayHasKey('turns', $arr);
    }

    // ── ScenarioRunner ────────────────────────────────────────────

    public function testRunnerCreation(): void
    {
        $runner = new ScenarioRunner();
        $this->assertEmpty($runner->getResults());
    }

    public function testRunnerSummaryEmpty(): void
    {
        $runner = new ScenarioRunner();
        $summary = $runner->summary();

        $this->assertEquals(0, $summary['total']);
        $this->assertEquals(0, $summary['passed']);
        $this->assertEquals(0, $summary['failed']);
    }

    public function testRunnerClearResults(): void
    {
        $runner = new ScenarioRunner();
        // Can't easily run a scenario without API key, but we can test error paths
        $scenario = Scenario::create('will_error', 'test');

        // This will error because no API key — but still records a result
        $runner->run($scenario);
        $this->assertCount(1, $runner->getResults());

        $runner->clearResults();
        $this->assertEmpty($runner->getResults());
    }

    public function testRunnerErrorScenario(): void
    {
        $runner = new ScenarioRunner(); // No API key
        $scenario = Scenario::create('no_api', 'Hello')
            ->withTags(['error']);

        $result = $runner->run($scenario);

        $this->assertFalse($result->passed);
        $this->assertNotNull($result->error);
        $this->assertGreaterThan(0, $result->durationMs);
    }

    public function testRunnerSummaryAfterRuns(): void
    {
        $runner = new ScenarioRunner();

        // Run two scenarios that will both error (no API key)
        $runner->run(Scenario::create('s1', 'Hello'));
        $runner->run(Scenario::create('s2', 'World'));

        $summary = $runner->summary();
        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(2, $summary['errors']);
        $this->assertGreaterThan(0, $summary['total_duration_ms']);
        $this->assertCount(2, $summary['results']);
    }

    public function testRunnerRunAllWithTagFilter(): void
    {
        $runner = new ScenarioRunner();
        $scenarios = [
            Scenario::create('s1', 'test')->withTags(['core']),
            Scenario::create('s2', 'test')->withTags(['extended']),
            Scenario::create('s3', 'test')->withTags(['core']),
        ];

        $results = $runner->runAll($scenarios, ['tag' => 'core']);
        $this->assertCount(2, $results);
    }

    public function testRunnerSetupCalled(): void
    {
        $setupCalled = false;
        $setupWorkspace = null;

        $runner = new ScenarioRunner();
        $scenario = Scenario::create('setup', 'test')
            ->withSetup(function (string $workspace) use (&$setupCalled, &$setupWorkspace) {
                $setupCalled = true;
                $setupWorkspace = $workspace;
                // Create a fixture file
                file_put_contents($workspace . '/fixture.txt', 'hello');
            });

        // Will error at agent creation (no API key), but setup should run
        $runner->run($scenario);

        $this->assertTrue($setupCalled);
        $this->assertNotNull($setupWorkspace);
        // Workspace should be cleaned up
        $this->assertDirectoryDoesNotExist($setupWorkspace);
    }

    public function testRunnerWorkspaceCleanedUp(): void
    {
        $capturedWorkspace = null;

        $runner = new ScenarioRunner();
        $scenario = Scenario::create('cleanup', 'test')
            ->withSetup(function (string $w) use (&$capturedWorkspace) {
                $capturedWorkspace = $w;
            });

        $runner->run($scenario);

        // Workspace should be cleaned up after run
        $this->assertNotNull($capturedWorkspace);
        $this->assertDirectoryDoesNotExist($capturedWorkspace);
    }

    public function testRunnerCustomWorkspaceNotCleaned(): void
    {
        $tmpDir = sys_get_temp_dir() . '/superagent_e2e_custom_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $runner = new ScenarioRunner(workspaceDir: $tmpDir);
        $scenario = Scenario::create('custom_ws', 'test');

        $runner->run($scenario);

        // Custom workspace dir should NOT be deleted
        $this->assertDirectoryExists($tmpDir);

        // Cleanup
        $this->recursiveDelete($tmpDir);
    }

    // ── Validation logic (unit tests via ScenarioRunner internals) ─

    public function testValidationRequiredToolsFail(): void
    {
        // We test validation by creating a scenario with required tools
        // but the runner won't call any tools (error path), so validation fails
        $runner = new ScenarioRunner(['api_key' => 'fake']); // will fail at API call

        $scenario = Scenario::create('tool_check', 'test')
            ->withRequiredTools(['read', 'write']);

        $result = $runner->run($scenario);
        // Should have an error (not tool validation failures since agent creation failed)
        $this->assertFalse($result->passed);
    }

    public function testValidationExpectedTextCheck(): void
    {
        // Test via ScenarioResult directly
        $msg = new AssistantMessage();
        $msg->content = [ContentBlock::text('The answer is 42.')];
        $agentResult = new AgentResult(message: $msg, allResponses: [$msg]);

        // Simulate expected text check
        $text = $agentResult->text();
        $this->assertStringContainsString('42', $text);
        $this->assertStringNotContainsString('99', $text);
    }

    // ── Helper ────────────────────────────────────────────────────

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
