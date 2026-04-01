<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Permissions\BashCommandClassifier;
use SuperAgent\Permissions\PermissionBehavior;
use SuperAgent\Permissions\PermissionCallbackInterface;
use SuperAgent\Permissions\PermissionContext;
use SuperAgent\Permissions\PermissionDecision;
use SuperAgent\Permissions\PermissionDenialTracker;
use SuperAgent\Permissions\PermissionEngine;
use SuperAgent\Permissions\PermissionMode;
use SuperAgent\Permissions\PermissionRule;
use SuperAgent\Permissions\PermissionRuleParser;
use SuperAgent\Permissions\PermissionRuleSource;
use SuperAgent\Permissions\PermissionRuleValue;
use SuperAgent\Tools\Tool;

class Phase3PermissionsTest extends TestCase
{
    public function testPermissionRuleParser(): void
    {
        $parser = new PermissionRuleParser();
        
        // Tool-wide rule
        $rule1 = $parser->parse('Bash');
        $this->assertEquals('Bash', $rule1->toolName);
        $this->assertNull($rule1->ruleContent);
        
        // Content-specific rule
        $rule2 = $parser->parse('Bash(npm install)');
        $this->assertEquals('Bash', $rule2->toolName);
        $this->assertEquals('npm install', $rule2->ruleContent);
        
        // Wildcard rule
        $rule3 = $parser->parse('Bash(git*)');
        $this->assertEquals('Bash', $rule3->toolName);
        $this->assertEquals('git*', $rule3->ruleContent);
        
        // Escaped parentheses
        $rule4 = $parser->parse('Bash(echo \(test\))');
        $this->assertEquals('Bash', $rule4->toolName);
        $this->assertEquals('echo (test)', $rule4->ruleContent);
        
        // Legacy format conversion
        $rule5 = $parser->parse('Bash(npm:*)');
        $this->assertEquals('Bash', $rule5->toolName);
        $this->assertEquals('npm*', $rule5->ruleContent);
    }
    
    public function testPermissionRuleMatching(): void
    {
        $rule1 = new PermissionRule(
            PermissionRuleSource::SETTINGS,
            PermissionBehavior::ALLOW,
            new PermissionRuleValue('Bash'),
        );
        
        $this->assertTrue($rule1->matches('Bash', 'any command'));
        $this->assertTrue($rule1->matches('Bash', null));
        $this->assertFalse($rule1->matches('Read', 'file.txt'));
        
        $rule2 = new PermissionRule(
            PermissionRuleSource::SETTINGS,
            PermissionBehavior::ALLOW,
            new PermissionRuleValue('Bash', 'git*'),
        );
        
        $this->assertTrue($rule2->matches('Bash', 'git status'));
        $this->assertTrue($rule2->matches('Bash', 'git commit -m "test"'));
        $this->assertFalse($rule2->matches('Bash', 'npm install'));
        $this->assertFalse($rule2->matches('Bash', null));
    }
    
    public function testBashCommandClassifier(): void
    {
        $classifier = new BashCommandClassifier();
        
        // Safe commands
        $safe = $classifier->classify('git status');
        $this->assertEquals('low', $safe->risk);
        $this->assertEquals('safe', $safe->category);
        $this->assertFalse($safe->isHighRisk());
        
        // Dangerous commands
        $dangerous = $classifier->classify('rm -rf /');
        $this->assertTrue($dangerous->isHighRisk());
        $this->assertTrue($dangerous->requiresApproval());
        
        // Complex commands
        $complex = $classifier->classify('echo "test" | grep pattern && npm install');
        $this->assertTrue($complex->isTooComplex);
        $this->assertEquals('high', $complex->risk);
        
        // Medium risk
        $medium = $classifier->classify('curl https://example.com');
        $this->assertEquals('medium', $medium->risk);
        $this->assertTrue($medium->requiresApproval());
        
        // Sudo commands
        $sudo = $classifier->classify('sudo apt-get update');
        $this->assertEquals('critical', $sudo->risk);
        $this->assertTrue($sudo->isHighRisk());
    }
    
