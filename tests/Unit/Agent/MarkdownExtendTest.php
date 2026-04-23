<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent\AgentManager;

/**
 * Locks in `extend:` inheritance for Markdown agent files — the same
 * semantics YAML agents got, now consistent across formats.
 *
 * Test surface goes through AgentManager::loadFromDirectory (the real
 * entry point) rather than invoking the loader directly, so
 * regressions in the glue (loadMarkdownFile → AgentSpecLoader) are
 * caught along with regressions in the resolver itself.
 */
class MarkdownExtendTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/superagent-md-extend-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_markdown_extending_yaml_parent_inherits_tools(): void
    {
        $this->write('base.yaml', <<<YAML
        name: base
        system_prompt: Base prompt from YAML.
        allowed_tools: [Read, Grep]
        YAML);
        $this->write('child.md', <<<MD
        ---
        name: md-extend-child
        extend: base
        ---
        Overridden body from the markdown child.
        MD);

        $mgr = $this->makeManager();
        $mgr->loadFromDirectory($this->tmpDir);

        $agent = $mgr->get('md-extend-child');
        $this->assertNotNull($agent);
        $this->assertSame('Overridden body from the markdown child.', $agent->systemPrompt());
        $this->assertContains('read_file', $agent->allowedTools());
        $this->assertContains('grep', $agent->allowedTools());
    }

    public function test_markdown_with_empty_body_inherits_parent_prompt(): void
    {
        // No body → resolver falls back to parent's system_prompt.
        $this->write('empty-base.yaml', <<<YAML
        name: empty-base
        system_prompt: Parent supplies the prompt.
        YAML);
        $this->write('empty.md', <<<MD
        ---
        name: md-empty-child
        extend: empty-base
        ---
        MD);

        $mgr = $this->makeManager();
        $mgr->loadFromDirectory($this->tmpDir);

        $agent = $mgr->get('md-empty-child');
        $this->assertSame('Parent supplies the prompt.', $agent->systemPrompt());
    }

    public function test_markdown_extending_markdown_works(): void
    {
        $this->write('base-md.md', <<<MD
        ---
        name: base-md
        allowed_tools: [Read]
        ---
        From the parent markdown.
        MD);
        $this->write('child-md.md', <<<MD
        ---
        name: md-child
        extend: base-md
        ---
        MD);

        $mgr = $this->makeManager();
        $mgr->loadFromDirectory($this->tmpDir);

        $agent = $mgr->get('md-child');
        $this->assertSame('From the parent markdown.', $agent->systemPrompt());
        $this->assertContains('read_file', $agent->allowedTools());
    }

    public function test_markdown_tool_lists_accumulate_with_parent(): void
    {
        $this->write('acc-base.yaml', <<<YAML
        name: acc-base
        allowed_tools: [Read, Grep]
        disallowed_tools: [Write]
        YAML);
        $this->write('acc.md', <<<MD
        ---
        name: acc-child
        extend: acc-base
        allowed_tools: [Bash]
        disallowed_tools: [Edit]
        ---
        Body.
        MD);

        $mgr = $this->makeManager();
        $mgr->loadFromDirectory($this->tmpDir);

        $agent = $mgr->get('acc-child');
        $allowed = $agent->allowedTools();
        $this->assertContains('read_file', $allowed);
        $this->assertContains('grep', $allowed);
        $this->assertContains('bash', $allowed);
        $disallowed = $agent->disallowedTools();
        $this->assertContains('write_file', $disallowed);
        $this->assertContains('edit_file', $disallowed);
    }

    public function test_markdown_missing_parent_rejects_file_silently_under_directory_load(): void
    {
        // Under loadFromDirectory(throw: false) a bad child shouldn't
        // abort the whole dir scan — we log nothing, skip the child,
        // and keep loading the rest.
        $this->write('bad.md', <<<MD
        ---
        name: bad
        extend: nonexistent-parent
        ---
        Body.
        MD);
        $this->write('good.md', <<<MD
        ---
        name: good
        ---
        Good body.
        MD);

        $mgr = $this->makeManager();
        $mgr->loadFromDirectory($this->tmpDir);

        $this->assertNull($mgr->get('bad'));
        $this->assertNotNull($mgr->get('good'));
    }

    // ── helpers ────────────────────────────────────────────────────

    private function makeManager(): AgentManager
    {
        // Fresh manager with no globals — the default constructor auto-
        // loads built-in PHP agents + configured paths, which we don't
        // want bleeding into these tests.
        return new AgentManager();
    }

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
