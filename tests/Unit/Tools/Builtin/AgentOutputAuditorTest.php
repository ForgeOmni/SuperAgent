<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools\Builtin;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\Builtin\AgentOutputAuditor;

/**
 * Pins the output-auditor contract. Uses a real temp dir (not vfsStream)
 * because `RecursiveDirectoryIterator` is the SUT and we want to exercise
 * the real iterator behaviour — mock FSes have missed stat() call
 * differences in the past.
 */
class AgentOutputAuditorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/superagent-auditor-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }
    }

    // ------------------------------------------------------------------
    // Extension whitelist
    // ------------------------------------------------------------------

    public function test_clean_directory_returns_empty_warnings(): void
    {
        $this->touch($this->root . '/report.md');
        $this->touch($this->root . '/data.csv');
        $this->touch($this->root . '/chart.png');

        $auditor = new AgentOutputAuditor(['md', 'csv', 'png']);
        $this->assertSame([], $auditor->audit($this->root, 'analyst'));
    }

    public function test_non_whitelisted_extension_flagged(): void
    {
        $this->touch($this->root . '/generate.py');
        $this->touch($this->root . '/report.md');

        $auditor = new AgentOutputAuditor(['md', 'csv', 'png']);
        $warnings = $auditor->audit($this->root, 'analyst');

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('non-whitelisted extensions', $warnings[0]);
        $this->assertStringContainsString('generate.py', $warnings[0]);
    }

    public function test_null_extension_policy_disables_check(): void
    {
        $this->touch($this->root . '/generate.py');
        $this->touch($this->root . '/report.md');

        $auditor = new AgentOutputAuditor(null); // null = no ext check
        $this->assertSame([], $auditor->audit($this->root, 'analyst'));
    }

    // ------------------------------------------------------------------
    // Reserved filenames
    // ------------------------------------------------------------------

    public function test_consolidator_reserved_filename_flagged(): void
    {
        $this->touch($this->root . '/report.md');
        $this->touch($this->root . '/summary.md'); // reserved
        $this->touch($this->root . '/摘要.md');     // reserved (zh)

        $auditor = new AgentOutputAuditor(null);
        $warnings = $auditor->audit($this->root, 'analyst');

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('consolidator-reserved filenames', $warnings[0]);
        $this->assertStringContainsString('summary.md', $warnings[0]);
        $this->assertStringContainsString('摘要.md', $warnings[0]);
    }

    public function test_empty_reserved_list_disables_check(): void
    {
        $this->touch($this->root . '/summary.md');

        $auditor = new AgentOutputAuditor(null, reservedFilenames: []);
        $this->assertSame([], $auditor->audit($this->root, 'analyst'));
    }

    // ------------------------------------------------------------------
    // Sibling-role detection
    // ------------------------------------------------------------------

    public function test_sibling_role_subdir_flagged(): void
    {
        mkdir($this->root . '/ceo', 0700);
        $this->touch($this->root . '/ceo/fabricated.md');

        $auditor = new AgentOutputAuditor(null);
        $warnings = $auditor->audit($this->root, 'analyst');

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('sibling-role sub-directories', $warnings[0]);
        $this->assertStringContainsString('ceo', $warnings[0]);
    }

    public function test_kebab_case_dashed_name_flagged_as_sibling_role(): void
    {
        // The regex gate: `^[a-z][a-z0-9-]*-[a-z0-9-]+$` matches agent
        // ids that look like role slugs (e.g. `ceo-bezos`). Regression
        // pin for SuperAICore RUN 68's `regional-khanna/ceo/` pattern.
        mkdir($this->root . '/regional-khanna', 0700);

        $auditor = new AgentOutputAuditor(null);
        $warnings = $auditor->audit($this->root, 'analyst');

        $this->assertStringContainsString('regional-khanna', $warnings[0]);
    }

    public function test_signals_meta_dir_not_flagged(): void
    {
        mkdir($this->root . '/_signals', 0700);
        $this->touch($this->root . '/_signals/findings.md');
        mkdir($this->root . '/.git', 0700);

        $auditor = new AgentOutputAuditor(null);
        $this->assertSame([], $auditor->audit($this->root, 'analyst'));
    }

    public function test_agent_own_name_subdir_not_flagged(): void
    {
        // If the caller's convention is `output_subdir = agent.name` and
        // the agent happens to be named after a role (e.g. `ceo`), we
        // don't want the agent's OWN subdir flagged as a sibling.
        // Normally the auditor is called WITH $agentName so this is
        // moot; the test pins the behaviour anyway.
        mkdir($this->root . '/ceo', 0700);

        $auditor = new AgentOutputAuditor(null);
        $warnings = $auditor->audit($this->root, 'ceo');

        $this->assertSame([], $warnings);
    }

    public function test_empty_sibling_list_disables_check(): void
    {
        mkdir($this->root . '/ceo', 0700);

        $auditor = new AgentOutputAuditor(null, siblingRoleNames: []);
        $this->assertSame([], $auditor->audit($this->root, 'analyst'));
    }

    // ------------------------------------------------------------------
    // Defensive paths
    // ------------------------------------------------------------------

    public function test_nonexistent_directory_returns_empty(): void
    {
        $auditor = new AgentOutputAuditor();
        $this->assertSame([], $auditor->audit($this->root . '/does-not-exist', 'analyst'));
    }

    // ------------------------------------------------------------------
    // Guard block
    // ------------------------------------------------------------------

    public function test_guard_block_en_for_latin_prompt(): void
    {
        $block = AgentOutputAuditor::guardBlock(
            'analyze the repo',
            'cto-vogels'
        );
        $this->assertStringContainsString(AgentOutputAuditor::GUARD_MARKER, $block);
        $this->assertStringContainsString('cto-vogels', $block);
        $this->assertStringContainsString('Host-injected rules', $block);
        $this->assertStringNotContainsString('宿主强制注入', $block);
    }

    public function test_guard_block_zh_for_cjk_prompt(): void
    {
        $block = AgentOutputAuditor::guardBlock(
            '分析仓库架构',
            'cto-vogels'
        );
        $this->assertStringContainsString('宿主强制注入', $block);
        $this->assertStringContainsString('cto-vogels', $block);
        $this->assertStringNotContainsString('Host-injected rules', $block);
    }

    public function test_guard_block_idempotent(): void
    {
        $firstPrompt = 'analyze the repo';
        $first = AgentOutputAuditor::guardBlock($firstPrompt, 'analyst');
        $this->assertNotSame('', $first);

        // Simulating "already injected" — guardBlock returns empty when
        // the marker is present so callers can safely pipe a prompt
        // through several composition passes.
        $withMarker = $firstPrompt . "\n" . $first;
        $second = AgentOutputAuditor::guardBlock($withMarker, 'analyst');
        $this->assertSame('', $second);
    }

    public function test_guard_block_includes_extension_whitelist(): void
    {
        $block = AgentOutputAuditor::guardBlock('analyze', 'analyst', ['md', 'sql', 'json']);
        $this->assertStringContainsString('.md', $block);
        $this->assertStringContainsString('.sql', $block);
        $this->assertStringContainsString('.json', $block);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function touch(string $path): void
    {
        file_put_contents($path, '');
    }
}
