<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Evals;

use Generator;
use PHPUnit\Framework\TestCase;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Evals\ScoreCatalog;
use SuperAgent\Evals\SmartOrchestrator;
use SuperAgent\Exceptions\BudgetExceededException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;

/**
 * Pure unit tests for SmartOrchestrator. We avoid the network by:
 *   - reflection on private parse/route methods (the most error-prone surface),
 *   - a tiny FakeProvider for the public run() / replayFromPlan() paths.
 *
 * The orchestrator's parallel mode shells out to a `superagent _subtask` worker;
 * we only exercise the serial path here. Parallel-mode coverage belongs in a
 * Smoke or Integration test that has the binary on disk.
 */
class SmartOrchestratorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/smart_orch_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupDir($this->tempDir);
    }

    // ---------------------------------------------------------------
    // Constructor — brain validation
    // ---------------------------------------------------------------

    public function test_constructor_rejects_unknown_brain_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not in the catalog/i');
        new SmartOrchestrator(
            catalog: $this->emptyCatalog(),
            brainOverride: 'definitely-not-a-real-model-12345',
        );
    }

    public function test_constructor_accepts_null_brain_override(): void
    {
        // No exception even when catalog is empty — falls back to ranked/configured/hardcoded brain.
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $this->assertInstanceOf(SmartOrchestrator::class, $orch);
    }

    // ---------------------------------------------------------------
    // pickBrain()
    // ---------------------------------------------------------------

    public function test_pick_brain_uses_explicit_override_when_in_catalog(): void
    {
        // Use the hardcoded last-resort id — guaranteed to exist in the bundled catalog.
        $orch = new SmartOrchestrator(
            catalog: $this->emptyCatalog(),
            brainOverride: 'claude-opus-4-7',
        );
        $this->assertSame('claude-opus-4-7', $orch->pickBrain());
    }

    public function test_pick_brain_falls_back_to_hardcoded_when_no_scores_no_config(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        // Returns the hardcoded fallback (or whatever is in the configured default).
        $this->assertNotEmpty($orch->pickBrain());
    }

    // ---------------------------------------------------------------
    // tryParsePlan() — strict parser used to decide retry
    // ---------------------------------------------------------------

    public function test_try_parse_plan_returns_null_for_empty_input(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $this->assertNull($this->invoke($orch, 'tryParsePlan', ['']));
    }

    public function test_try_parse_plan_returns_null_when_no_subtasks(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $bad = json_encode(['complexity' => 'simple', 'subtasks' => []]);
        $this->assertNull($this->invoke($orch, 'tryParsePlan', [$bad]));
    }

    public function test_try_parse_plan_returns_null_when_subtask_prompt_empty(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $bad = json_encode(['subtasks' => [['id' => '1', 'prompt' => '   ']]]);
        $this->assertNull($this->invoke($orch, 'tryParsePlan', [$bad]));
    }

    public function test_try_parse_plan_extracts_json_from_surrounding_prose(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $raw = "Here you go:\n" . json_encode([
            'complexity' => 'simple',
            'primary_dim' => 'reasoning',
            'concurrency' => 'serial',
            'subtasks' => [['id' => '1', 'prompt' => 'do the thing', 'difficulty' => 'hard', 'dim' => 'reasoning']],
        ]) . "\nHope that helps!";
        $plan = $this->invoke($orch, 'tryParsePlan', [$raw]);
        $this->assertIsArray($plan);
        $this->assertSame('simple', $plan['complexity']);
        $this->assertCount(1, $plan['subtasks']);
    }

    // ---------------------------------------------------------------
    // parsePlan() — lenient parser, always returns a Plan
    // ---------------------------------------------------------------

    public function test_parse_plan_falls_back_to_single_subtask_on_garbage(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $plan = $this->invoke($orch, 'parsePlan', ['<<not json>>', 'fix the bug']);
        $this->assertSame('simple', $plan['complexity']);
        $this->assertCount(1, $plan['subtasks']);
        $this->assertSame('fix the bug', $plan['subtasks'][0]['prompt']);
    }

    public function test_parse_plan_normalises_unknown_enum_values(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $raw = json_encode([
            'complexity' => 'BANANAS',
            'primary_dim' => 'unknown_dim',
            'concurrency' => 'maybe',
            'subtasks' => [
                ['id' => '1', 'prompt' => 'p', 'difficulty' => 'medium', 'dim' => 'oops'],
            ],
        ]);
        $plan = $this->invoke($orch, 'parsePlan', [$raw, 'task']);
        $this->assertSame('simple', $plan['complexity']);  // default
        $this->assertSame('reasoning', $plan['primary_dim']); // default
        $this->assertSame('serial', $plan['concurrency']);  // default
        $this->assertSame('hard', $plan['subtasks'][0]['difficulty']); // default
        $this->assertSame('reasoning', $plan['subtasks'][0]['dim']);   // primary_dim default
    }

    // ---------------------------------------------------------------
    // routeSubtask()
    // ---------------------------------------------------------------

    public function test_route_subtask_falls_back_to_brain_when_catalog_empty(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $st = ['difficulty' => 'hard', 'dim' => 'coding'];
        $this->assertSame('claude-opus-4-7', $this->invoke($orch, 'routeSubtask', [$st, 'claude-opus-4-7']));
    }

    public function test_route_subtask_easy_falls_back_to_best_model_when_below_threshold(): void
    {
        $catalogPath = $this->tempDir . '/scores.json';
        file_put_contents($catalogPath, json_encode([
            '_meta' => ['schema_version' => 1, 'updated_at' => date('c')],
            'models' => [
                'claude-opus-4-7' => [
                    'provider' => 'anthropic',
                    'dims' => ['coding' => ['score' => 0.5]],
                    'overall' => 0.5,
                ],
            ],
        ]));
        $catalog = new ScoreCatalog($catalogPath);
        $orch = new SmartOrchestrator(
            catalog: $catalog,
            easyThreshold: 0.9,  // higher than the only catalog entry
        );
        $st = ['difficulty' => 'easy', 'dim' => 'coding'];
        // No model passes 0.9 — falls back to bestModelFor('coding').
        $this->assertSame('claude-opus-4-7', $this->invoke($orch, 'routeSubtask', [$st, 'brain-fallback']));
    }

    // ---------------------------------------------------------------
    // mergeUsage()
    // ---------------------------------------------------------------

    public function test_merge_usage_handles_nulls(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $a = new \SuperAgent\Messages\Usage(10, 20);
        $this->assertSame($a, $this->invoke($orch, 'mergeUsage', [null, $a]));
        $this->assertSame($a, $this->invoke($orch, 'mergeUsage', [$a, null]));
        $this->assertNull($this->invoke($orch, 'mergeUsage', [null, null]));
    }

    public function test_merge_usage_sums_token_fields(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        $a = new \SuperAgent\Messages\Usage(10, 20, 5, null);
        $b = new \SuperAgent\Messages\Usage(3, 7, null, 4);
        $merged = $this->invoke($orch, 'mergeUsage', [$a, $b]);
        $this->assertSame(13, $merged->inputTokens);
        $this->assertSame(27, $merged->outputTokens);
        $this->assertSame(5, $merged->cacheCreationInputTokens);
        $this->assertSame(4, $merged->cacheReadInputTokens);
    }

    // ---------------------------------------------------------------
    // assertBudget()
    // ---------------------------------------------------------------

    public function test_assert_budget_no_op_when_cap_unset(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog());
        // Should not throw at any spend.
        $this->invoke($orch, 'assertBudget', [9999.0, 'test']);
        $this->assertTrue(true);
    }

    public function test_assert_budget_throws_when_spent_exceeds_cap(): void
    {
        $orch = new SmartOrchestrator(catalog: $this->emptyCatalog(), maxCostUsd: 0.10);
        $this->expectException(BudgetExceededException::class);
        $this->invoke($orch, 'assertBudget', [0.50, 'test']);
    }

    // ---------------------------------------------------------------
    // run() — end-to-end via FakeProvider
    // ---------------------------------------------------------------

    public function test_run_with_single_subtask_skips_merge(): void
    {
        $catalogPath = $this->tempDir . '/scores.json';
        $logDir = $this->tempDir . '/runs';

        // Brain returns a 1-subtask plan, then the subtask call returns the answer.
        $provider = new FakeProvider([
            json_encode([
                'complexity' => 'simple',
                'primary_dim' => 'reasoning',
                'concurrency' => 'serial',
                'subtasks' => [['id' => '1', 'prompt' => 'compute 2+2', 'difficulty' => 'hard', 'dim' => 'reasoning']],
            ]),
            'The answer is 4.',
        ]);

        $orch = new SmartOrchestratorWithFakeProvider(
            catalog: new ScoreCatalog($catalogPath),
            runLogDir: $logDir,
            fakeProvider: $provider,
        );
        $events = [];
        $orch->setEventCallback(function (array $e) use (&$events): void {
            $events[] = $e['type'];
        });

        $result = $orch->run('what is 2+2?');
        $this->assertSame('The answer is 4.', $result['final']);
        $this->assertCount(1, $result['subtask_results']);
        $this->assertContains('merge_skipped', $events);
        $this->assertNotNull($result['run_log_path']);
        $this->assertFileExists($result['run_log_path']);
    }

    public function test_run_persists_log_with_expected_shape(): void
    {
        $logDir = $this->tempDir . '/runs2';
        $provider = new FakeProvider([
            json_encode([
                'complexity' => 'simple',
                'primary_dim' => 'reasoning',
                'concurrency' => 'serial',
                'subtasks' => [['id' => '1', 'prompt' => 'q', 'difficulty' => 'hard', 'dim' => 'reasoning']],
            ]),
            'A',
        ]);
        $orch = new SmartOrchestratorWithFakeProvider(
            catalog: $this->emptyCatalog(),
            runLogDir: $logDir,
            fakeProvider: $provider,
        );
        $result = $orch->run('q');

        $log = json_decode((string) file_get_contents($result['run_log_path']), true);
        $this->assertIsArray($log);
        $this->assertSame('q', $log['task']);
        $this->assertArrayHasKey('plan', $log);
        $this->assertArrayHasKey('subtask_results', $log);
        $this->assertArrayHasKey('final', $log);
        $this->assertArrayHasKey('plan_raw', $log);
    }

    public function test_default_run_log_dir_under_home(): void
    {
        $dir = SmartOrchestrator::defaultRunLogDir();
        $this->assertStringContainsString('.superagent', $dir);
        $this->assertStringContainsString('smart_runs', $dir);
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    private function emptyCatalog(): ScoreCatalog
    {
        return new ScoreCatalog($this->tempDir . '/' . bin2hex(random_bytes(3)) . '.json');
    }

    /**
     * Reflection helper for private methods on SmartOrchestrator (and our subclass).
     *
     * @param array<int, mixed> $args
     */
    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionClass(SmartOrchestrator::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir . '/' . $i;
            is_dir($p) ? $this->cleanupDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

/**
 * Minimal LLMProvider double — yields one AssistantMessage per chat() call,
 * cycling through a queue of canned text responses. No network, no streaming,
 * no token accounting.
 */
final class FakeProvider implements LLMProvider
{
    /** @var list<string> */
    private array $responses;
    private int $cursor = 0;
    private string $model = 'claude-opus-4-7';

    /** @param list<string> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function chat(array $messages, array $tools = [], ?string $systemPrompt = null, array $options = []): Generator
    {
        $text = $this->responses[$this->cursor] ?? '';
        $this->cursor++;
        $msg = new AssistantMessage();
        $msg->content[] = ContentBlock::text($text);
        yield $msg;
    }

    public function formatMessages(array $messages): array
    {
        return [];
    }

    public function formatTools(array $tools): array
    {
        return [];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function name(): string
    {
        return 'fake';
    }
}

/**
 * Subclass that swaps `buildProvider()` for the FakeProvider so we can drive
 * `run()` without a real HTTP call. Reuses every other code path verbatim.
 */
final class SmartOrchestratorWithFakeProvider extends SmartOrchestrator
{
    private FakeProvider $fake;
    /** @var (callable(array<string,mixed>):void)|null */
    private $eventCb;

    public function __construct(
        ScoreCatalog $catalog,
        string $runLogDir,
        FakeProvider $fakeProvider,
    ) {
        parent::__construct(catalog: $catalog, runLogDir: $runLogDir);
        $this->fake = $fakeProvider;
    }

    public function setEventCallback(\Closure $cb): void
    {
        $this->eventCb = $cb;
        $ref = new \ReflectionClass(SmartOrchestrator::class);
        $prop = $ref->getProperty('onEvent');
        $prop->setAccessible(true);
        $prop->setValue($this, $cb);
    }

    protected function buildProvider(string $modelId): LLMProvider
    {
        $this->fake->setModel($modelId);
        return $this->fake;
    }
}
