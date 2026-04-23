<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent\AgentSpecLoader;
use SuperAgent\Agent\YamlAgentDefinition;

class YamlAgentDefinitionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/superagent-yaml-agent-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_minimal_yaml_spec_is_loaded(): void
    {
        $path = $this->write('reviewer.yaml', <<<YAML
        name: reviewer
        description: Reviews code
        system_prompt: |
          You are a code reviewer. Be thorough.
        allowed_tools: [Read, Grep]
        read_only: true
        YAML);

        $def = YamlAgentDefinition::fromFile($path);
        $this->assertSame('reviewer', $def->name());
        $this->assertSame('Reviews code', $def->description());
        $this->assertStringContainsString('code reviewer', $def->systemPrompt());
        $this->assertTrue($def->readOnly());
        $this->assertIsArray($def->allowedTools());
        // Tool names are normalized to SA canonical form via ToolNameResolver
        // (Read → read_file, Grep → grep). We assert on the canonical name.
        $this->assertContains('read_file', $def->allowedTools());
    }

    public function test_system_prompt_path_is_resolved_relative_to_yaml(): void
    {
        file_put_contents($this->tmpDir . '/prompt.md', 'Hello from a separate file.');
        $path = $this->write('refactor.yaml', <<<YAML
        name: refactor
        system_prompt_path: prompt.md
        YAML);

        $def = YamlAgentDefinition::fromFile($path);
        $this->assertSame('Hello from a separate file.', $def->systemPrompt());
    }

    public function test_missing_name_throws(): void
    {
        $path = $this->write('nameless.yaml', "description: no name\n");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/`name`/');
        YamlAgentDefinition::fromFile($path);
    }

    public function test_exclude_tools_alias_is_accepted(): void
    {
        // kimi-cli uses `exclude_tools`; we keep `disallowed_tools` for
        // backwards compat; both should land in disallowedTools().
        $path = $this->write('a.yaml', <<<YAML
        name: a
        disallowed_tools: [Write]
        exclude_tools: [Edit]
        YAML);

        $def = YamlAgentDefinition::fromFile($path);
        $disallowed = $def->disallowedTools();
        $this->assertContains('write_file', $disallowed);
        $this->assertContains('edit_file', $disallowed);
    }

    public function test_features_block_is_passed_through(): void
    {
        $path = $this->write('thinker.yaml', <<<YAML
        name: thinker
        system_prompt: Think carefully.
        features:
          thinking:
            budget: 8000
        YAML);

        $def = YamlAgentDefinition::fromFile($path);
        $this->assertSame(['thinking' => ['budget' => 8000]], $def->features());
    }

    public function test_model_inherit_is_treated_as_null(): void
    {
        $path = $this->write('a.yaml', "name: a\nmodel: inherit\n");
        $def = YamlAgentDefinition::fromFile($path);
        $this->assertNull($def->model());
    }

    // ── extend: inheritance ────────────────────────────────────────

    public function test_extend_inherits_system_prompt_and_tools(): void
    {
        $this->write('base.yaml', <<<YAML
        name: base
        system_prompt: Base prompt.
        allowed_tools: [Read, Grep]
        YAML);
        $child = $this->write('reviewer.yaml', <<<YAML
        extend: base
        name: reviewer
        description: Reviews.
        read_only: true
        YAML);

        $loader = new AgentSpecLoader();
        $def = $loader->loadFile($child);

        $this->assertSame('reviewer', $def->name());
        $this->assertSame('Base prompt.', $def->systemPrompt());
        $this->assertContains('read_file', $def->allowedTools());
        $this->assertContains('grep', $def->allowedTools());
        $this->assertTrue($def->readOnly());
    }

    public function test_extend_tool_lists_accumulate(): void
    {
        $this->write('base.yaml', <<<YAML
        name: base
        allowed_tools: [Read, Grep]
        disallowed_tools: [Write]
        YAML);
        $child = $this->write('c.yaml', <<<YAML
        extend: base
        name: c
        allowed_tools: [Bash]
        exclude_tools: [Edit]
        YAML);

        $loader = new AgentSpecLoader();
        $def = $loader->loadFile($child);
        $allowed = $def->allowedTools();
        $this->assertContains('read_file', $allowed);
        $this->assertContains('grep', $allowed);
        $this->assertContains('bash', $allowed);
        $disallowed = $def->disallowedTools();
        $this->assertContains('write_file', $disallowed);
        $this->assertContains('edit_file', $disallowed);
    }

    public function test_extend_cycle_is_detected(): void
    {
        $this->write('a.yaml', "extend: b\nname: a\n");
        $this->write('b.yaml', "extend: a\nname: b\n");

        $loader = new AgentSpecLoader();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cycle|depth/i');
        $loader->loadFile($this->tmpDir . '/a.yaml');
    }

    public function test_extend_missing_parent_throws(): void
    {
        $child = $this->write('c.yaml', "extend: nonexistent\nname: c\n");
        $loader = new AgentSpecLoader();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no parent file found/');
        $loader->loadFile($child);
    }

    public function test_extend_searches_extra_dirs(): void
    {
        // Parent in a different dir (mimics a built-in agent being
        // extended from a user-dir child).
        $parentDir = $this->tmpDir . '/parents';
        mkdir($parentDir, 0755, true);
        file_put_contents($parentDir . '/base.yaml', "name: base\nsystem_prompt: From parents dir.\n");

        $child = $this->write('c.yaml', "extend: base\nname: c\n");

        $loader = new AgentSpecLoader([$parentDir]);
        $def = $loader->loadFile($child);
        $this->assertSame('From parents dir.', $def->systemPrompt());
    }

    // ── helpers ────────────────────────────────────────────────────

    private function write(string $filename, string $content): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