    public function testPermissionModes(): void
    {
        $this->assertEquals('Standard Permissions', PermissionMode::DEFAULT->getTitle());
        $this->assertEquals('🔒', PermissionMode::DEFAULT->getSymbol());
        $this->assertFalse(PermissionMode::DEFAULT->isHeadless());
        
        $this->assertEquals('Bypass Permissions', PermissionMode::BYPASS_PERMISSIONS->getTitle());
        $this->assertEquals('⚠️', PermissionMode::BYPASS_PERMISSIONS->getSymbol());
        $this->assertFalse(PermissionMode::BYPASS_PERMISSIONS->isHeadless());
        
        $this->assertTrue(PermissionMode::DONT_ASK->isHeadless());
        $this->assertTrue(PermissionMode::AUTO->isHeadless());
    }
    
    public function testPermissionContext(): void
    {
        $context = new PermissionContext();
        
        $this->assertEquals(PermissionMode::DEFAULT, $context->mode);
        $this->assertCount(0, $context->alwaysAllowRules);
        $this->assertCount(0, $context->alwaysDenyRules);
        
        $rule = new PermissionRule(
            PermissionRuleSource::RUNTIME,
            PermissionBehavior::ALLOW,
            new PermissionRuleValue('Bash', 'git*'),
        );
        
        $context->addAllowRule($rule);
        $this->assertCount(1, $context->alwaysAllowRules);
        
        $newContext = $context->withMode(PermissionMode::PLAN);
        $this->assertEquals(PermissionMode::PLAN, $newContext->mode);
        $this->assertCount(1, $newContext->alwaysAllowRules);
    }
    
    public function testPermissionDenialTracker(): void
    {
        $tracker = new PermissionDenialTracker();
        
        $this->assertEquals(0, $tracker->getRecentDenialCount('Bash'));
        $this->assertFalse($tracker->isCircuitBreakerOpen('Bash'));
        
        // Record denials
        for ($i = 0; $i < 3; $i++) {
            $tracker->recordDenial('Bash', 'test-reason');
        }
        
        $this->assertEquals(3, $tracker->getRecentDenialCount('Bash'));
        $this->assertFalse($tracker->isCircuitBreakerOpen('Bash'));
        
        // Trigger circuit breaker
        for ($i = 0; $i < 3; $i++) {
            $tracker->recordDenial('Bash', 'test-reason');
        }
        
        $this->assertTrue($tracker->isCircuitBreakerOpen('Bash'));
        
        $status = $tracker->getCircuitBreakerStatus('Bash');
        $this->assertEquals('open', $status['status']);
        $this->assertGreaterThan(0, $status['cooldown_remaining']);
        
        // Reset circuit breaker
        $tracker->resetCircuitBreaker('Bash');
        $this->assertFalse($tracker->isCircuitBreakerOpen('Bash'));
        $this->assertEquals(0, $tracker->getRecentDenialCount('Bash'));
    }
    
    public function testPermissionEngine(): void
    {
        $callback = $this->createMock(PermissionCallbackInterface::class);
        $context = new PermissionContext(PermissionMode::DEFAULT);
        $engine = new PermissionEngine($callback, $context);
        
        $tool = $this->createMock(Tool::class);
        $tool->method('getName')->willReturn('Bash');
        $tool->method('requiresUserInteraction')->willReturn(false);
        
        // Test with no rules - should ask
        $decision = $engine->checkPermission($tool, ['command' => 'ls -la']);
        $this->assertEquals(PermissionBehavior::ASK, $decision->behavior);
        
        // Add allow rule
        $context->addAllowRule(new PermissionRule(
            PermissionRuleSource::SETTINGS,
            PermissionBehavior::ALLOW,
            new PermissionRuleValue('Bash', 'ls*'),
        ));
        
        $decision = $engine->checkPermission($tool, ['command' => 'ls -la']);
        $this->assertEquals(PermissionBehavior::ALLOW, $decision->behavior);
        
        // Test deny rule
        $context->addDenyRule(new PermissionRule(
            PermissionRuleSource::SETTINGS,
            PermissionBehavior::DENY,
            new PermissionRuleValue('Bash', 'rm*'),
        ));
        
        $decision = $engine->checkPermission($tool, ['command' => 'rm -rf /']);
        $this->assertEquals(PermissionBehavior::DENY, $decision->behavior);
        
        // Test bypass mode
        $bypassContext = $context->withMode(PermissionMode::BYPASS_PERMISSIONS);
        $engine->setContext($bypassContext);
        
        $decision = $engine->checkPermission($tool, ['command' => 'dangerous command']);
        $this->assertEquals(PermissionBehavior::ALLOW, $decision->behavior);
    }
    
