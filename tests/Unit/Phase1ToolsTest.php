<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\Builtin\FileEditTool;
use SuperAgent\Tools\Builtin\GlobTool;
use SuperAgent\Tools\Builtin\GrepTool;
use SuperAgent\Tools\Builtin\NotebookEditTool;
use SuperAgent\Tools\Builtin\WebFetchTool;
use SuperAgent\Tools\Builtin\WebSearchTool;
use SuperAgent\Tools\BuiltinToolRegistry;

class Phase1ToolsTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/superagent_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up test directory
        $this->recursiveDelete($this->testDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testFileEditTool(): void
    {
        $tool = new FileEditTool();

        // Create a test file
        $testFile = $this->testDir . '/test.txt';
        file_put_contents($testFile, "Hello World\nThis is a test\nGoodbye World");

        // Test replacing first occurrence
        $result = $tool->execute([
            'path' => $testFile,
            'old_string' => 'World',
            'new_string' => 'Universe',
            'replace_all' => false,
        ]);

        $this->assertTrue($result->isSuccess());
        $content = file_get_contents($testFile);
        $this->assertStringContainsString('Hello Universe', $content);
        $this->assertStringContainsString('Goodbye World', $content);

        // Test replacing all occurrences
        $result = $tool->execute([
            'path' => $testFile,
            'old_string' => 'World',
            'new_string' => 'Universe',
            'replace_all' => true,
        ]);

        $this->assertTrue($result->isSuccess());
        $content = file_get_contents($testFile);
        $this->assertStringNotContainsString('World', $content);
        $this->assertStringContainsString('Goodbye Universe', $content);

        // Test error cases
        $result = $tool->execute([
            'path' => '/nonexistent/file.txt',
            'old_string' => 'test',
            'new_string' => 'new',
        ]);
        $this->assertFalse($result->isSuccess());
    }

    public function testGlobTool(): void
    {
        $tool = new GlobTool();

        // Create test files
        file_put_contents($this->testDir . '/file1.txt', 'test');
        file_put_contents($this->testDir . '/file2.php', '<?php');
        mkdir($this->testDir . '/subdir');
        file_put_contents($this->testDir . '/subdir/file3.php', '<?php');

        // Test simple glob
        $result = $tool->execute([
            'pattern' => '*.txt',
            'path' => $this->testDir,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('file1.txt', $result->content);
        $this->assertStringNotContainsString('file2.php', $result->content);

        // Test recursive glob
        $result = $tool->execute([
            'pattern' => '**/*.php',
            'path' => $this->testDir,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('file2.php', $result->content);
        // On Windows, path separator may be backslash
        $this->assertTrue(
            str_contains($result->content, 'subdir/file3.php') || str_contains($result->content, 'subdir\\file3.php'),
            "Expected subdir/file3.php or subdir\\file3.php in result"
        );
    }

    public function testGrepTool(): void
    {
        $tool = new GrepTool();

        // Create test files with content
        file_put_contents($this->testDir . '/test1.txt', "Line 1\nHello World\nLine 3");
        file_put_contents($this->testDir . '/test2.txt', "Another file\nHello Universe\nLast line");

        // Test finding files with matches
        $result = $tool->execute([
            'pattern' => 'Hello',
            'path' => $this->testDir,
            'output_mode' => 'files_with_matches',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('test1.txt', $result->content);
        $this->assertStringContainsString('test2.txt', $result->content);

        // Test getting content with line numbers
        $result = $tool->execute([
            'pattern' => 'Hello',
            'path' => $this->testDir,
            'output_mode' => 'content',
            'show_line_numbers' => true,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('Hello World', $result->content);
        $this->assertStringContainsString('Hello Universe', $result->content);

        // Test case-insensitive search
        $result = $tool->execute([
            'pattern' => 'hello',
            'path' => $this->testDir,
            'output_mode' => 'files_with_matches',
            'case_insensitive' => true,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('test1.txt', $result->content);
    }

    public function testNotebookEditTool(): void
    {
        $tool = new NotebookEditTool();

        // Create a simple notebook
        $notebookPath = $this->testDir . '/test.ipynb';
        $notebook = [
            'cells' => [
                [
                    'cell_type' => 'code',
                    'source' => ["print('Hello')\n"],
                    'metadata' => [],
                    'execution_count' => null,
                    'outputs' => [],
                ],
                [
                    'cell_type' => 'markdown',
                    'source' => ["# Title\n"],
                    'metadata' => [],
                ],
            ],
            'metadata' => [
                'kernelspec' => [
                    'display_name' => 'Python 3',
                    'language' => 'python',
                    'name' => 'python3',
                ],
            ],
            'nbformat' => 4,
            'nbformat_minor' => 5,
        ];

        file_put_contents($notebookPath, json_encode($notebook, JSON_PRETTY_PRINT));

        // Test replacing a cell
        $result = $tool->execute([
            'notebook_path' => $notebookPath,
            'cell_index' => 0,
            'new_source' => "print('Updated')",
            'edit_mode' => 'replace',
        ]);

        $this->assertTrue($result->isSuccess());

        $updated = json_decode(file_get_contents($notebookPath), true);
        $this->assertStringContainsString('Updated', implode('', $updated['cells'][0]['source']));

        // Test inserting a cell
        $result = $tool->execute([
            'notebook_path' => $notebookPath,
            'cell_index' => 0,
            'new_source' => "# New Cell",
            'cell_type' => 'markdown',
            'edit_mode' => 'insert',
        ]);

        $this->assertTrue($result->isSuccess());

        $updated = json_decode(file_get_contents($notebookPath), true);
        $this->assertCount(3, $updated['cells']);

        // Test deleting a cell
        $result = $tool->execute([
            'notebook_path' => $notebookPath,
            'cell_index' => 1,
            'edit_mode' => 'delete',
        ]);

        $this->assertTrue($result->isSuccess());

        $updated = json_decode(file_get_contents($notebookPath), true);
        $this->assertCount(2, $updated['cells']);
    }

    public function testWebSearchToolMocked(): void
    {
        $tool = new WebSearchTool();

        // Test without API key — falls back to WebFetch/DuckDuckGo
        $result = $tool->execute([
            'query' => 'test query',
        ]);

        // Without API key, search either:
        // 1. Fails (no network) — error mentions API key / search failed
        // 2. Succeeds via DuckDuckGo fallback (has network) — returns results
        if ($result->isSuccess()) {
            $output = $result->contentAsString();
            $this->assertNotEmpty($output, 'Successful fallback search should return results');
        } else {
            $output = $result->error ?? $result->contentAsString();
            $this->assertTrue(
                str_contains($output, 'API key')
                || str_contains($output, 'Search failed')
                || str_contains($output, 'SEARCH_API_KEY'),
                "Expected search failure message, got: {$output}"
            );
        }
    }

    public function testWebFetchToolMocked(): void
    {
        $tool = new WebFetchTool();

        // Test with invalid URL
        $result = $tool->execute([
            'url' => 'not-a-valid-url',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Invalid URL', $result->error);

        // Test with non-HTTP(S) URL
        $result = $tool->execute([
            'url' => 'ftp://example.com',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Only HTTP and HTTPS', $result->error);
    }

    public function testToolRegistry(): void
    {
        // Test getting all tools
        $tools = BuiltinToolRegistry::all();
        $this->assertArrayHasKey('file_edit', $tools);
        $this->assertArrayHasKey('glob', $tools);
        $this->assertArrayHasKey('grep', $tools);
        $this->assertArrayHasKey('web_search', $tools);
        $this->assertArrayHasKey('web_fetch', $tools);
        $this->assertArrayHasKey('notebook_edit', $tools);

        // Test getting by category
        $fileTools = BuiltinToolRegistry::byCategory('file');
        $this->assertArrayHasKey('read_file', $fileTools);
        $this->assertArrayHasKey('write_file', $fileTools);
        $this->assertArrayHasKey('file_edit', $fileTools);
        $this->assertArrayHasKey('notebook_edit', $fileTools);

        $searchTools = BuiltinToolRegistry::byCategory('search');
        $this->assertArrayHasKey('glob', $searchTools);
        $this->assertArrayHasKey('grep', $searchTools);

        $networkTools = BuiltinToolRegistry::byCategory('network');
        $this->assertArrayHasKey('http_request', $networkTools);
        $this->assertArrayHasKey('web_search', $networkTools);
        $this->assertArrayHasKey('web_fetch', $networkTools);

        // Test getting a specific tool
        $tool = BuiltinToolRegistry::get('file_edit');
        $this->assertInstanceOf(FileEditTool::class, $tool);

        // Test getting read-only tools
        $readOnlyTools = BuiltinToolRegistry::readOnly();
        $this->assertArrayHasKey('read_file', $readOnlyTools);
        $this->assertArrayHasKey('glob', $readOnlyTools);
        $this->assertArrayHasKey('grep', $readOnlyTools);

        // Test categories
        $categories = BuiltinToolRegistry::categories();
        $this->assertContains('file', $categories);
        $this->assertContains('search', $categories);
        $this->assertContains('network', $categories);
        $this->assertContains('execution', $categories);
    }

    public function testToolCategories(): void
    {
        $fileEdit = new FileEditTool();
        $this->assertEquals('file', $fileEdit->category());

        $glob = new GlobTool();
        $this->assertEquals('search', $glob->category());

        $grep = new GrepTool();
        $this->assertEquals('search', $grep->category());

        $webSearch = new WebSearchTool();
        $this->assertEquals('network', $webSearch->category());

        $webFetch = new WebFetchTool();
        $this->assertEquals('network', $webFetch->category());

        $notebook = new NotebookEditTool();
        $this->assertEquals('file', $notebook->category());
    }
}