<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\AgentResult;
use SuperAgent\Coordinator\AbstractPipelineListener;
use SuperAgent\Coordinator\AgentProviderConfig;
use SuperAgent\Coordinator\AgentRetryPolicy;
use SuperAgent\Coordinator\CollaborationPhase;
use SuperAgent\Coordinator\CollaborationPipeline;
use SuperAgent\Coordinator\CollaborationResult;
use SuperAgent\Coordinator\FailureStrategy;
use SuperAgent\Coordinator\ParallelPhaseExecutor;
use SuperAgent\Coordinator\PhaseResult;
use SuperAgent\Coordinator\PipelineListener;
use SuperAgent\Coordinator\TaskNotification;
use SuperAgent\Coordinator\TaskRouter;
use SuperAgent\CostPrediction\TaskProfile;
use SuperAgent\Providers\CredentialPool;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;

class CollaborationPipelineTest extends TestCase
{
    // ─── FailureStrategy ───

    public function testFailureStrategyValues(): void
    {
        $this->assertEquals('fail_fast', FailureStrategy::FAIL_FAST->value);
        $this->assertEquals('continue', FailureStrategy::CONTINUE->value);
        $this->assertEquals('retry', FailureStrategy::RETRY->value);
        $this->assertEquals('fallback', FailureStrategy::FALLBACK->value);
    }

    public function testFailureStrategyFromString(): void
    {
        $this->assertSame(FailureStrategy::FAIL_FAST, FailureStrategy::from('fail_fast'));
        $this->assertSame(FailureStrategy::CONTINUE, FailureStrategy::from('continue'));
        $this->assertSame(FailureStrategy::RETRY, FailureStrategy::from('retry'));
        $this->assertSame(FailureStrategy::FALLBACK, FailureStrategy::from('fallback'));
    }

    // ─── PhaseResult ───

    public function testPhaseResultLifecycle(): void
    {
        $result = new PhaseResult('research');

        $this->assertEquals('research', $result->phaseName);
        $this->assertSame(AgentStatus::PENDING, $result->getStatus());
        $this->assertFalse($result->isSuccessful());

        $result->markRunning();
        $this->assertSame(AgentStatus::RUNNING, $result->getStatus());

        $result->markCompleted();
        $this->assertSame(AgentStatus::COMPLETED, $result->getStatus());
        $this->assertTrue($result->isSuccessful());
        $this->assertNull($result->getError());
        $this->assertGreaterThan(0, $result->getDurationMs());
    }

    public function testPhaseResultFailure(): void
    {
        $result = new PhaseResult('build');
        $result->markRunning();
        $result->markFailed('Compilation error');

        $this->assertSame(AgentStatus::FAILED, $result->getStatus());
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Compilation error', $result->getError());
    }

    public function testPhaseResultAgentResults(): void
    {
        $result = new PhaseResult('test');
        $this->assertEquals(0, $result->getAgentCount());
        $this->assertEmpty($result->getAgentResults());

        $agentResult = new AgentResult(null, [], [], 0.05);
        $result->addAgentResult('tester-1', $agentResult);

        $this->assertEquals(1, $result->getAgentCount());
        $this->assertSame($agentResult, $result->getAgentResult('tester-1'));
        $this->assertNull($result->getAgentResult('nonexistent'));
        $this->assertEquals(0.05, $result->getTotalCostUsd());
    }

    public function testPhaseResultToArray(): void
    {
        $result = new PhaseResult('deploy');
        $result->markRunning();
        $result->addAgentResult('deployer', new AgentResult(null, [], [], 0.1));
        $result->markCompleted();

        $array = $result->toArray();

        $this->assertEquals('deploy', $array['phase']);
        $this->assertEquals('completed', $array['status']);
        $this->assertNull($array['error']);
        $this->assertArrayHasKey('duration_ms', $array);
        $this->assertEquals(0.1, $array['total_cost_usd']);
        $this->assertEquals(1, $array['agent_count']);
        $this->assertArrayHasKey('deployer', $array['agents']);
    }

    // ─── CollaborationPhase ───

    public function testPhaseConstruction(): void
    {
        $phase = new CollaborationPhase('research');

        $this->assertEquals('research', $phase->name);
        $this->assertEmpty($phase->getAgents());
        $this->assertEmpty($phase->getDependencies());
        $this->assertSame(FailureStrategy::FAIL_FAST, $phase->getFailureStrategy());
        $this->assertEquals(1, $phase->getMaxRetries());
        $this->assertEquals(300, $phase->getTimeoutSeconds());
    }

    public function testPhaseAddAgents(): void
    {
        $phase = new CollaborationPhase('research');
        $config1 = new AgentSpawnConfig(name: 'agent-1', prompt: 'Do task 1');
        $config2 = new AgentSpawnConfig(name: 'agent-2', prompt: 'Do task 2');

        $phase->addAgent($config1);
        $phase->addAgent($config2);

        $this->assertCount(2, $phase->getAgents());
        $this->assertEquals(2, $phase->getAgentCount());
    }

    public function testPhaseAddMultipleAgents(): void
    {
        $phase = new CollaborationPhase('batch');
        $configs = [
            new AgentSpawnConfig(name: 'a', prompt: '1'),
            new AgentSpawnConfig(name: 'b', prompt: '2'),
            new AgentSpawnConfig(name: 'c', prompt: '3'),
        ];

        $phase->addAgents($configs);
        $this->assertCount(3, $phase->getAgents());
    }

    public function testPhaseDependencies(): void
    {
        $phase = new CollaborationPhase('implement');
        $phase->dependsOn('research', 'design');

        $this->assertEquals(['research', 'design'], $phase->getDependencies());

        // No duplicates
        $phase->dependsOn('research');
        $this->assertCount(2, $phase->getDependencies());
    }

