<?php

namespace SuperAgent\Tests\Unit\AdaptiveFeedback;

use PHPUnit\Framework\TestCase;
use SuperAgent\AdaptiveFeedback\CorrectionCategory;
use SuperAgent\AdaptiveFeedback\CorrectionCollector;
use SuperAgent\AdaptiveFeedback\CorrectionStore;

class CorrectionCollectorTest extends TestCase
{
    private CorrectionStore $store;
    private CorrectionCollector $collector;

    protected function setUp(): void
    {
        $this->store = new CorrectionStore(null);
        $this->collector = new CorrectionCollector($this->store);
    }

    // ── Denial Recording ───────────────────────────────────────────

    public function test_record_denial_bash(): void
    {
        $pattern = $this->collector->recordDenial(
            'Bash',
            ['command' => 'rm -rf /tmp/test'],
            'User denied',
        );

        $this->assertSame(CorrectionCategory::TOOL_DENIED, $pattern->category);
        $this->assertSame('Bash', $pattern->toolName);
        $this->assertStringContainsString('bash: rm -rf', $pattern->pattern);
    }

    public function test_record_denial_edit(): void
    {
        $pattern = $this->collector->recordDenial(
            'Edit',
            ['file_path' => '/src/App.php'],
            'User denied',
        );

        $this->assertStringContainsString('edit:', $pattern->pattern);
        $this->assertStringContainsString('.php', $pattern->pattern);
    }

    public function test_record_denial_network(): void
    {
        $pattern = $this->collector->recordDenial('WebFetch', ['url' => 'http://example.com'], 'Denied');

        $this->assertStringContainsString('network', $pattern->pattern);
    }

    public function test_repeated_denial_increments_count(): void
    {
        $this->collector->recordDenial('Bash', ['command' => 'rm -rf /a'], 'reason1');
        $pattern = $this->collector->recordDenial('Bash', ['command' => 'rm -rf /b'], 'reason2');

        $this->assertSame(2, $pattern->occurrences);
    }

    // ── Bash Pattern Extraction ────────────────────────────────────

    public function test_bash_git_subcommand_extraction(): void
    {
        $pattern = $this->collector->recordDenial(
            'Bash',
            ['command' => 'git push --force origin main'],
            'Denied',
        );

        $this->assertStringContainsString('bash: git push --force', $pattern->pattern);
    }

    public function test_bash_simple_command(): void
    {
        $pattern = $this->collector->recordDenial(
            'Bash',
            ['command' => 'sudo apt install foo'],
            'Denied',
        );

        $this->assertStringContainsString('bash: sudo', $pattern->pattern);
    }

    public function test_bash_empty_command(): void
    {
        $pattern = $this->collector->recordDenial('Bash', [], 'Denied');

        $this->assertSame('bash: empty command', $pattern->pattern);
    }

    // ── Edit Pattern Extraction ────────────────────────────────────

    public function test_edit_sensitive_file(): void
    {
        $pattern = $this->collector->recordDenial(
            'Edit',
            ['file_path' => '/project/.env'],
            'Denied',
        );

        $this->assertSame('edit: .env', $pattern->pattern);
    }

    public function test_edit_by_extension(): void
    {
        $pattern = $this->collector->recordDenial(
            'Edit',
            ['file_path' => '/src/Controller.ts'],
            'Denied',
        );

        $this->assertSame('edit: *.ts files', $pattern->pattern);
    }

    // ── Correction Recording ───────────────────────────────────────

    public function test_record_correction(): void
    {
        $pattern = $this->collector->recordCorrection(
            'Stop adding docstrings to every function',
        );

        $this->assertSame(CorrectionCategory::BEHAVIOR_CORRECTION, $pattern->category);
        $this->assertSame('stop adding docstrings to every function', $pattern->pattern);
    }

    public function test_record_correction_normalizes(): void
    {
        $pattern = $this->collector->recordCorrection('  Too  Many   SPACES  ');

        $this->assertSame('too many spaces', $pattern->pattern);
    }

    public function test_record_correction_with_tool(): void
    {
        $pattern = $this->collector->recordCorrection('Stop using sed', 'Bash');

        $this->assertSame('Bash', $pattern->toolName);
    }

    // ── Revert Recording ───────────────────────────────────────────

    public function test_record_revert(): void
    {
        $pattern = $this->collector->recordRevert('/src/App.php', 'Removed unwanted comments');

        $this->assertSame(CorrectionCategory::EDIT_REVERTED, $pattern->category);
        $this->assertStringContainsString('/src/App.php', $pattern->pattern);
        $this->assertSame('Edit', $pattern->toolName);
    }

    // ── Unwanted Content ───────────────────────────────────────────

    public function test_record_unwanted_content(): void
    {
        $pattern = $this->collector->recordUnwantedContent('unnecessary type annotations');

        $this->assertSame(CorrectionCategory::CONTENT_UNWANTED, $pattern->category);
        $this->assertSame('unnecessary type annotations', $pattern->pattern);
    }

    // ── Output Rejection ───────────────────────────────────────────

    public function test_record_rejection(): void
    {
        $pattern = $this->collector->recordRejection('wrong approach', 'Bash');

        $this->assertSame(CorrectionCategory::OUTPUT_REJECTED, $pattern->category);
    }

    // ── Event Listener ─────────────────────────────────────────────

    public function test_event_listener(): void
    {
        $events = [];
        $this->collector->on('correction.recorded', function ($pattern) use (&$events) {
            $events[] = $pattern->pattern;
        });

        $this->collector->recordCorrection('test feedback');

        $this->assertCount(1, $events);
        $this->assertSame('test feedback', $events[0]);
    }
}
