<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent\AgentDefinition;
use SuperAgent\Agent\AgentManager;

class AgentManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        AgentManager::reset();
        $this->tempDir = sys_get_temp_dir() . '/superagent_agent_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        AgentManager::reset();
    }

    public function test_builtin_agents_are_registered(): void
    {
        $manager = AgentManager::getInstance();

        $this->assertTrue($manager->has('general-purpose'));
        $this->assertTrue($manager->has('code-writer'));
        $this->assertTrue($manager->has('researcher'));
        $this->assertTrue($manager->has('reviewer'));
        $this->assertTrue($manager->has('explore'));
        $this->assertTrue($manager->has('plan'));
        $this->assertTrue($manager->has('verification'));
    }

    public function test_get_builtin_agent(): void
    {
        $manager = AgentManager::getInstance();
        $agent = $manager->get('code-writer');

        $this->assertNotNull($agent);
        $this->assertEquals('code-writer', $agent->name());
        $this->assertNotNull($agent->systemPrompt());
        $this->assertIsArray($agent->allowedTools());
        $this->assertContains('read_file', $agent->allowedTools());
    }

    public function test_general_purpose_allows_all_tools(): void
    {
        $manager = AgentManager::getInstance();
        $agent = $manager->get('general-purpose');

        $this->assertNull($agent->systemPrompt());
        $this->assertNull($agent->allowedTools());
    }

    public function test_get_names_returns_all_registered(): void
    {
        $manager = AgentManager::getInstance();
        $names = $manager->getNames();

        $this->assertContains('general-purpose', $names);
        $this->assertContains('code-writer', $names);
        $this->assertContains('researcher', $names);
        $this->assertContains('reviewer', $names);
        $this->assertContains('explore', $names);
        $this->assertContains('plan', $names);
        $this->assertContains('verification', $names);
    }

    public function test_explore_agent_is_read_only(): void
    {
        $manager = AgentManager::getInstance();
        $agent = $manager->get('explore');

        $this->assertTrue($agent->readOnly());
        $this->assertNotNull($agent->disallowedTools());
        $this->assertContains('write_file', $agent->disallowedTools());
        $this->assertContains('edit_file', $agent->disallowedTools());
        $this->assertStringContainsString('READ-ONLY', $agent->systemPrompt());
    }

    public function test_plan_agent_is_read_only(): void
    {
        $manager = AgentManager::getInstance();
        $agent = $manager->get('plan');

        $this->assertTrue($agent->readOnly());
        $this->assertStringContainsString('Critical Files for Implementation', $agent->systemPrompt());
    }

    public function test_verification_agent_properties(): void
    {
        $manager = AgentManager::getInstance();
        $agent = $manager->get('verification');

        $this->assertTrue($agent->readOnly());
        $this->assertStringContainsString('try to break it', $agent->systemPrompt());
        $this->assertStringContainsString('VERDICT: PASS', $agent->systemPrompt());
        $this->assertStringContainsString('VERDICT: FAIL', $agent->systemPrompt());
        $this->assertStringContainsString('VERDICT: PARTIAL', $agent->systemPrompt());
    }

    public function test_get_nonexistent_returns_null(): void
    {
        $manager = AgentManager::getInstance();
        $this->assertNull($manager->get('nonexistent'));
    }

    public function test_load_from_directory(): void
    {
        $this->writeAgentFile(
            $this->tempDir . '/TranslatorAgent.php',
            'TestAgents',
            'TranslatorAgent',
            'translator',
            'You are a translation specialist.',
        );

        $manager = AgentManager::getInstance();
        $manager->loadFromDirectory($this->tempDir);

        $agent = $manager->get('translator');
        $this->assertNotNull($agent);
        $this->assertEquals('translator', $agent->name());
        $this->assertEquals('You are a translation specialist.', $agent->systemPrompt());
    }

    public function test_load_from_directory_recursive(): void
    {
        $subDir = $this->tempDir . '/sub';
        mkdir($subDir, 0755, true);

        $this->writeAgentFile($this->tempDir . '/TopAgent.php', 'TopNs', 'TopAgent', 'top_agent');
        $this->writeAgentFile($subDir . '/DeepAgent.php', 'TopNs\\Sub', 'DeepAgent', 'deep_agent');

        $manager = AgentManager::getInstance();
        $manager->loadFromDirectory($this->tempDir, recursive: true);

        $this->assertNotNull($manager->get('top_agent'));
        $this->assertNotNull($manager->get('deep_agent'));
    }

    public function test_load_from_file(): void
    {
        $file = $this->tempDir . '/SingleAgent.php';
        $this->writeAgentFile($file, 'Isolated', 'SingleAgent', 'single_agent');

        $manager = AgentManager::getInstance();
        $manager->loadFromFile($file);

        $this->assertNotNull($manager->get('single_agent'));
    }

    public function test_load_from_file_not_found_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent file not found');

        AgentManager::getInstance()->loadFromFile('/nonexistent/FakeAgent.php');
    }

    public function test_load_from_file_not_agent_definition_throws(): void
    {
        $file = $this->tempDir . '/NotAnAgent.php';
        file_put_contents($file, <<<'PHP'
<?php
namespace TestNotAgent;
class NotAnAgent {
    public function name(): string { return 'fake'; }
}
PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not an AgentDefinition subclass');

        AgentManager::getInstance()->loadFromFile($file);
    }

    public function test_duplicate_registration_throws(): void
    {
        $this->writeAgentFile(
            $this->tempDir . '/DuplicateAgent.php',
            'TestDup',
            'DuplicateAgent',
            'general-purpose', // conflicts with builtin
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent already registered: general-purpose');

        $manager = AgentManager::getInstance();
        $manager->loadFromDirectory($this->tempDir);
    }

    public function test_nonexistent_directory_is_noop(): void
    {
        $manager = AgentManager::getInstance();
        $countBefore = count($manager->getAll());

        $manager->loadFromDirectory('/nonexistent/path');

        $this->assertCount($countBefore, $manager->getAll());
    }

    public function test_get_by_category(): void
    {
        $manager = AgentManager::getInstance();
        $devAgents = $manager->getByCategory('development');

        $this->assertArrayHasKey('code-writer', $devAgents);
        $this->assertArrayHasKey('reviewer', $devAgents);
        $this->assertArrayNotHasKey('researcher', $devAgents);
    }

    private function writeAgentFile(
        string $path,
        string $namespace,
        string $className,
        string $agentName,
        string $systemPrompt = 'Test prompt',
    ): void {
        $content = <<<PHP
<?php
namespace {$namespace};

use SuperAgent\Agent\AgentDefinition;

class {$className} extends AgentDefinition
{
    public function name(): string { return '{$agentName}'; }
    public function description(): string { return 'Test agent {$agentName}'; }
    public function systemPrompt(): ?string { return '{$systemPrompt}'; }
}
PHP;
        file_put_contents($path, $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