    public function testPhaseCondition(): void
    {
        $phase = new CollaborationPhase('deploy');
        $phase->when(function (array $priorResults): bool {
            return isset($priorResults['test']) && $priorResults['test']->isSuccessful();
        });

        // Condition not met (no test result)
        $this->assertFalse($phase->shouldRun([]));

        // Condition met
        $testResult = new PhaseResult('test');
        $testResult->markRunning();
        $testResult->markCompleted();
        $this->assertTrue($phase->shouldRun(['test' => $testResult]));
    }

    public function testPhaseConditionDefaultTrue(): void
    {
        $phase = new CollaborationPhase('always-run');
        $this->assertTrue($phase->shouldRun([]));
    }

    public function testPhaseFailureStrategies(): void
    {
        $phase = new CollaborationPhase('test');

        $phase->onFailure(FailureStrategy::CONTINUE);
        $this->assertSame(FailureStrategy::CONTINUE, $phase->getFailureStrategy());

        $phase->withRetries(3);
        $this->assertSame(FailureStrategy::RETRY, $phase->getFailureStrategy());
        $this->assertEquals(3, $phase->getMaxRetries());

        $phase->withFallback('safe-deploy');
        $this->assertSame(FailureStrategy::FALLBACK, $phase->getFailureStrategy());
        $this->assertEquals('safe-deploy', $phase->getFallbackPhase());
    }

    public function testPhaseTimeout(): void
    {
        $phase = new CollaborationPhase('long-task');
        $phase->withTimeout(600);
        $this->assertEquals(600, $phase->getTimeoutSeconds());
    }

    public function testPhaseFluentApi(): void
    {
        $phase = new CollaborationPhase('test');
        $result = $phase
            ->addAgent(new AgentSpawnConfig(name: 'a', prompt: 'p'))
            ->dependsOn('research')
            ->onFailure(FailureStrategy::CONTINUE)
            ->withTimeout(120);

        $this->assertSame($phase, $result);
    }

    // ─── CollaborationResult ───

    public function testCollaborationResultLifecycle(): void
    {
        $result = new CollaborationResult();
        $this->assertSame(AgentStatus::PENDING, $result->getStatus());

        $result->markRunning();
        $this->assertSame(AgentStatus::RUNNING, $result->getStatus());

        $result->markCompleted();
        $this->assertTrue($result->isSuccessful());
        $this->assertGreaterThan(0, $result->getDurationMs());
    }

    public function testCollaborationResultPhases(): void
    {
        $result = new CollaborationResult();

        $phase1 = new PhaseResult('research');
        $phase1->markRunning();
        $phase1->addAgentResult('r1', new AgentResult(null, [], [], 0.01));
        $phase1->addAgentResult('r2', new AgentResult(null, [], [], 0.02));
        $phase1->markCompleted();

        $phase2 = new PhaseResult('implement');
        $phase2->markRunning();
        $phase2->addAgentResult('i1', new AgentResult(null, [], [], 0.05));
        $phase2->markCompleted();

        $result->addPhaseResult($phase1);
        $result->addPhaseResult($phase2);
        $result->markCompleted();

        $this->assertEquals(2, $result->getCompletedPhaseCount());
        $this->assertEquals(3, $result->getTotalAgentCount());
        $this->assertEqualsWithDelta(0.08, $result->getTotalCostUsd(), 0.0001);
        $this->assertEmpty($result->getFailedPhases());
        $this->assertSame($phase1, $result->getPhaseResult('research'));
        $this->assertNull($result->getPhaseResult('nonexistent'));
    }

    public function testCollaborationResultFailedPhases(): void
    {
        $result = new CollaborationResult();

        $success = new PhaseResult('research');
        $success->markRunning();
        $success->markCompleted();

        $failed = new PhaseResult('deploy');
        $failed->markRunning();
        $failed->markFailed('Connection refused');

        $result->addPhaseResult($success);
        $result->addPhaseResult($failed);
        $result->markFailed();

        $this->assertFalse($result->isSuccessful());
        $this->assertEquals(1, $result->getCompletedPhaseCount());
        $failedPhases = $result->getFailedPhases();
        $this->assertArrayHasKey('deploy', $failedPhases);
        $this->assertEquals('Connection refused', $failedPhases['deploy']);
    }

    public function testCollaborationResultSkippedPhases(): void
    {
        $result = new CollaborationResult();
        $result->addSkippedPhase('optional-step');
        $result->addSkippedPhase('conditional-step');

        $this->assertEquals(['optional-step', 'conditional-step'], $result->getSkippedPhases());
    }

    public function testCollaborationResultSummary(): void
    {
        $result = new CollaborationResult();
        $result->markRunning();

        $p = new PhaseResult('test');
        $p->markRunning();
        $p->markCompleted();
        $result->addPhaseResult($p);
        $result->markCompleted();

        $summary = $result->summary();
        $this->assertStringContainsString('Pipeline completed', $summary);
        $this->assertStringContainsString('1/1 phases completed', $summary);
    }

    public function testCollaborationResultToArray(): void
    {
        $result = new CollaborationResult();
        $result->markRunning();
        $result->addSkippedPhase('skipped');
        $result->markCompleted();

        $array = $result->toArray();

        $this->assertEquals('completed', $array['status']);
        $this->assertArrayHasKey('duration_ms', $array);
        $this->assertArrayHasKey('total_cost_usd', $array);
        $this->assertEquals(['skipped'], $array['skipped_phases']);
    }

    // ─── CollaborationPipeline ───

    public function testPipelineCreation(): void
    {
        $pipeline = CollaborationPipeline::create();
        $this->assertInstanceOf(CollaborationPipeline::class, $pipeline);
        $this->assertEmpty($pipeline->getPhases());
    }

