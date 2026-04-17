<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent\AgentManager;
use SuperAgent\Agent\MarkdownAgentDefinition;
use SuperAgent\Skills\MarkdownSkill;
use SuperAgent\Skills\SkillManager;
use SuperAgent\Support\MarkdownFrontmatter;

class MarkdownLoadingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        AgentManager::reset();
        SkillManager::reset();
        $this->tempDir = sys_get_temp_dir() . '/superagent_md_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        AgentManager::reset();
        SkillManager::reset();
    }

    // --- MarkdownFrontmatter parser ---

    public function test_parse_frontmatter_and_body(): void
    {
        $content = <<<'MD'
---
name: test-agent
description: "A test agent"
model: inherit
---

# Test Agent

You are a test agent.
MD;

        $parsed = MarkdownFrontmatter::parse($content);

        $this->assertEquals('test-agent', $parsed['frontmatter']['name']);
        $this->assertEquals('A test agent', $parsed['frontmatter']['description']);
        $this->assertEquals('inherit', $parsed['frontmatter']['model']);
        $this->assertStringContains('# Test Agent', $parsed['body']);
        $this->assertStringContains('You are a test agent.', $parsed['body']);
    }

    public function test_parse_frontmatter_with_list(): void
    {
        $content = <<<'MD'
---
name: restricted
allowed_tools:
  - read_file
  - write_file
  - bash
---

Body here.
MD;

        $parsed = MarkdownFrontmatter::parse($content);

        $this->assertEquals(['read_file', 'write_file', 'bash'], $parsed['frontmatter']['allowed_tools']);
    }

    public function test_parse_no_frontmatter(): void
    {
        $content = "# Just a markdown file\n\nNo frontmatter here.";
        $parsed = MarkdownFrontmatter::parse($content);

        $this->assertEmpty($parsed['frontmatter']);
        $this->assertStringContains('Just a markdown file', $parsed['body']);
    }

    // --- Agent Markdown loading ---

    public function test_load_agent_from_md_file(): void
    {
        $md = <<<'MD'
---
name: ai-advisor
description: "AI Strategy Advisor"
model: inherit
---

# AI Strategy Agent

You are an AI strategy advisor.
MD;
        file_put_contents($this->tempDir . '/ai-advisor.md', $md);

        $manager = AgentManager::getInstance();
        $manager->loadFromFile($this->tempDir . '/ai-advisor.md');

        $agent = $manager->get('ai-advisor');
        $this->assertNotNull($agent);
        $this->assertInstanceOf(MarkdownAgentDefinition::class, $agent);
        $this->assertEquals('ai-advisor', $agent->name());
        $this->assertEquals('AI Strategy Advisor', $agent->description());
        $this->assertNull($agent->model()); // inherit -> null
        $this->assertStringContains('AI strategy advisor', $agent->systemPrompt());
    }

    public function test_load_agent_md_with_allowed_tools(): void
    {
        $md = <<<'MD'
---
name: restricted-agent
description: "Limited agent"
allowed_tools:
  - read_file
  - grep
---

You can only read files.
MD;
        file_put_contents($this->tempDir . '/restricted-agent.md', $md);

        $manager = AgentManager::getInstance();
        $manager->loadFromFile($this->tempDir . '/restricted-agent.md');

        $agent = $manager->get('restricted-agent');
        $this->assertEquals(['read_file', 'grep'], $agent->allowedTools());
    }

    public function test_agent_md_system_prompt_preserves_placeholders(): void
    {
        $md = <<<'MD'
---
name: task-agent
description: "Task agent"
---

## Input

$ARGUMENTS

## Language

Output in $LANGUAGE.
MD;
        file_put_contents($this->tempDir . '/task-agent.md', $md);

        $manager = AgentManager::getInstance();
        $manager->loadFromFile($this->tempDir . '/task-agent.md');

        $agent = $manager->get('task-agent');
        $this->assertInstanceOf(MarkdownAgentDefinition::class, $agent);

        // Placeholders are preserved for LLM to interpret
        $prompt = $agent->systemPrompt();
        $this->assertStringContains('$ARGUMENTS', $prompt);
        $this->assertStringContains('$LANGUAGE', $prompt);
    }

    public function test_agent_md_get_meta_arbitrary_fields(): void
    {
        $md = <<<'MD'
---
name: custom-agent
description: "Custom"
argument-hint: "<project-name> <context>"
disable-model-invocation: true
custom_field: hello
---

Body.
MD;
        file_put_contents($this->tempDir . '/custom-agent.md', $md);

        $manager = AgentManager::getInstance();
        $manager->loadFromFile($this->tempDir . '/custom-agent.md');

        $agent = $manager->get('custom-agent');
        $this->assertEquals('<project-name> <context>', $agent->getMeta('argument-hint'));
        $this->assertTrue($agent->getMeta('disable-model-invocation'));
        $this->assertEquals('hello', $agent->getMeta('custom_field'));
        $this->assertNull($agent->getMeta('nonexistent'));
    }

    // --- Skill Markdown loading ---

    public function test_load_skill_from_md_file(): void
    {
        $md = <<<'MD'
---
name: biznet
description: "Business networking strategy"
category: business
argument-hint: "<project-name> <business context>"
---

# Business Networking Strategy

You are the networking orchestrator.

## Input

$ARGUMENTS
MD;
        file_put_contents($this->tempDir . '/biznet.md', $md);

        $manager = SkillManager::getInstance();
        $manager->loadFromFile($this->tempDir . '/biznet.md');

        $skill = $manager->get('biznet');
        $this->assertNotNull($skill);
        $this->assertInstanceOf(MarkdownSkill::class, $skill);
        $this->assertEquals('biznet', $skill->name());
        $this->assertEquals('business', $skill->category());
        $this->assertStringContains('networking orchestrator', $skill->template());
    }

    public function test_skill_md_execute_preserves_placeholders(): void
    {
        $md = <<<'MD'
---
name: translate
description: "Translate content"
---

Translate the following to $LANGUAGE:

$ARGUMENTS
MD;
        file_put_contents($this->tempDir . '/translate.md', $md);

        $manager = SkillManager::getInstance();
        $manager->loadFromFile($this->tempDir . '/translate.md');

        // Template is returned as-is, placeholders preserved for LLM
        $result = $manager->execute('translate', []);

        $this->assertStringContains('$LANGUAGE', $result);
        $this->assertStringContains('$ARGUMENTS', $result);
    }

    public function test_skill_md_get_meta(): void
    {
        $md = <<<'MD'
---
name: meta-skill
description: "Skill with extra meta"
argument-hint: "<context>"
disable-model-invocation: true
---

Template body.
MD;
        file_put_contents($this->tempDir . '/meta-skill.md', $md);

        $manager = SkillManager::getInstance();
        $manager->loadFromFile($this->tempDir . '/meta-skill.md');

        $skill = $manager->get('meta-skill');
        $this->assertEquals('<context>', $skill->getMeta('argument-hint'));
        $this->assertTrue($skill->getMeta('disable-model-invocation'));
    }

    // --- Mixed directory loading (PHP + MD) ---

    public function test_agent_directory_loads_both_php_and_md(): void
    {
        // Write a PHP agent
        file_put_contents($this->tempDir . '/MixPhpAgent.php', <<<'PHP'
<?php
namespace TestMixAgent;

use SuperAgent\Agent\AgentDefinition;

class MixPhpAgent extends AgentDefinition
{
    public function name(): string { return 'mix-php-agent'; }
    public function description(): string { return 'PHP agent'; }
    public function systemPrompt(): ?string { return 'I am from PHP.'; }
}
PHP);

        // Write an MD agent
        file_put_contents($this->tempDir . '/mix-md-agent.md', <<<'MD'
---
name: mix-md-agent
description: "MD agent"
---

I am from Markdown.
MD);

        $manager = AgentManager::getInstance();
        $manager->loadFromDirectory($this->tempDir);

        $this->assertNotNull($manager->get('mix-php-agent'));
        $this->assertNotNull($manager->get('mix-md-agent'));
        $this->assertStringContains('PHP', $manager->get('mix-php-agent')->systemPrompt());
        $this->assertStringContains('Markdown', $manager->get('mix-md-agent')->systemPrompt());
    }

    public function test_skill_directory_loads_both_php_and_md(): void
    {
        file_put_contents($this->tempDir . '/MixPhpSkill.php', <<<'PHP'
<?php
namespace TestMixSkill;

use SuperAgent\Skills\Skill;

class MixPhpSkill extends Skill
{
    public function name(): string { return 'mix-php-skill'; }
    public function description(): string { return 'PHP skill'; }
    public function template(): string { return 'PHP template'; }
}
PHP);

        file_put_contents($this->tempDir . '/mix-md-skill.md', <<<'MDFILE'
---
name: mix-md-skill
description: "MD skill"
---

Markdown template
MDFILE);

        $manager = SkillManager::getInstance();
        $manager->loadFromDirectory($this->tempDir);

        $this->assertNotNull($manager->get('mix-php-skill'));
        $this->assertNotNull($manager->get('mix-md-skill'));
    }

    // --- Helper ---

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertStringContainsString($needle, $haystack);
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