    public function testPermissionEngineWithDontAskMode(): void
    {
        $callback = $this->createMock(PermissionCallbackInterface::class);
        $context = new PermissionContext(PermissionMode::DONT_ASK);
        $engine = new PermissionEngine($callback, $context);
        
        $tool = $this->createMock(Tool::class);
        $tool->method('getName')->willReturn('Read');
        $tool->method('requiresUserInteraction')->willReturn(false);
        
        // In DONT_ASK mode, ASK decisions should become DENY
        $decision = $engine->checkPermission($tool, ['file_path' => '/some/file.txt']);
        $this->assertEquals(PermissionBehavior::DENY, $decision->behavior);
    }
    
    public function testPermissionEngineWithPlanMode(): void
    {
        $callback = $this->createMock(PermissionCallbackInterface::class);
        $context = new PermissionContext(PermissionMode::PLAN);
        
        // Add an allow rule
        $context->addAllowRule(new PermissionRule(
            PermissionRuleSource::SETTINGS,
            PermissionBehavior::ALLOW,
            new PermissionRuleValue('Read'),
        ));
        
        $engine = new PermissionEngine($callback, $context);
        
        $tool = $this->createMock(Tool::class);
        $tool->method('getName')->willReturn('Read');
        $tool->method('requiresUserInteraction')->willReturn(false);
        
        // In PLAN mode, even ALLOW decisions should become ASK
        $decision = $engine->checkPermission($tool, ['file_path' => '/some/file.txt']);
        $this->assertEquals(PermissionBehavior::ASK, $decision->behavior);
    }
    
    public function testDangerousPathDetection(): void
    {
        $callback = $this->createMock(PermissionCallbackInterface::class);
        $context = new PermissionContext(PermissionMode::DEFAULT);
        $engine = new PermissionEngine($callback, $context);
        
        $tool = $this->createMock(Tool::class);
        $tool->method('getName')->willReturn('Read');
        $tool->method('requiresUserInteraction')->willReturn(false);
        
        // Test dangerous paths
        $dangerousPaths = [
            '.git/config',
            '.env',
            '.ssh/id_rsa',
            '/etc/passwd',
            'credentials.json',
        ];
        
        foreach ($dangerousPaths as $path) {
            $decision = $engine->checkPermission($tool, ['file_path' => $path]);
            $this->assertEquals(PermissionBehavior::ASK, $decision->behavior);
            $this->assertEquals('dangerous-path', $decision->decisionReason?->type);
        }
    }
    
    public function testAcceptEditsMode(): void
    {
        $callback = $this->createMock(PermissionCallbackInterface::class);
        $context = new PermissionContext(PermissionMode::ACCEPT_EDITS);
        $engine = new PermissionEngine($callback, $context);
        
        $editTool = $this->createMock(Tool::class);
        $editTool->method('getName')->willReturn('Edit');
        $editTool->method('requiresUserInteraction')->willReturn(false);
        
        // Edit tools should be auto-allowed in ACCEPT_EDITS mode
        $decision = $engine->checkPermission($editTool, ['file_path' => 'src/file.php']);
        $this->assertEquals(PermissionBehavior::ALLOW, $decision->behavior);
        
        $bashTool = $this->createMock(Tool::class);
        $bashTool->method('getName')->willReturn('Bash');
        $bashTool->method('requiresUserInteraction')->willReturn(false);
        
        // Non-edit tools should still ask
        $decision = $engine->checkPermission($bashTool, ['command' => 'ls']);
        $this->assertEquals(PermissionBehavior::ASK, $decision->behavior);
    }
}