    public function testPipelinePhaseBuilder(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->phase('research', function (CollaborationPhase $phase) {
                $phase->addAgent(new AgentSpawnConfig(name: 'r1', prompt: 'Research'));
                $phase->addAgent(new AgentSpawnConfig(name: 'r2', prompt: 'Research'));
            })
            ->phase('implement', function (CollaborationPhase $phase) {
                $phase->dependsOn('research');
                $phase->addAgent(new AgentSpawnConfig(name: 'coder', prompt: 'Code'));
            });

        $this->assertCount(2, $pipeline->getPhases());
        $this->assertNotNull($pipeline->getPhase('research'));
        $this->assertNotNull($pipeline->getPhase('implement'));
        $this->assertNull($pipeline->getPhase('nonexistent'));
    }

    public function testPipelineAddPhase(): void
    {
        $phase = new CollaborationPhase('test');
        $phase->addAgent(new AgentSpawnConfig(name: 'tester', prompt: 'Test'));

        $pipeline = CollaborationPipeline::create()->addPhase($phase);

        $this->assertSame($phase, $pipeline->getPhase('test'));
    }

    public function testPipelineTopologicalOrder(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->phase('verify', function (CollaborationPhase $phase) {
                $phase->dependsOn('implement');
            })
            ->phase('research', function (CollaborationPhase $phase) {
                // No dependencies
            })
            ->phase('implement', function (CollaborationPhase $phase) {
                $phase->dependsOn('research');
            });

        $order = $pipeline->getExecutionOrder();

        $this->assertCount(3, $order);
        // research must come before implement, implement before verify
        $researchIdx = array_search('research', $order);
        $implementIdx = array_search('implement', $order);
        $verifyIdx = array_search('verify', $order);

        $this->assertLessThan($implementIdx, $researchIdx);
        $this->assertLessThan($verifyIdx, $implementIdx);
    }

