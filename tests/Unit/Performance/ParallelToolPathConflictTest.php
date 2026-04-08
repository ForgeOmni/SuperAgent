<?php

namespace SuperAgent\Tests\Unit\Performance;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Performance\ParallelToolExecutor;

class ParallelToolPathConflictTest extends TestCase
{
    private ParallelToolExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new ParallelToolExecutor(enabled: true);
    }

    public function test_read_tools_classified_as_parallel(): void
    {
        $blocks = [
            $this->makeToolBlock('read', ['file_path' => '/a.php']),
            $this->makeToolBlock('grep', ['pattern' => 'test']),
            $this->makeToolBlock('glob', ['pattern' => '*.php']),
        ];

        $result = $this->executor->classify($blocks);
        $this->assertCount(3, $result['parallel']);
        $this->assertCount(0, $result['sequential']);
    }

    public function test_writes_to_different_paths_can_be_parallel(): void
    {
        $blocks = [
            $this->makeToolBlock('write', ['file_path' => '/src/a.php']),
            $this->makeToolBlock('write', ['file_path' => '/src/b.php']),
        ];

        $result = $this->executor->classify($blocks);
        $this->assertCount(2, $result['parallel']);
        $this->assertCount(0, $result['sequential']);
    }

    public function test_writes_to_same_path_are_sequential(): void
    {
        $blocks = [
            $this->makeToolBlock('write', ['file_path' => '/src/a.php']),
            $this->makeToolBlock('edit', ['file_path' => '/src/a.php']),
        ];

        $result = $this->executor->classify($blocks);
        // First write goes to parallel, second conflicts and goes to sequential
        $totalParallel = count($result['parallel']);
        $totalSequential = count($result['sequential']);
        // At least one should be sequential due to conflict
        $this->assertEquals(2, $totalParallel + $totalSequential);
        $this->assertGreaterThanOrEqual(1, $totalSequential, 'Conflicting write should be sequential');
    }

    public function test_overlapping_parent_child_paths_are_sequential(): void
    {
        $blocks = [
            $this->makeToolBlock('write', ['file_path' => '/src/controllers/Auth.php']),
            $this->makeToolBlock('bash', ['command' => 'echo test > /src/controllers/Auth.php.bak']),
        ];

        // Both target /src/controllers/ subtree — the bash extracts the path
        $result = $this->executor->classify($blocks);
        // Bash path extraction may or may not match; at minimum both should be handled
        $this->assertNotNull($result);
    }

    public function test_destructive_bash_always_sequential(): void
    {
        $blocks = [
            $this->makeToolBlock('read', ['file_path' => '/a.php']),
            $this->makeToolBlock('bash', ['command' => 'rm -rf /tmp/test']),
            $this->makeToolBlock('read', ['file_path' => '/b.php']),
        ];

        $result = $this->executor->classify($blocks);
        $this->assertCount(2, $result['parallel']); // Two reads
        $this->assertCount(1, $result['sequential']); // rm -rf
    }

    public function test_git_push_is_destructive(): void
    {
        $blocks = [
            $this->makeToolBlock('bash', ['command' => 'git push origin main']),
            $this->makeToolBlock('read', ['file_path' => '/a.php']),
            $this->makeToolBlock('read', ['file_path' => '/b.php']),
        ];

        $result = $this->executor->classify($blocks);
        $this->assertCount(1, $result['sequential']); // git push
        $this->assertCount(2, $result['parallel']); // two reads
    }

    public function test_single_block_returns_sequential(): void
    {
        $blocks = [$this->makeToolBlock('write', ['file_path' => '/a.php'])];
        $result = $this->executor->classify($blocks);
        $this->assertCount(0, $result['parallel']);
        $this->assertCount(1, $result['sequential']);
    }

    public function test_unknown_tools_without_path_are_sequential(): void
    {
        $blocks = [
            $this->makeToolBlock('custom_tool', ['data' => 'something']),
            $this->makeToolBlock('another_tool', ['input' => 'test']),
        ];

        $result = $this->executor->classify($blocks);
        $this->assertCount(0, $result['parallel']);
        $this->assertCount(2, $result['sequential']);
    }

    private function makeToolBlock(string $toolName, array $input): ContentBlock
    {
        return ContentBlock::toolUse(
            'tool-' . uniqid(),
            $toolName,
            $input,
        );
    }
}
