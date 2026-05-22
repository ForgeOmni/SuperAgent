<?php

namespace SuperAgent\Tests\Tools\Compression;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\Compression\RtkPipeline;

class RtkPipelineTest extends TestCase
{
    public function test_git_diff_drops_context_keeps_change_lines(): void
    {
        $rtk = new RtkPipeline();
        $diff = <<<'D'
diff --git a/src/Foo.php b/src/Foo.php
index abc123..def456 100644
--- a/src/Foo.php
+++ b/src/Foo.php
@@ -10,7 +10,7 @@ class Foo
     public function bar()
     {
         $x = 1;
-        return $x;
+        return $x + 1;
         // unchanged
         // unchanged
     }
D;
        $out = $rtk->compress('git_diff', $diff);
        $this->assertStringContainsString('diff --git', $out);
        $this->assertStringContainsString('@@ -10,7', $out);
        $this->assertStringContainsString('-        return $x;', $out);
        $this->assertStringContainsString('+        return $x + 1;', $out);
        $this->assertStringNotContainsString('index abc123', $out);
        $this->assertStringNotContainsString('// unchanged', $out);
        $this->assertLessThan(strlen($diff), strlen($out));
    }

    public function test_grep_compacts_repeated_file_paths(): void
    {
        $rtk = new RtkPipeline();
        $grep = <<<'G'
src/Foo.php:10:    public function bar()
src/Foo.php:11:    {
src/Foo.php:12:        return 1;
src/Bar.php:5:class Bar
G;
        $out = $rtk->compress('grep', $grep);
        // First hit keeps the path; subsequent hits in same file get indented form
        $this->assertStringContainsString('src/Foo.php:10:', $out);
        $this->assertStringContainsString('  :11:', $out);
        $this->assertStringContainsString('  :12:', $out);
        $this->assertStringContainsString('src/Bar.php:5:', $out);
    }

    public function test_unknown_tool_falls_through_to_original(): void
    {
        $rtk = new RtkPipeline();
        $output = "Some completely arbitrary text that doesn't match any pattern.";
        $this->assertSame($output, $rtk->compress('totally_unknown', $output));
    }

    public function test_heuristic_detects_diff_for_unknown_tool(): void
    {
        $rtk = new RtkPipeline();
        $diff = "diff --git a/x b/x\nindex 1..2\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-old\n+new\n";
        // 'unknown_diff_tool' is not registered, but the content matches the
        // diff heuristic so the pipeline should still compress.
        $out = $rtk->compress('unknown_diff_tool', $diff);
        $this->assertStringNotContainsString('index 1..2', $out);
    }

    public function test_stats_track_savings(): void
    {
        $rtk = new RtkPipeline();
        $diff = str_repeat("diff --git a/x b/x\nindex 1..2\n--- a/x\n+++ b/x\n@@ -1,3 +1,3 @@\n unchanged\n-old\n+new\n unchanged\n", 5);
        $rtk->compress('git_diff', $diff);
        $stats = $rtk->stats();
        $this->assertGreaterThan(0, $stats['saved_bytes']);
        $this->assertGreaterThan(0.0, $stats['ratio']);
    }

    public function test_short_input_with_no_savings_returns_original(): void
    {
        $rtk = new RtkPipeline();
        $tiny = "diff --git a/x b/x\n";
        // Diff-shaped header but nothing to compress — should NOT enlarge
        $this->assertSame($tiny, $rtk->compress('git_diff', $tiny));
    }
}
