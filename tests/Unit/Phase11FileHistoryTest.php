<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\FileHistory\FileSnapshotManager;
use SuperAgent\FileHistory\FileSnapshot;
use SuperAgent\FileHistory\UndoRedoManager;
use SuperAgent\FileHistory\FileAction;
use SuperAgent\FileHistory\GitAttribution;
use SuperAgent\FileHistory\SensitiveFileProtection;
use Carbon\Carbon;

class Phase11FileHistoryTest extends TestCase
{
    private string $testDir;
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test directory
        $this->testDir = sys_get_temp_dir() . '/superagent_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        
        // Create test file
        $this->testFile = $this->testDir . '/test.txt';
        file_put_contents($this->testFile, "Original content\n");
        
        // Clear managers
        FileSnapshotManager::clear();
        UndoRedoManager::clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test files
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
        
        // Clear managers
        FileSnapshotManager::clear();
        UndoRedoManager::clear();
    }

    /**
     * Test FileSnapshotManager singleton.
     */
    public function testFileSnapshotManagerSingleton(): void
    {
        $manager1 = FileSnapshotManager::getInstance();
        $manager2 = FileSnapshotManager::getInstance();
        
        $this->assertSame($manager1, $manager2);
    }

    /**
     * Test creating file snapshots.
     */
    public function testCreateSnapshot(): void
    {
        $manager = FileSnapshotManager::getInstance();
        
        // Create snapshot
        $snapshotId = $manager->createSnapshot($this->testFile);
        $this->assertNotNull($snapshotId);
        $this->assertStringStartsWith('snap_', $snapshotId);
        
        // Verify snapshot count
        $this->assertEquals(1, $manager->getSnapshotCount($this->testFile));
        
        // Get snapshot
        $snapshot = $manager->getLastSnapshot($this->testFile);
        $this->assertNotNull($snapshot);
        $this->assertEquals($this->testFile, $snapshot->filePath);
        $this->assertEquals("Original content\n", $snapshot->content);
        $this->assertEquals(sha1("Original content\n"), $snapshot->hash);
    }

    /**
     * Test snapshot restoration.
     */
    public function testRestoreSnapshot(): void
    {
        $manager = FileSnapshotManager::getInstance();
        
        // Create initial snapshot
        $snapshot1 = $manager->createSnapshot($this->testFile);
        
        // Modify file
        file_put_contents($this->testFile, "Modified content\n");
        
        // Create second snapshot
        $snapshot2 = $manager->createSnapshot($this->testFile);
        
        // Verify file was modified
        $this->assertEquals("Modified content\n", file_get_contents($this->testFile));
        
        // Restore first snapshot
        $restored = $manager->restoreSnapshot($snapshot1);
        $this->assertTrue($restored);
        
        // Verify content was restored
        $this->assertEquals("Original content\n", file_get_contents($this->testFile));
    }

    /**
     * Test diff generation.
     */
    public function testDiffGeneration(): void
    {
        $manager = FileSnapshotManager::getInstance();
        
        // Create initial snapshot
        $snapshot1 = $manager->createSnapshot($this->testFile);
        
        // Modify file
        file_put_contents($this->testFile, "Line 1\nLine 2 modified\nLine 3\n");
        
        // Get diff
        $diff = $manager->getDiff($this->testFile);
        $this->assertNotNull($diff);
        $this->assertArrayHasKey('diff', $diff);
        $this->assertArrayHasKey('stats', $diff);
        
        // Check stats
        $stats = $diff['stats'];
        $this->assertGreaterThan(0, $stats['modified'] + $stats['added']);
    }

    /**
     * Test UndoRedoManager.
     */
    public function testUndoRedoManager(): void
    {
        $undoRedo = UndoRedoManager::getInstance();
        $snapshot = FileSnapshotManager::getInstance();
        
        // Create initial snapshot
        $snapshot1 = $snapshot->createSnapshot($this->testFile);
        
        // Modify file
        file_put_contents($this->testFile, "Modified content\n");
        $snapshot2 = $snapshot->createSnapshot($this->testFile);
        
        // Record edit action
        $action = FileAction::edit($this->testFile, $snapshot2, $snapshot1);
        $undoRedo->recordAction($action);
        
        $this->assertTrue($undoRedo->canUndo());
        $this->assertFalse($undoRedo->canRedo());
        
        // Undo
        $undone = $undoRedo->undo();
        $this->assertTrue($undone);
        $this->assertEquals("Original content\n", file_get_contents($this->testFile));
        
        $this->assertFalse($undoRedo->canUndo());
        $this->assertTrue($undoRedo->canRedo());
        
        // Redo
        $redone = $undoRedo->redo();
        $this->assertTrue($redone);
        $this->assertEquals("Modified content\n", file_get_contents($this->testFile));
    }

    /**
     * Test file action creation helpers.
     */
    public function testFileActionCreation(): void
    {
        $createAction = FileAction::create('/path/to/file', 'content');
        $this->assertEquals('create', $createAction->type);
        $this->assertEquals('/path/to/file', $createAction->filePath);
        $this->assertEquals('content', $createAction->content);
        
        $editAction = FileAction::edit('/path/to/file', 'snap2', 'snap1');
        $this->assertEquals('edit', $editAction->type);
        $this->assertEquals('snap2', $editAction->snapshotId);
        $this->assertEquals('snap1', $editAction->previousSnapshotId);
        
        $deleteAction = FileAction::delete('/path/to/file', 'snap1');
        $this->assertEquals('delete', $deleteAction->type);
        $this->assertEquals('snap1', $deleteAction->previousSnapshotId);
        
        $renameAction = FileAction::rename('/old/path', '/new/path');
        $this->assertEquals('rename', $renameAction->type);
        $this->assertEquals('/old/path', $renameAction->filePath);
        $this->assertEquals('/new/path', $renameAction->newPath);
    }

    /**
     * Test GitAttribution.
     */
    public function testGitAttribution(): void
    {
        $git = GitAttribution::getInstance();
        
        // Check if in git repo (may not be in test environment)
        if (!$git->isGitRepository()) {
            $this->markTestSkipped('Not in a git repository');
        }
        
        // Test message preparation
        $message = $git->prepareCommitMessage('Test commit');
        $this->assertStringContainsString('Test commit', $message);
        $this->assertStringContainsString('SuperAgent', $message);
        $this->assertStringContainsString('Co-Authored-By', $message);
        
        // Test getting modified files
        $files = $git->getModifiedFiles();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $files);
        
        // Test branch detection
        $branch = $git->getCurrentBranch();
        $this->assertIsString($branch);
    }

    /**
     * Test SensitiveFileProtection.
     */
    public function testSensitiveFileProtection(): void
    {
        $protection = SensitiveFileProtection::getInstance();
        
        // Test .env file detection
        $this->assertTrue($protection->isProtected('.env'));
        $this->assertTrue($protection->isProtected('.env.local'));
        $this->assertTrue($protection->isProtected('/path/to/.env'));
        
        // Test key file detection
        $this->assertTrue($protection->isProtected('private.key'));
        $this->assertTrue($protection->isProtected('id_rsa'));
        $this->assertTrue($protection->isProtected('/home/user/.ssh/id_rsa'));
        
        // Test non-protected file
        $this->assertFalse($protection->isProtected('regular.txt'));
        $this->assertFalse($protection->isProtected('app.js'));
    }

    /**
     * Test protection operations.
     */
    public function testProtectionOperations(): void
    {
        $protection = SensitiveFileProtection::getInstance();
        
        // Test read operation on protected file
        $readResult = $protection->checkOperation('read', '.env');
        $this->assertTrue($readResult->allowed);
        $this->assertArrayHasKey('redact', $readResult->metadata);
        
        // Test delete operation on protected file
        $deleteResult = $protection->checkOperation('delete', '.env');
        $this->assertFalse($deleteResult->allowed);
        $this->assertStringContainsString('Cannot delete', $deleteResult->reason);
        
        // Test write with secrets
        $writeResult = $protection->checkOperation('write', 'config.txt', [
            'content' => 'api_key=sk-1234567890abcdefghij',
        ]);
        $this->assertFalse($writeResult->allowed);
        $this->assertStringContainsString('secrets', $writeResult->reason);
    }

    /**
     * Test secret detection.
     */
    public function testSecretDetection(): void
    {
        $protection = SensitiveFileProtection::getInstance();
        
        $content = "
            API_KEY=sk-1234567890abcdefghij
            AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
            password: mysecretpassword123
            token: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9
            database_url: mysql://user:pass@localhost/db
        ";
        
        $secrets = $protection->detectSecrets($content);
        $this->assertNotEmpty($secrets);
        
        $types = array_column($secrets, 'type');
        $this->assertContains('api_key', $types);
        $this->assertContains('aws_key', $types);
        $this->assertContains('password', $types);
        $this->assertContains('token', $types);
        $this->assertContains('database_url', $types);
    }

    /**
     * Test snapshot cleanup.
     */
    public function testSnapshotCleanup(): void
    {
        $manager = FileSnapshotManager::getInstance();
        
        // Create many snapshots (more than max)
        for ($i = 0; $i < 60; $i++) {
            file_put_contents($this->testFile, "Content version {$i}\n");
            $manager->createSnapshot($this->testFile);
        }
        
        // Check that old snapshots were cleaned up
        // Default max is 50
        $count = $manager->getSnapshotCount($this->testFile);
        $this->assertLessThanOrEqual(50, $count);
    }

    /**
     * Test undo/redo history.
     */
    public function testUndoRedoHistory(): void
    {
        $undoRedo = UndoRedoManager::getInstance();
        
        // Record multiple actions
        for ($i = 1; $i <= 5; $i++) {
            $action = FileAction::create("/file{$i}.txt", "Content {$i}");
            $undoRedo->recordAction($action);
        }
        
        $this->assertEquals(5, $undoRedo->getHistorySize());
        $this->assertEquals(4, $undoRedo->getCurrentPosition()); // 0-indexed
        
        // Get history
        $history = $undoRedo->getHistory();
        $this->assertCount(5, $history);
        
        // Undo twice
        $undoRedo->undo();
        $undoRedo->undo();
        $this->assertEquals(2, $undoRedo->getCurrentPosition());
        
        // Record new action (should truncate forward history)
        $action = FileAction::create("/new.txt", "New content");
        $undoRedo->recordAction($action);
        
        $this->assertEquals(4, $undoRedo->getHistorySize()); // 3 original + 1 new
        $this->assertEquals(3, $undoRedo->getCurrentPosition());
    }

    /**
     * Test protection violations logging.
     */
    public function testProtectionViolations(): void
    {
        $protection = SensitiveFileProtection::getInstance();
        
        // Clear violations
        $protection->clearViolations();
        
        // Attempt protected operations
        $protection->checkOperation('delete', '.env');
        $protection->checkOperation('write', 'id_rsa', ['content' => 'new key']);
        
        $violations = $protection->getViolations();
        $this->assertCount(2, $violations);
        
        $firstViolation = $violations[0];
        $this->assertEquals('delete', $firstViolation['operation']);
        $this->assertEquals('.env', $firstViolation['file_path']);
    }
}