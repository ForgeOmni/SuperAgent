<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\FileHistory\FileSnapshotManager;
use SuperAgent\FileHistory\GitAttribution;
use SuperAgent\FileHistory\SensitiveFileProtection;
use SuperAgent\FileHistory\UndoRedoManager;

class FileHistoryTest extends TestCase
{
    private string $tempFile;
    private FileSnapshotManager $snapshotManager;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tempFile = sys_get_temp_dir() . '/test_file_' . uniqid() . '.txt';
        file_put_contents($this->tempFile, 'initial content');
        
        $this->snapshotManager = FileSnapshotManager::getInstance();
    }
    
    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        
        // Clean up snapshot directory
        $snapshotDir = sys_get_temp_dir() . '/superagent_snapshots';
        if (is_dir($snapshotDir)) {
            $this->rrmdir($snapshotDir);
        }
        
        parent::tearDown();
    }
    
    public function testFileSnapshotManagerCreatesSnapshot()
    {
        $snapshotId = $this->snapshotManager->createSnapshot($this->tempFile);
        
        $this->assertNotNull($snapshotId);
        $this->assertIsString($snapshotId);
    }
    
    public function testFileSnapshotManagerDetectsDuplicates()
    {
        // Create first snapshot
        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);
        
        // Create second snapshot without changes - should return same ID
        $snapshot2 = $this->snapshotManager->createSnapshot($this->tempFile);
        
        $this->assertEquals($snapshot1, $snapshot2);
        
        // Change file content
        file_put_contents($this->tempFile, 'changed content');
        
        // Create third snapshot - should be different
        $snapshot3 = $this->snapshotManager->createSnapshot($this->tempFile);
        
        $this->assertNotEquals($snapshot1, $snapshot3);
    }
    
    public function testFileSnapshotManagerListsSnapshots()
    {
        // Create multiple snapshots with changes
        $this->snapshotManager->createSnapshot($this->tempFile);
        
        file_put_contents($this->tempFile, 'version 2');
        $this->snapshotManager->createSnapshot($this->tempFile);
        
        file_put_contents($this->tempFile, 'version 3');
        $this->snapshotManager->createSnapshot($this->tempFile);
        
        $snapshots = $this->snapshotManager->listSnapshots($this->tempFile);
        
        $this->assertIsArray($snapshots);
        $this->assertCount(3, $snapshots);
    }
    
    public function testFileSnapshotManagerRestoresSnapshot()
    {
        // Create initial snapshot
        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);
        
        // Change file
        file_put_contents($this->tempFile, 'modified content');
        
        // Restore from snapshot
        $restored = $this->snapshotManager->restoreSnapshot($snapshot1);
        
        $this->assertTrue($restored);
        $this->assertEquals('initial content', file_get_contents($this->tempFile));
    }
    
    public function testFileSnapshotManagerGetsDiff()
    {
        // Create snapshot
        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);
        
        // Modify file
        file_put_contents($this->tempFile, "initial content\nadded line");
        
        // Get diff
        $diff = $this->snapshotManager->getDiff($this->tempFile, $snapshot1);
        
        $this->assertNotNull($diff);
        $this->assertStringContainsString('added line', $diff);
    }
    
    public function testGitAttributionGeneratesCommitMessage()
    {
        $attribution = new GitAttribution();
        
        $message = $attribution->generateCommitMessage(
            'Fix authentication bug',
            'SuperAgent',
            ['auth.php' => ['added' => 5, 'removed' => 2]]
        );
        
        $this->assertStringContainsString('Fix authentication bug', $message);
        $this->assertStringContainsString('[SuperAgent]', $message);
        $this->assertStringContainsString('Co-authored-by:', $message);
    }
    
    public function testGitAttributionTracksChanges()
    {
        $attribution = new GitAttribution();
        
        $attribution->trackChange($this->tempFile, 'edit', 10, 5);
        $attribution->trackChange($this->tempFile, 'create');
        
        $stats = $attribution->getChangeStats();
        
        $this->assertArrayHasKey($this->tempFile, $stats);
        $this->assertEquals(10, $stats[$this->tempFile]['added']);
        $this->assertEquals(5, $stats[$this->tempFile]['removed']);
    }
    
    public function testGitAttributionCreatesCommit()
    {
        $attribution = new GitAttribution();
        
        // Mock git repo check
        $gitDir = sys_get_temp_dir() . '/test_git_' . uniqid();
        mkdir($gitDir . '/.git', 0777, true);
        
        try {
            $originalCwd = getcwd();
            chdir($gitDir);
            
            // Test would create commit if in real git repo
            $result = $attribution->createCommit('Test commit');
            
            // In test environment without real git, this should return false
            $this->assertFalse($result);
            
            chdir($originalCwd);
        } finally {
            $this->rrmdir($gitDir);
        }
    }
    
    public function testSensitiveFileProtectionDetectsSensitiveFiles()
    {
        $protection = new SensitiveFileProtection();
        
        // Test common sensitive files
        $this->assertTrue($protection->isSensitive('.env'));
        $this->assertTrue($protection->isSensitive('.env.local'));
        $this->assertTrue($protection->isSensitive('config/database.php'));
        $this->assertTrue($protection->isSensitive('/home/user/.ssh/id_rsa'));
        $this->assertTrue($protection->isSensitive('credentials.json'));
        
        // Test non-sensitive files
        $this->assertFalse($protection->isSensitive('app.js'));
        $this->assertFalse($protection->isSensitive('README.md'));
    }
    
    public function testSensitiveFileProtectionValidatesOperation()
    {
        $protection = new SensitiveFileProtection();
        
        // Should allow read operations on sensitive files
        $this->assertTrue($protection->validateOperation('.env', 'read'));
        
        // Should require confirmation for write operations
        $result = $protection->validateOperation('.env', 'write');
        $this->assertFalse($result); // Without user confirmation
        
        // Should block delete operations by default
        $this->assertFalse($protection->validateOperation('.env', 'delete'));
    }
    
    public function testSensitiveFileProtectionRedactsContent()
    {
        $protection = new SensitiveFileProtection();
        
        $content = "API_KEY=secret123\nDATABASE_URL=postgresql://user:pass@host/db";
        $redacted = $protection->redactSensitiveContent($content, '.env');
        
        $this->assertStringNotContainsString('secret123', $redacted);
        $this->assertStringNotContainsString('pass', $redacted);
        $this->assertStringContainsString('***', $redacted);
    }
    
    public function testUndoRedoManagerTracksChanges()
    {
        $undoRedo = new UndoRedoManager();
        
        // Register initial state
        $undoRedo->pushState($this->tempFile, 'initial content');
        
        // Make changes
        file_put_contents($this->tempFile, 'change 1');
        $undoRedo->pushState($this->tempFile, 'change 1');
        
        file_put_contents($this->tempFile, 'change 2');
        $undoRedo->pushState($this->tempFile, 'change 2');
        
        $this->assertTrue($undoRedo->canUndo($this->tempFile));
        $this->assertFalse($undoRedo->canRedo($this->tempFile));
    }
    
    public function testUndoRedoManagerUndo()
    {
        $undoRedo = new UndoRedoManager();
        
        // Setup states
        $undoRedo->pushState($this->tempFile, 'version 1');
        $undoRedo->pushState($this->tempFile, 'version 2');
        $undoRedo->pushState($this->tempFile, 'version 3');
        
        // Undo once
        $content = $undoRedo->undo($this->tempFile);
        $this->assertEquals('version 2', $content);
        
        // Undo again
        $content = $undoRedo->undo($this->tempFile);
        $this->assertEquals('version 1', $content);
        
        $this->assertTrue($undoRedo->canRedo($this->tempFile));
    }
    
    public function testUndoRedoManagerRedo()
    {
        $undoRedo = new UndoRedoManager();
        
        // Setup states and undo
        $undoRedo->pushState($this->tempFile, 'version 1');
        $undoRedo->pushState($this->tempFile, 'version 2');
        $undoRedo->pushState($this->tempFile, 'version 3');
        
        $undoRedo->undo($this->tempFile); // Back to v2
        $undoRedo->undo($this->tempFile); // Back to v1
        
        // Redo
        $content = $undoRedo->redo($this->tempFile);
        $this->assertEquals('version 2', $content);
        
        $content = $undoRedo->redo($this->tempFile);
        $this->assertEquals('version 3', $content);
        
        $this->assertFalse($undoRedo->canRedo($this->tempFile));
    }
    
    public function testUndoRedoManagerClearsRedoOnNewChange()
    {
        $undoRedo = new UndoRedoManager();
        
        $undoRedo->pushState($this->tempFile, 'version 1');
        $undoRedo->pushState($this->tempFile, 'version 2');
        
        $undoRedo->undo($this->tempFile); // Back to v1
        $this->assertTrue($undoRedo->canRedo($this->tempFile));
        
        // New change should clear redo stack
        $undoRedo->pushState($this->tempFile, 'version 3');
        
        $this->assertFalse($undoRedo->canRedo($this->tempFile));
    }
    
    public function testUndoRedoManagerGetHistory()
    {
        $undoRedo = new UndoRedoManager();
        
        $undoRedo->pushState($this->tempFile, 'version 1');
        $undoRedo->pushState($this->tempFile, 'version 2');
        $undoRedo->pushState($this->tempFile, 'version 3');
        
        $history = $undoRedo->getHistory($this->tempFile);
        
        $this->assertCount(3, $history);
        $this->assertEquals('version 1', $history[0]['content']);
        $this->assertEquals('version 3', $history[2]['content']);
    }
    
    private function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->rrmdir($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}