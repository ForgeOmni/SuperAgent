<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\AutoMode\TaskAnalyzer;
use SuperAgent\AutoMode\TaskAnalysisResult;
use SuperAgent\AutoMode\AutoModeAgent;
use SuperAgent\Agent;
use Psr\Log\NullLogger;

class AutoModeTest extends TestCase
{
    private TaskAnalyzer $analyzer;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new TaskAnalyzer();
    }
    
    public function testSimpleTaskUseSingleAgent(): void
    {
        $prompt = "What is 2 + 2?";
        $result = $this->analyzer->analyzeTask($prompt);
        
        $this->assertFalse($result->shouldUseMultiAgent());
        $this->assertLessThan(0.3, $result->getComplexityScore());
        $this->assertStringContainsString('Single agent sufficient', $result->getReason());
    }
    
    public function testComplexTaskTriggersMultiAgent(): void
    {
        $prompt = "Please analyze this codebase for security vulnerabilities, " .
                 "generate a comprehensive report with recommendations, " .
                 "create test cases for each vulnerability found, " .
                 "and then implement fixes for critical issues.";
        
        $result = $this->analyzer->analyzeTask($prompt);
        
        $this->assertTrue($result->shouldUseMultiAgent());
        $this->assertGreaterThan(0.5, $result->getComplexityScore());
        $this->assertStringContainsString('Multi-agent mode triggered', $result->getReason());
    }
    
    public function testNumberedTasksDetectedAsSubtasks(): void
    {
        $prompt = "Please help me with the following tasks:\n" .
                 "1. Review the authentication system\n" .
                 "2. Optimize database queries\n" .
                 "3. Write unit tests for the API\n" .
                 "4. Create documentation\n" .
                 "5. Deploy to production";
        
        $result = $this->analyzer->analyzeTask($prompt);
        
        $this->assertTrue($result->shouldUseMultiAgent());
        $metrics = $result->getMetrics();
        $this->assertGreaterThanOrEqual(5, $metrics['subtask_count']);
    }
    
    public function testChineseTaskAnalysis(): void
    {
        $prompt = "请帮我完成以下任务：" .
                 "首先，分析代码质量，" .
                 "然后，找出所有安全漏洞，" .
                 "接着，生成修复方案，" .
                 "最后，创建测试用例。";
        
        $result = $this->analyzer->analyzeTask($prompt);
        
        $this->assertTrue($result->shouldUseMultiAgent());
        $metrics = $result->getMetrics();
        $this->assertGreaterThan(3, $metrics['subtask_count']);
    }
    
    public function testTokenEstimation(): void
    {
        // Short prompt
        $shortPrompt = "Hello";
        $shortResult = $this->analyzer->analyzeTask($shortPrompt);
        $shortMetrics = $shortResult->getMetrics();
        $this->assertLessThan(1000, $shortMetrics['token_estimate']);
        
        // Long complex prompt
        $longPrompt = str_repeat("Analyze this complex system and generate detailed reports. ", 100);
        $longResult = $this->analyzer->analyzeTask($longPrompt);
        $longMetrics = $longResult->getMetrics();
        $this->assertGreaterThan(5000, $longMetrics['token_estimate']);
    }
    
    public function testToolCategoryDetection(): void
    {
        $prompt = "Please read files, analyze the code, run tests, " .
                 "fetch data from the web API, commit changes to git, " .
                 "and deploy the application.";
        
        $result = $this->analyzer->analyzeTask($prompt);
        $metrics = $result->getMetrics();
        
        $this->assertGreaterThanOrEqual(4, $metrics['tool_count']);
    }
    
    public function testSuggestedConfiguration(): void
    {
        $complexPrompt = "1. Analyze code\n2. Write tests\n3. Create docs\n4. Deploy";
        $result = $this->analyzer->analyzeTask($complexPrompt);
        
        $config = $this->analyzer->suggestConfiguration($result);
        
        $this->assertEquals('multi', $config['mode']);
        $this->assertGreaterThanOrEqual(2, $config['agents']);
        $this->assertArrayHasKey('team_structure', $config);
    }
    
    public function testAutoModeAgentSingleMode(): void
    {
        $agent = new AutoModeAgent([
            'auto_mode' => true,
            'analyzer_config' => [
                'threshold' => [
                    'complexity_score' => 0.9, // Very high threshold
                ],
            ],
        ]);
        
        // This would normally run, but we'll skip actual execution in tests
        $this->assertInstanceOf(AutoModeAgent::class, $agent);
    }
    
    public function testAgentWithAutoModeIntegration(): void
    {
        // Test that the main Agent class can use auto-mode
        // Skip this test if no API key is available
        $this->markTestSkipped('Requires API key configuration');
    }
    
    public function testCustomWeightsConfiguration(): void
    {
        $analyzer = new TaskAnalyzer([
            'weights' => [
                'length' => 0.5,
                'keywords' => 0.1,
                'subtasks' => 0.2,
                'tools' => 0.1,
                'tokens' => 0.1,
            ],
        ]);
        
        $prompt = str_repeat("Simple task. ", 100); // Long but simple
        $result = $analyzer->analyzeTask($prompt);
        
        // With high length weight, this should score higher
        $this->assertGreaterThan(0.3, $result->getComplexityScore());
    }
    
    public function testDisabledAutoMode(): void
    {
        $analyzer = new TaskAnalyzer([
            'enabled' => false,
        ]);
        
        $prompt = "Complex multi-step task with many subtasks and requirements";
        $result = $analyzer->analyzeTask($prompt);
        
        $this->assertFalse($result->shouldUseMultiAgent());
        $this->assertEquals('Auto-mode detection is disabled', $result->getReason());
    }
}