    public function testPipelineCircularDependencyDetection(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->phase('a', function (CollaborationPhase $phase) {
                $phase->dependsOn('b');
            })
            ->phase('b', function (CollaborationPhase $phase) {
                $phase->dependsOn('a');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency');
        $pipeline->getExecutionOrder();
    }

    public function testPipelineUndefinedDependency(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->phase('impl', function (CollaborationPhase $phase) {
                $phase->dependsOn('nonexistent');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("depends on undefined phase 'nonexistent'");
        $pipeline->getExecutionOrder();
    }

    public function testPipelineRunEmptyPhases(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->phase('empty', function (CollaborationPhase $phase) {
                // No agents
            });

        $result = $pipeline->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->getPhaseResult('empty'));
        $this->assertTrue($result->getPhaseResult('empty')->isSuccessful());
    }

    public function testPipelineSkipsConditionalPhase(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->phase('always', function (CollaborationPhase $phase) {
                // No agents, completes immediately
            })
            ->phase('conditional', function (CollaborationPhase $phase) {
                $phase->dependsOn('always');
                $phase->when(fn(array $results) => false); // Always skip
            });

        $result = $pipeline->run();

        $this->assertTrue($result->isSuccessful());
        $this->assertContains('conditional', $result->getSkippedPhases());
        $this->assertNull($result->getPhaseResult('conditional'));
    }

    // ─── PipelineListener ───

    public function testAbstractPipelineListenerAllNoop(): void
    {
        $listener = new class extends AbstractPipelineListener {
            public array $events = [];

            public function onPipelineStart(array $phaseNames): void
            {
                $this->events[] = ['pipeline_start', $phaseNames];
            }

            public function onPipelineComplete(CollaborationResult $result): void
            {
                $this->events[] = ['pipeline_complete', $result->getStatus()->value];
            }

            public function onPhaseStart(string $phaseName, int $agentCount): void
            {
                $this->events[] = ['phase_start', $phaseName, $agentCount];
            }

            public function onPhaseComplete(string $phaseName, PhaseResult $result): void
            {
                $this->events[] = ['phase_complete', $phaseName];
            }

            public function onPhaseSkipped(string $phaseName, string $reason): void
            {
                $this->events[] = ['phase_skipped', $phaseName, $reason];
            }
        };

        $pipeline = CollaborationPipeline::create()
            ->addListener($listener)
            ->phase('step1', function (CollaborationPhase $phase) {
                // empty
            })
            ->phase('step2', function (CollaborationPhase $phase) {
                $phase->dependsOn('step1');
                $phase->when(fn() => false);
            });

        $result = $pipeline->run();

        $this->assertCount(5, $listener->events);
        $this->assertEquals('pipeline_start', $listener->events[0][0]);
        $this->assertEquals('phase_start', $listener->events[1][0]);
        $this->assertEquals('phase_complete', $listener->events[2][0]);
        $this->assertEquals('phase_skipped', $listener->events[3][0]);
        $this->assertEquals('pipeline_complete', $listener->events[4][0]);
    }

    // ─── ParallelPhaseExecutor ───

    public function testExecutorEmptyPhase(): void
    {
        $executor = new ParallelPhaseExecutor();
        $phase = new CollaborationPhase('empty');

        $result = $executor->execute($phase);

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(0, $result->getAgentCount());
    }

    // ─── TaskNotification (existing class) ───

    public function testTaskNotificationXmlRoundTrip(): void
    {
        $notification = new TaskNotification(
            taskId: 'agent-123',
            status: 'completed',
            summary: 'Research completed successfully',
            result: 'Found 3 relevant files',
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
            costUsd: 0.0015,
            durationMs: 1234.5,
            toolsUsed: ['Read', 'Grep'],
            turnCount: 3,
        );

        $xml = $notification->toXml();
        $this->assertStringContainsString('<task-id>agent-123</task-id>', $xml);
        $this->assertStringContainsString('<status>completed</status>', $xml);

        $parsed = TaskNotification::fromXml($xml);
        $this->assertNotNull($parsed);
        $this->assertEquals('agent-123', $parsed->taskId);
        $this->assertEquals('completed', $parsed->status);
        $this->assertEquals('Research completed successfully', $parsed->summary);
        $this->assertEquals('Found 3 relevant files', $parsed->result);
        $this->assertEquals(0.0015, $parsed->costUsd);
        $this->assertEquals(3, $parsed->turnCount);
    }

    public function testTaskNotificationToText(): void
    {
        $notification = new TaskNotification(
            taskId: 'task-1',
            status: 'failed',
            summary: 'Build failed',
            error: 'Syntax error on line 42',
            costUsd: 0.003,
            turnCount: 5,
        );

        $text = $notification->toText();
        $this->assertStringContainsString('[failed]', $text);
        $this->assertStringContainsString('task-1', $text);
        $this->assertStringContainsString('Syntax error', $text);
    }

    public function testTaskNotificationFromResult(): void
    {
        $notification = TaskNotification::fromResult('t1', 'completed', [
            'summary' => 'Done',
            'result' => 'All tests pass',
            'cost_usd' => 0.01,
            'turn_count' => 2,
        ]);

        $this->assertEquals('t1', $notification->taskId);
        $this->assertEquals('completed', $notification->status);
        $this->assertEquals('Done', $notification->summary);
        $this->assertEquals('All tests pass', $notification->result);
    }

    // ─── Integration: Pipeline with dependencies ───

    public function testPipelineDiamondDependency(): void
    {
        // Diamond: A → B, A → C, B → D, C → D
        $pipeline = CollaborationPipeline::create()
            ->phase('A', function (CollaborationPhase $phase) {})
            ->phase('B', function (CollaborationPhase $phase) {
                $phase->dependsOn('A');
            })
            ->phase('C', function (CollaborationPhase $phase) {
                $phase->dependsOn('A');
            })
            ->phase('D', function (CollaborationPhase $phase) {
                $phase->dependsOn('B', 'C');
            });

        $order = $pipeline->getExecutionOrder();
        $this->assertCount(4, $order);

        $aIdx = array_search('A', $order);
        $bIdx = array_search('B', $order);
        $cIdx = array_search('C', $order);
        $dIdx = array_search('D', $order);

        $this->assertLessThan($bIdx, $aIdx);
        $this->assertLessThan($cIdx, $aIdx);
        $this->assertLessThan($dIdx, $bIdx);
        $this->assertLessThan($dIdx, $cIdx);
    }

    public function testPipelineMultipleListeners(): void
    {
        $counter1 = new class extends AbstractPipelineListener {
            public int $startCount = 0;
            public function onPipelineStart(array $phaseNames): void
            {
                $this->startCount++;
            }
        };

        $counter2 = new class extends AbstractPipelineListener {
            public int $startCount = 0;
            public function onPipelineStart(array $phaseNames): void
            {
                $this->startCount++;
            }
        };

        $pipeline = CollaborationPipeline::create()
            ->addListener($counter1)
            ->addListener($counter2)
            ->phase('test', function (CollaborationPhase $phase) {});

        $pipeline->run();

        $this->assertEquals(1, $counter1->startCount);
        $this->assertEquals(1, $counter2->startCount);
    }

    public function testCollaborationResultGetAllText(): void
    {
        $result = new CollaborationResult();
        $phase = new PhaseResult('research');
        $phase->markRunning();
        $phase->addAgentResult('agent-1', new AgentResult(null, [], [], 0.0));
        $phase->markCompleted();
        $result->addPhaseResult($phase);

        $text = $result->getAllText();
        $this->assertStringContainsString('Phase: research', $text);
        $this->assertStringContainsString('[agent-1]', $text);
    }

    // ─── AgentProviderConfig ───

    public function testSameProviderConfig(): void
    {
        $config = AgentProviderConfig::sameProvider('anthropic', config: [
            'model' => 'claude-sonnet-4-6',
        ]);

        $this->assertEquals('anthropic', $config->getProviderName());
        $this->assertEquals('claude-sonnet-4-6', $config->getConfig()['model']);
        $this->assertFalse($config->hasFallbackChain());
    }

    public function testCrossProviderConfig(): void
    {
        $config = AgentProviderConfig::crossProvider('openai', [
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ]);

        $this->assertEquals('openai', $config->getProviderName());
        $this->assertEquals('sk-test', $config->getConfig()['api_key']);
    }

    public function testFallbackChainConfig(): void
    {
        $config = AgentProviderConfig::withFallbackChain([
            'anthropic',
            ['name' => 'openai', 'config' => ['model' => 'gpt-4o']],
            'ollama',
        ]);

        $this->assertTrue($config->hasFallbackChain());
        $this->assertEquals(['anthropic', 'openai', 'ollama'], $config->getFallbackProviders());
        $this->assertEquals('anthropic', $config->getProviderName());
    }

    public function testProviderConfigFluentSetters(): void
    {
        $config = AgentProviderConfig::sameProvider('anthropic')
            ->withModel('claude-opus-4-6')
            ->withApiKey('sk-test-key')
            ->withConfig(['max_tokens' => 4096]);

        $c = $config->getConfig();
        $this->assertEquals('claude-opus-4-6', $c['model']);
        $this->assertEquals('sk-test-key', $c['api_key']);
        $this->assertEquals(4096, $c['max_tokens']);
    }

    public function testProviderConfigWithCredentialPool(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1', 'round_robin');
        $pool->addCredential('anthropic', 'key-2', 'round_robin');

        $config = AgentProviderConfig::sameProvider('anthropic', $pool);

        $this->assertSame($pool, $config->getCredentialPool());

        // toSpawnConfig should inject a rotated key
        $spawnConfig = $config->toSpawnConfig();
        $this->assertEquals('anthropic', $spawnConfig['provider']);
        $this->assertNotNull($spawnConfig['api_key']);
        $this->assertContains($spawnConfig['api_key'], ['key-1', 'key-2']);
    }

    public function testProviderConfigCredentialRotation(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-A', 'round_robin');
        $pool->addCredential('anthropic', 'key-B', 'round_robin');

        $config = AgentProviderConfig::sameProvider('anthropic', $pool);

        // Get two keys — should rotate
        $key1 = $config->toSpawnConfig()['api_key'];
        $key2 = $config->toSpawnConfig()['api_key'];

        $this->assertContains($key1, ['key-A', 'key-B']);
        $this->assertContains($key2, ['key-A', 'key-B']);
        // With round_robin and 2 keys, after using one, the other should be selected
        $this->assertNotEquals($key1, $key2);
    }

    public function testProviderConfigCredentialPoolReporting(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1');
        $pool->addCredential('anthropic', 'key-2');

        $config = AgentProviderConfig::sameProvider('anthropic', $pool);

        // Report rate limit on key-1
        $config->reportRateLimit('key-1');

        $stats = $pool->getStats('anthropic');
        $this->assertEquals(2, $stats['total']);
        // key-1 should be in cooldown
        $this->assertEquals(1, $stats['cooldown']);
        $this->assertEquals(1, $stats['ok']);
    }

    public function testProviderConfigAddFallback(): void
    {
        $config = AgentProviderConfig::sameProvider('anthropic')
            ->addFallback('openai', ['model' => 'gpt-4o'])
            ->addFallback('ollama');

        $this->assertTrue($config->hasFallbackChain());
        $this->assertCount(2, $config->getFallbackProviders());
    }

    // ─── AgentRetryPolicy ───

    public function testRetryPolicyDefault(): void
    {
        $policy = AgentRetryPolicy::default();

        $this->assertEquals(3, $policy->getMaxAttempts());
        $this->assertEquals('exponential', $policy->getBackoffType());
        $this->assertEquals(1000, $policy->getBaseDelayMs());
        $this->assertEquals(30000, $policy->getMaxDelayMs());
        $this->assertTrue($policy->hasJitter());
        $this->assertTrue($policy->isCredentialRotationEnabled());
        $this->assertFalse($policy->isProviderFallbackEnabled());
    }

    public function testRetryPolicyAggressive(): void
    {
        $policy = AgentRetryPolicy::aggressive();

        $this->assertEquals(5, $policy->getMaxAttempts());
        $this->assertEquals(2000, $policy->getBaseDelayMs());
        $this->assertEquals(60000, $policy->getMaxDelayMs());
    }

    public function testRetryPolicyNone(): void
    {
        $policy = AgentRetryPolicy::none();
        $this->assertEquals(1, $policy->getMaxAttempts());

        // Should not retry anything
        $error = new \RuntimeException('Server error', 500);
        $this->assertFalse($policy->shouldRetry(1, $error));
    }

    public function testRetryPolicyCrossProvider(): void
    {
        $policy = AgentRetryPolicy::crossProvider(['openai', 'ollama']);

        $this->assertTrue($policy->isProviderFallbackEnabled());
        $this->assertEquals(['openai', 'ollama'], $policy->getFallbackProviders());
    }

    public function testRetryPolicyFluentSetters(): void
    {
        $policy = AgentRetryPolicy::default()
            ->withMaxAttempts(5)
            ->withBackoff('linear', 500, 10000)
            ->withJitter(false)
            ->withCredentialRotation(false)
            ->withProviderFallback('openai', ['model' => 'gpt-4o']);

        $this->assertEquals(5, $policy->getMaxAttempts());
        $this->assertEquals('linear', $policy->getBackoffType());
        $this->assertEquals(500, $policy->getBaseDelayMs());
        $this->assertEquals(10000, $policy->getMaxDelayMs());
        $this->assertFalse($policy->hasJitter());
        $this->assertFalse($policy->isCredentialRotationEnabled());
        $this->assertTrue($policy->isProviderFallbackEnabled());
    }

    public function testRetryPolicyShouldRetryRateLimit(): void
    {
        $policy = AgentRetryPolicy::default();

        $rateLimitError = new \RuntimeException('Rate limit exceeded', 429);
        $this->assertTrue($policy->shouldRetry(1, $rateLimitError));
        $this->assertTrue($policy->shouldRetry(2, $rateLimitError));
        $this->assertFalse($policy->shouldRetry(3, $rateLimitError)); // max attempts reached
    }

    public function testRetryPolicyShouldRetryServerError(): void
    {
        $policy = AgentRetryPolicy::default();

        $serverError = new \RuntimeException('Internal server error', 500);
        $this->assertTrue($policy->shouldRetry(1, $serverError));

        $badGateway = new \RuntimeException('Bad gateway', 502);
        $this->assertTrue($policy->shouldRetry(1, $badGateway));
    }

    public function testRetryPolicyShouldNotRetryAuthError(): void
    {
        $policy = AgentRetryPolicy::default();

        $authError = new \RuntimeException('Unauthorized', 401);
        $this->assertFalse($policy->shouldRetry(1, $authError));

        $forbidden = new \RuntimeException('Forbidden', 403);
        $this->assertFalse($policy->shouldRetry(1, $forbidden));
    }

    public function testRetryPolicyShouldNotRetryProgrammingError(): void
    {
        $policy = AgentRetryPolicy::default();

        $typeError = new \TypeError('Bad type');
        $this->assertFalse($policy->shouldRetry(1, $typeError));

        $logicError = new \LogicException('Logic error');
        $this->assertFalse($policy->shouldRetry(1, $logicError));
    }

    public function testRetryPolicyShouldRetryNetworkErrors(): void
    {
        $policy = AgentRetryPolicy::default();

        $connError = new \RuntimeException('Connection refused', 0);
        $this->assertTrue($policy->shouldRetry(1, $connError));

        $timeoutError = new \RuntimeException('Request timeout', 0);
        $this->assertTrue($policy->shouldRetry(1, $timeoutError));
    }

    public function testRetryPolicyDelayCalculation(): void
    {
        // Exponential without jitter
        $policy = AgentRetryPolicy::default()->withJitter(false);

        $this->assertEquals(1000, $policy->getDelayMs(1));   // 1000 * 2^0
        $this->assertEquals(2000, $policy->getDelayMs(2));   // 1000 * 2^1
        $this->assertEquals(4000, $policy->getDelayMs(3));   // 1000 * 2^2

        // Linear
        $linear = (new AgentRetryPolicy(backoffType: 'linear', baseDelayMs: 1000))->withJitter(false);
        $this->assertEquals(1000, $linear->getDelayMs(1));
        $this->assertEquals(2000, $linear->getDelayMs(2));
        $this->assertEquals(3000, $linear->getDelayMs(3));

        // Fixed
        $fixed = (new AgentRetryPolicy(backoffType: 'fixed', baseDelayMs: 500))->withJitter(false);
        $this->assertEquals(500, $fixed->getDelayMs(1));
        $this->assertEquals(500, $fixed->getDelayMs(2));

        // None
        $none = (new AgentRetryPolicy(backoffType: 'none'))->withJitter(false);
        $this->assertEquals(0, $none->getDelayMs(1));
    }

    public function testRetryPolicyDelayRespectsCap(): void
    {
        $policy = (new AgentRetryPolicy(
            baseDelayMs: 10000,
            maxDelayMs: 15000,
        ))->withJitter(false);

        // 10000 * 2^2 = 40000, but capped at 15000
        $this->assertEquals(15000, $policy->getDelayMs(3));
    }

    public function testRetryPolicyDelayWithJitter(): void
    {
        $policy = new AgentRetryPolicy(baseDelayMs: 1000, jitter: true);

        $delay = $policy->getDelayMs(1);
        // Base is 1000, jitter adds 0-25% = 0-250
        $this->assertGreaterThanOrEqual(1000, $delay);
        $this->assertLessThanOrEqual(1250, $delay);
    }

    public function testRetryPolicyCredentialRotation(): void
    {
        $policy = AgentRetryPolicy::default();

        $rateLimitError = new \RuntimeException('Rate limit exceeded', 429);
        $this->assertTrue($policy->shouldRotateCredential($rateLimitError));

        $serverError = new \RuntimeException('Server error', 500);
        $this->assertFalse($policy->shouldRotateCredential($serverError));

        // Disabled
        $noRotation = AgentRetryPolicy::default()->withCredentialRotation(false);
        $this->assertFalse($noRotation->shouldRotateCredential($rateLimitError));
    }

    public function testRetryPolicyProviderSwitch(): void
    {
        $policy = AgentRetryPolicy::crossProvider(['openai', 'ollama']);

        // Auth error → switch immediately
        $authError = new \RuntimeException('Invalid API key', 401);
        $this->assertTrue($policy->shouldSwitchProvider(1, $authError));

        // Server error → switch after attempt 2+
        $serverError = new \RuntimeException('Server error', 500);
        $this->assertFalse($policy->shouldSwitchProvider(1, $serverError));
        $this->assertTrue($policy->shouldSwitchProvider(2, $serverError));

        // Get fallback providers
        $this->assertEquals('openai', $policy->getNextFallbackProvider(0));
        $this->assertEquals('ollama', $policy->getNextFallbackProvider(1));
        $this->assertNull($policy->getNextFallbackProvider(2));
    }

    public function testRetryPolicyErrorClassification(): void
    {
        $policy = AgentRetryPolicy::default();

        $this->assertEquals('auth', $policy->classifyError(new \RuntimeException('', 401)));
        $this->assertEquals('auth', $policy->classifyError(new \RuntimeException('', 403)));
        $this->assertEquals('rate_limit', $policy->classifyError(new \RuntimeException('', 429)));
        $this->assertEquals('server', $policy->classifyError(new \RuntimeException('', 500)));
        $this->assertEquals('server', $policy->classifyError(new \RuntimeException('', 503)));
        $this->assertEquals('timeout', $policy->classifyError(new \RuntimeException('request timeout', 0)));
        $this->assertEquals('network', $policy->classifyError(new \RuntimeException('connection refused', 0)));
        $this->assertEquals('unrecoverable', $policy->classifyError(new \TypeError('bad type')));
    }

    public function testRetryPolicyToArray(): void
    {
        $policy = AgentRetryPolicy::default()
            ->withProviderFallback('openai');

        $array = $policy->toArray();

        $this->assertEquals(3, $array['max_attempts']);
        $this->assertEquals('exponential', $array['backoff_type']);
        $this->assertTrue($array['rotate_credential_on_rate_limit']);
        $this->assertTrue($array['switch_provider_on_failure']);
        $this->assertEquals(['openai'], $array['fallback_providers']);
    }

    // ─── Phase provider/retry integration ───

    public function testPhaseWithProvider(): void
    {
        $providerConfig = AgentProviderConfig::sameProvider('anthropic');
        $phase = new CollaborationPhase('test');
        $phase->withProvider($providerConfig);

        $this->assertSame($providerConfig, $phase->getProviderConfig());
        $this->assertSame($providerConfig, $phase->getProviderConfigFor('any-agent'));
    }

    public function testPhaseWithProviderName(): void
    {
        $phase = new CollaborationPhase('test');
        $phase->withProviderName('openai', ['model' => 'gpt-4o']);

        $config = $phase->getProviderConfig();
        $this->assertNotNull($config);
        $this->assertEquals('openai', $config->getProviderName());
    }

    public function testPhasePerAgentProviderOverride(): void
    {
        $defaultConfig = AgentProviderConfig::sameProvider('anthropic');
        $openaiConfig = AgentProviderConfig::crossProvider('openai', ['model' => 'gpt-4o']);

        $phase = new CollaborationPhase('test');
        $phase->withProvider($defaultConfig);
        $phase->withAgentProvider('reviewer', $openaiConfig);

        // Default agent gets phase-level config
        $this->assertSame($defaultConfig, $phase->getProviderConfigFor('researcher'));

        // Specific agent gets its override
        $this->assertSame($openaiConfig, $phase->getProviderConfigFor('reviewer'));
    }

    public function testPhaseWithRetryPolicy(): void
    {
        $policy = AgentRetryPolicy::aggressive();
        $phase = new CollaborationPhase('test');
        $phase->withRetryPolicy($policy);

        $this->assertSame($policy, $phase->getRetryPolicy());
        $this->assertSame($policy, $phase->getRetryPolicyFor('any-agent'));
    }

    public function testPhasePerAgentRetryOverride(): void
    {
        $defaultPolicy = AgentRetryPolicy::default();
        $aggressivePolicy = AgentRetryPolicy::aggressive();

        $phase = new CollaborationPhase('test');
        $phase->withRetryPolicy($defaultPolicy);
        $phase->withAgentRetryPolicy('critical-agent', $aggressivePolicy);

        $this->assertSame($defaultPolicy, $phase->getRetryPolicyFor('normal-agent'));
        $this->assertSame($aggressivePolicy, $phase->getRetryPolicyFor('critical-agent'));
    }

    public function testPhaseDefaultRetryPolicyWhenNotSet(): void
    {
        $phase = new CollaborationPhase('test');

        // Should return a default policy
        $policy = $phase->getRetryPolicyFor('any-agent');
        $this->assertInstanceOf(AgentRetryPolicy::class, $policy);
        $this->assertEquals(3, $policy->getMaxAttempts());
    }

    public function testPhaseWithCredentialPool(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1');

        $phase = new CollaborationPhase('test');
        $phase->withCredentialPool($pool);

        $config = $phase->getProviderConfig();
        $this->assertNotNull($config);
        $this->assertSame($pool, $config->getCredentialPool());
    }

    // ─── Pipeline provider/retry defaults ───

    public function testPipelineDefaultProvider(): void
    {
        $providerConfig = AgentProviderConfig::sameProvider('anthropic');

        $pipeline = CollaborationPipeline::create()
            ->withDefaultProvider($providerConfig)
            ->phase('test', function (CollaborationPhase $phase) {});

        $this->assertSame($providerConfig, $pipeline->getDefaultProviderConfig());
        // Phase should inherit default
        $this->assertSame($providerConfig, $pipeline->getPhase('test')->getProviderConfig());
    }

    public function testPipelineDefaultProviderName(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->withDefaultProviderName('openai', ['model' => 'gpt-4o'])
            ->phase('test', function (CollaborationPhase $phase) {});

        $config = $pipeline->getDefaultProviderConfig();
        $this->assertNotNull($config);
        $this->assertEquals('openai', $config->getProviderName());
    }

    public function testPipelineDefaultRetryPolicy(): void
    {
        $policy = AgentRetryPolicy::aggressive();

        $pipeline = CollaborationPipeline::create()
            ->withDefaultRetryPolicy($policy)
            ->phase('test', function (CollaborationPhase $phase) {});

        $this->assertSame($policy, $pipeline->getDefaultRetryPolicy());
        // Phase should inherit
        $this->assertSame($policy, $pipeline->getPhase('test')->getRetryPolicy());
    }

    public function testPipelinePhaseOverridesDefault(): void
    {
        $defaultConfig = AgentProviderConfig::sameProvider('anthropic');
        $phaseConfig = AgentProviderConfig::crossProvider('openai');

        $pipeline = CollaborationPipeline::create()
            ->withDefaultProvider($defaultConfig)
            ->phase('override', function (CollaborationPhase $phase) use ($phaseConfig) {
                // Phase overrides the default
                $phase->withProvider($phaseConfig);
            });

        // Phase should use its own override, not the pipeline default
        $this->assertSame($phaseConfig, $pipeline->getPhase('override')->getProviderConfig());
    }

    public function testPipelineCredentialPool(): void
    {
        $pool = new CredentialPool();
        $pool->addCredential('anthropic', 'key-1');
        $pool->addCredential('anthropic', 'key-2');

        $pipeline = CollaborationPipeline::create()
            ->withCredentialPool($pool)
            ->phase('test', function (CollaborationPhase $phase) {});

        $config = $pipeline->getDefaultProviderConfig();
        $this->assertNotNull($config);
        $this->assertSame($pool, $config->getCredentialPool());
    }

    public function testPipelineAddPhaseInheritsDefaults(): void
    {
        $providerConfig = AgentProviderConfig::sameProvider('anthropic');
        $retryPolicy = AgentRetryPolicy::aggressive();

        $phase = new CollaborationPhase('added');

        $pipeline = CollaborationPipeline::create()
            ->withDefaultProvider($providerConfig)
            ->withDefaultRetryPolicy($retryPolicy)
            ->addPhase($phase);

        // Phase added via addPhase() should also inherit defaults
        $this->assertSame($providerConfig, $pipeline->getPhase('added')->getProviderConfig());
        $this->assertSame($retryPolicy, $pipeline->getPhase('added')->getRetryPolicy());
    }

    // ─── Cross-provider pipeline scenarios ───

    public function testCrossProviderPipelineConfig(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->phase('research', function (CollaborationPhase $phase) {
                // Agent 1: Anthropic for deep research
                $phase->withAgentProvider('deep-researcher',
                    AgentProviderConfig::crossProvider('anthropic', ['model' => 'claude-opus-4-6'])
                );
                // Agent 2: OpenAI for broad search
                $phase->withAgentProvider('broad-researcher',
                    AgentProviderConfig::crossProvider('openai', ['model' => 'gpt-4o'])
                );
                $phase->addAgent(new AgentSpawnConfig(name: 'deep-researcher', prompt: 'Deep research'));
                $phase->addAgent(new AgentSpawnConfig(name: 'broad-researcher', prompt: 'Broad research'));
            });

        $phase = $pipeline->getPhase('research');
        $deepConfig = $phase->getProviderConfigFor('deep-researcher');
        $broadConfig = $phase->getProviderConfigFor('broad-researcher');

        $this->assertEquals('anthropic', $deepConfig->getProviderName());
        $this->assertEquals('openai', $broadConfig->getProviderName());
    }

    public function testRetryWithProviderFallbackConfig(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->withDefaultRetryPolicy(
                AgentRetryPolicy::default()
                    ->withProviderFallback('openai', ['model' => 'gpt-4o'])
                    ->withProviderFallback('ollama')
            )
            ->phase('test', function (CollaborationPhase $phase) {});

        $policy = $pipeline->getPhase('test')->getRetryPolicyFor('any');
        $this->assertTrue($policy->isProviderFallbackEnabled());
        $this->assertEquals(['openai', 'ollama'], $policy->getFallbackProviders());
        $this->assertEquals(['model' => 'gpt-4o'], $policy->getFallbackProviderConfig('openai'));
    }

    // ─── Executor retry log ───

    public function testExecutorRetryLogEmpty(): void
    {
        $executor = new ParallelPhaseExecutor();
        $phase = new CollaborationPhase('empty');
        $executor->execute($phase);

        $this->assertEmpty($executor->getRetryLog());
    }

    // ─── Phase context injection ───

    public function testPhaseContextInjectionEnabledByDefault(): void
    {
        $phase = new CollaborationPhase('test');
        $this->assertTrue($phase->isContextInjectionEnabled());
        $this->assertNotNull($phase->getContextInjector());
    }

    public function testPhaseContextInjectionCanBeDisabled(): void
    {
        $phase = new CollaborationPhase('test');
        $phase->withoutContextInjection();
        $this->assertFalse($phase->isContextInjectionEnabled());
        $this->assertNull($phase->getContextInjector());
    }

    public function testPhaseContextInjectionCustomConfig(): void
    {
        $phase = new CollaborationPhase('test');
        $phase->withContextInjection(
            maxTokensPerPhase: 1000,
            maxTotalTokens: 4000,
            strategy: 'full',
        );

        $injector = $phase->getContextInjector();
        $this->assertNotNull($injector);
        $this->assertEquals(1000, $injector->getMaxSummaryTokens());
        $this->assertEquals(4000, $injector->getMaxTotalTokens());
        $this->assertEquals('full', $injector->getStrategy());
    }

    // ─── Auto-routing ───

    public function testPhaseAutoRoutingDisabledByDefault(): void
    {
        $phase = new CollaborationPhase('test');
        $this->assertFalse($phase->isAutoRoutingEnabled());
        $this->assertNull($phase->getTaskRouter());
    }

    public function testPhaseWithAutoRouting(): void
    {
        $phase = new CollaborationPhase('test');
        $phase->withAutoRouting();
        $this->assertTrue($phase->isAutoRoutingEnabled());
        $this->assertNotNull($phase->getTaskRouter());
    }

    public function testPhaseAutoRoutingRoutesResearchToHaiku(): void
    {
        $phase = new CollaborationPhase('test');
        $phase->withAutoRouting();
        $phase->addAgent(new AgentSpawnConfig(name: 'searcher', prompt: 'Research the latest API docs'));

        $config = $phase->getProviderConfigFor('searcher');
        $this->assertNotNull($config);
        $this->assertEquals('anthropic', $config->getProviderName());
        // Research task → Tier 3 → Haiku
        $this->assertEquals('claude-haiku-4', $config->getConfig()['model']);
    }

    public function testPhaseAutoRoutingExplicitOverrideWins(): void
    {
        $phase = new CollaborationPhase('test');
        $phase->withAutoRouting();
        $explicitConfig = AgentProviderConfig::crossProvider('openai', ['model' => 'gpt-4o']);
        $phase->withAgentProvider('special', $explicitConfig);
        $phase->addAgent(new AgentSpawnConfig(name: 'special', prompt: 'Research something'));

        // Explicit override should win over auto-routing
        $this->assertSame($explicitConfig, $phase->getProviderConfigFor('special'));
    }

    public function testPipelineAutoRoutingPropagates(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->withAutoRouting()
            ->phase('test', function (CollaborationPhase $phase) {
                $phase->addAgent(new AgentSpawnConfig(name: 'a', prompt: 'Do stuff'));
            });

        $this->assertTrue($pipeline->isAutoRoutingEnabled());
        $this->assertTrue($pipeline->getPhase('test')->isAutoRoutingEnabled());
    }

    public function testPipelineAutoRoutingDoesNotOverridePhaseRouting(): void
    {
        $customRouter = TaskRouter::fromConfig([
            'tier_models' => [
                1 => ['provider' => 'openai', 'model' => 'gpt-4o'],
                2 => ['provider' => 'openai', 'model' => 'gpt-4o'],
                3 => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            ],
        ]);

        $pipeline = CollaborationPipeline::create()
            ->withAutoRouting()
            ->phase('custom', function (CollaborationPhase $phase) use ($customRouter) {
                $phase->withAutoRouting($customRouter); // Phase has its own router
            });

        // Phase's custom router should be preserved
        $this->assertSame($customRouter, $pipeline->getPhase('custom')->getTaskRouter());
    }

    // ─── New task types ───

    public function testNewTaskTypeConstants(): void
    {
        $this->assertEquals('synthesis', TaskProfile::TYPE_SYNTHESIS);
        $this->assertEquals('research', TaskProfile::TYPE_RESEARCH);
        $this->assertEquals('coordination', TaskProfile::TYPE_COORDINATION);
    }
}
