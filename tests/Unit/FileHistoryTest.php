<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\FileHistory\FileSnapshotManager;
use SuperAgent\FileHistory\FileAction;
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

        // Clear singletons before each test
        FileSnapshotManager::clear();
        UndoRedoManager::clear();

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

        // getFileSnapshots returns a Collection
        $snapshots = $this->snapshotManager->getFileSnapshots($this->tempFile);

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

        // Get diff — returns an array with 'diff' and 'stats' keys
        $diff = $this->snapshotManager->getDiff($this->tempFile, $snapshot1);

        $this->assertNotNull($diff);
        $this->assertIsArray($diff);
        $this->assertArrayHasKey('diff', $diff);
        $this->assertArrayHasKey('stats', $diff);

        // Check that the diff captured the added line
        $diffLines = $diff['diff'];
        $addedContent = array_filter($diffLines, fn($d) => $d['type'] === 'added' || $d['type'] === 'modified');
        $this->assertNotEmpty($addedContent);
    }

    public function testGitAttributionGeneratesCommitMessage()
    {
        $attribution = GitAttribution::getInstance();

        $message = $attribution->prepareCommitMessage('Fix authentication bug');

        $this->assertStringContainsString('Fix authentication bug', $message);
        $this->assertStringContainsString('Co-Authored-By:', $message);
    }

    public function testGitAttributionTracksChanges()
    {
        $attribution = GitAttribution::getInstance();

        // Test that we can get modified files (returns a Collection)
        $files = $attribution->getModifiedFiles();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $files);
    }

    public function testGitAttributionCreatesCommitReturnsFalseWhenDisabled()
    {
        $attribution = GitAttribution::getInstance();

        // When disabled, createCommit should always return false
        $attribution->setEnabled(false);
        $result = $attribution->createCommit('Test commit');
        $this->assertFalse($result);

        // Restore enabled state
        $attribution->setEnabled(true);
    }

    public function testSensitiveFileProtectionDetectsSensitiveFiles()
    {
        $protection = SensitiveFileProtection::getInstance();

        // Test common sensitive files
        $this->assertTrue($protection->isProtected('.env'));
        $this->assertTrue($protection->isProtected('.env.local'));
        $this->assertTrue($protection->isProtected('config/database.php'));
        $this->assertTrue($protection->isProtected('credentials.json'));

        // Test non-sensitive files
        $this->assertFalse($protection->isProtected('app.js'));
        $this->assertFalse($protection->isProtected('README.md'));
    }

    public function testSensitiveFileProtectionValidatesOperation()
    {
        $protection = SensitiveFileProtection::getInstance();

        // Should allow read operations on sensitive files
        $readResult = $protection->checkOperation('read', '.env');
        $this->assertTrue($readResult->allowed);

        // Should block delete operations by default
        $deleteResult = $protection->checkOperation('delete', '.env');
        $this->assertFalse($deleteResult->allowed);
    }

    public function testSensitiveFileProtectionRedactsContent()
    {
        $protection = SensitiveFileProtection::getInstance();

        $content = "API_KEY=secret123456789012345\nDATABASE_URL=postgresql://user:pass@host/db";
        $secrets = $protection->detectSecrets($content);

        $this->assertNotEmpty($secrets);
        // Should detect api_key and/or database_url patterns
        $secretTypes = array_column($secrets, 'type');
        $this->assertTrue(
            in_array('api_key', $secretTypes) || in_array('database_url', $secretTypes),
            'Should detect at least one secret pattern'
        );
    }

    public function testUndoRedoManagerTracksChanges()
    {
        $undoRedo = UndoRedoManager::getInstance();

        // Record actions using FileAction objects
        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);

        file_put_contents($this->tempFile, 'change 1');
        $snapshot2 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot2, $snapshot1));

        file_put_contents($this->tempFile, 'change 2');
        $snapshot3 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot3, $snapshot2));

        $this->assertTrue($undoRedo->canUndo());
        $this->assertFalse($undoRedo->canRedo());
    }

    public function testUndoRedoManagerUndo()
    {
        $undoRedo = UndoRedoManager::getInstance();

        // Setup actions
        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);

        file_put_contents($this->tempFile, 'version 2');
        $snapshot2 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot2, $snapshot1));

        file_put_contents($this->tempFile, 'version 3');
        $snapshot3 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot3, $snapshot2));

        // Undo once
        $result = $undoRedo->undo();
        $this->assertTrue($result);
        $this->assertEquals('version 2', file_get_contents($this->tempFile));

        // Undo again
        $result = $undoRedo->undo();
        $this->assertTrue($result);
        $this->assertEquals('initial content', file_get_contents($this->tempFile));

        $this->assertTrue($undoRedo->canRedo());
    }

    public function testUndoRedoManagerRedo()
    {
        $undoRedo = UndoRedoManager::getInstance();

        // Setup actions
        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);

        file_put_contents($this->tempFile, 'version 2');
        $snapshot2 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot2, $snapshot1));

        file_put_contents($this->tempFile, 'version 3');
        $snapshot3 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot3, $snapshot2));

        // Undo twice
        $undoRedo->undo();
        $undoRedo->undo();

        // Redo
        $result = $undoRedo->redo();
        $this->assertTrue($result);
        $this->assertEquals('version 2', file_get_contents($this->tempFile));

        $result = $undoRedo->redo();
        $this->assertTrue($result);
        $this->assertEquals('version 3', file_get_contents($this->tempFile));

        $this->assertFalse($undoRedo->canRedo());
    }

    public function testUndoRedoManagerClearsRedoOnNewChange()
    {
        $undoRedo = UndoRedoManager::getInstance();

        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);

        file_put_contents($this->tempFile, 'version 2');
        $snapshot2 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot2, $snapshot1));

        $undoRedo->undo(); // Back to initial
        $this->assertTrue($undoRedo->canRedo());

        // New action should clear redo stack
        file_put_contents($this->tempFile, 'version 3');
        $snapshot3 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot3, $snapshot1));

        $this->assertFalse($undoRedo->canRedo());
    }

    public function testUndoRedoManagerGetHistory()
    {
        $undoRedo = UndoRedoManager::getInstance();

        $snapshot1 = $this->snapshotManager->createSnapshot($this->tempFile);

        file_put_contents($this->tempFile, 'version 2');
        $snapshot2 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot2, $snapshot1));

        file_put_contents($this->tempFile, 'version 3');
        $snapshot3 = $this->snapshotManager->createSnapshot($this->tempFile);
        $undoRedo->recordAction(FileAction::edit($this->tempFile, $snapshot3, $snapshot2));

        $history = $undoRedo->getHistory();

        $this->assertCount(2, $history);
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
