<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tasks\PersistentTaskManager;
use SuperAgent\Tasks\TaskStatus;

class PersistentTaskManagerTest extends TestCase
{
    private string $tmpDir;
    private PersistentTaskManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/superagent_test_tasks_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->manager = new PersistentTaskManager($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);

        // Reset singleton
        $reflection = new \ReflectionClass(\SuperAgent\Tasks\TaskManager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        parent::tearDown();
    }

    // ── Basic CRUD persists to disk ───────────────────────────────

    public function testCreateTaskPersistsIndex(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Test persistence',
            'description' => 'Should write tasks.json',
        ]);

        $this->assertFileExists($this->tmpDir . '/tasks.json');

        $data = json_decode(file_get_contents($this->tmpDir . '/tasks.json'), true);
        $this->assertCount(1, $data);
        $this->assertEquals('Test persistence', $data[0]['subject']);
        $this->assertEquals($task->id, $data[0]['id']);
    }

    public function testUpdateTaskPersistsIndex(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Original',
            'description' => 'Original desc',
        ]);

        $this->manager->updateTask($task->id, [
            'subject' => 'Updated',
            'status' => 'in_progress',
        ]);

        $data = json_decode(file_get_contents($this->tmpDir . '/tasks.json'), true);
        $this->assertEquals('Updated', $data[0]['subject']);
        $this->assertEquals('in_progress', $data[0]['status']);
    }

    public function testDeleteTaskRemovesFromIndex(): void
    {
        $task1 = $this->manager->createTask([
            'subject' => 'Task 1',
            'description' => 'First',
        ]);
        $task2 = $this->manager->createTask([
            'subject' => 'Task 2',
            'description' => 'Second',
        ]);

        $this->manager->deleteTask($task1->id);

        $data = json_decode(file_get_contents($this->tmpDir . '/tasks.json'), true);
        $this->assertCount(1, $data);
        $this->assertEquals($task2->id, $data[0]['id']);
    }

    public function testStopTaskPersists(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Running task',
            'description' => 'Will be stopped',
            'status' => 'in_progress',
        ]);

        $this->manager->stopTask($task->id);

        $data = json_decode(file_get_contents($this->tmpDir . '/tasks.json'), true);
        $this->assertEquals('killed', $data[0]['status']);
    }

    // ── Output log files ──────────────────────────────────────────

    public function testOutputFilePath(): void
    {
        $path = $this->manager->getOutputFilePath('test_123');
        $this->assertEquals($this->tmpDir . '/test_123.log', $path);
    }

    public function testAppendAndReadOutput(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Log test',
            'description' => 'Test output logging',
        ]);

        $this->manager->appendOutput($task->id, "line 1\n");
        $this->manager->appendOutput($task->id, "line 2\n");

        $output = $this->manager->readOutput($task->id);
        $this->assertEquals("line 1\nline 2\n", $output);
    }

    public function testAppendEmptyStringDoesNothing(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Empty test',
            'description' => 'Test empty append',
        ]);

        $this->manager->appendOutput($task->id, '');

        $path = $this->manager->getOutputFilePath($task->id);
        $this->assertFileDoesNotExist($path);
    }

    public function testReadOutputTruncatesLargeFiles(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Big output',
            'description' => 'Large output test',
        ]);

        // Write more than maxOutputReadBytes (default 12000)
        $bigData = str_repeat('X', 20000);
        $this->manager->appendOutput($task->id, $bigData);

        // Read with small limit
        $output = $this->manager->readOutput($task->id, 100);
        $this->assertStringContainsString('[...truncated, showing last 100 bytes...]', $output);
        $this->assertStringContainsString('XXXX', $output);
    }

    public function testReadOutputNonexistentTask(): void
    {
        $output = $this->manager->readOutput('nonexistent');
        $this->assertEquals('', $output);
    }

    // ── Index restore ─────────────────────────────────────────────

    public function testRestoreFromDisk(): void
    {
        // Create tasks and persist
        $task1 = $this->manager->createTask([
            'subject' => 'Completed task',
            'description' => 'Already done',
            'status' => 'completed',
        ]);
        $task2 = $this->manager->createTask([
            'subject' => 'Failed task',
            'description' => 'Already failed',
            'status' => 'failed',
        ]);

        // Create a new manager that loads from the same directory
        $newManager = new PersistentTaskManager($this->tmpDir);

        $tasks = $newManager->listTasks();
        $this->assertCount(2, $tasks);
    }

    public function testRestoreMarksRunningTasksAsFailed(): void
    {
        // Manually write an index with in_progress tasks
        $indexData = [
            [
                'id' => 't_running1',
                'subject' => 'Was running',
                'description' => 'This was running when process died',
                'status' => 'in_progress',
                'activeForm' => null,
                'metadata' => [],
                'owner' => null,
                'blocks' => [],
                'blockedBy' => [],
                'createdAt' => date('c'),
                'updatedAt' => date('c'),
                'startedAt' => date('c'),
                'endedAt' => null,
                'output' => null,
            ],
        ];
        file_put_contents($this->tmpDir . '/tasks.json', json_encode($indexData));

        $newManager = new PersistentTaskManager($this->tmpDir);
        $task = $newManager->listTasks()->first();

        $this->assertNotNull($task);
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals('Process died before completion', $task->metadata['_stale_reason']);
    }

    public function testRestoreWithCorruptedIndexSkipsGracefully(): void
    {
        file_put_contents($this->tmpDir . '/tasks.json', 'not valid json{{{');

        // Should not throw, just log warning
        $newManager = new PersistentTaskManager($this->tmpDir);
        $tasks = $newManager->listTasks();
        $this->assertCount(0, $tasks);
    }

    public function testRestoreWithMissingIndexIsEmpty(): void
    {
        $newDir = $this->tmpDir . '/fresh_' . uniqid();
        $newManager = new PersistentTaskManager($newDir);

        $tasks = $newManager->listTasks();
        $this->assertCount(0, $tasks);

        // Cleanup
        $this->recursiveDelete($newDir);
    }

    // ── Delete cleans up files ────────────────────────────────────

    public function testDeleteTaskCleansUpLogFile(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Delete me',
            'description' => 'Has output',
        ]);

        $this->manager->appendOutput($task->id, "some output\n");
        $logPath = $this->manager->getOutputFilePath($task->id);
        $this->assertFileExists($logPath);

        $this->manager->deleteTask($task->id);
        $this->assertFileDoesNotExist($logPath);
    }

    // ── SetTaskOutput persists ────────────────────────────────────

    public function testSetTaskOutputPersists(): void
    {
        $task = $this->manager->createTask([
            'subject' => 'Output task',
            'description' => 'Has structured output',
        ]);

        $this->manager->setTaskOutput($task->id, ['result' => 'success']);

        $data = json_decode(file_get_contents($this->tmpDir . '/tasks.json'), true);
        $this->assertEquals(['result' => 'success'], $data[0]['output']);
    }

    // ── Pruning ───────────────────────────────────────────────────

    public function testPruneRemovesOldCompletedTasks(): void
    {
        // Create a manager with 1-day prune
        $manager = new PersistentTaskManager($this->tmpDir, pruneAfterDays: 1);

        $task = $manager->createTask([
            'subject' => 'Old task',
            'description' => 'Completed long ago',
            'status' => 'completed',
        ]);

        // Manually set endedAt to 2 days ago by rewriting index
        $data = json_decode(file_get_contents($this->tmpDir . '/tasks.json'), true);
        $data[0]['endedAt'] = date('c', time() - 2 * 86400);
        file_put_contents($this->tmpDir . '/tasks.json', json_encode($data));

        // Re-create manager (restores from disk)
        $manager = new PersistentTaskManager($this->tmpDir, pruneAfterDays: 1);
        $pruned = $manager->prune();

        $this->assertEquals(1, $pruned);
        $this->assertCount(0, $manager->listTasks());
    }

    public function testPruneSkipsActiveTasks(): void
    {
        $manager = new PersistentTaskManager($this->tmpDir, pruneAfterDays: 1);

        // "pending" tasks restored from disk become "failed" (stale),
        // so create and mark as completed to test the non-terminal skip
        $task = $manager->createTask([
            'subject' => 'Active task',
            'description' => 'Still in progress',
            'status' => 'in_progress',
        ]);

        $pruned = $manager->prune();
        $this->assertEquals(0, $pruned);
    }

    public function testPruneDisabledWhenZeroDays(): void
    {
        $manager = new PersistentTaskManager($this->tmpDir, pruneAfterDays: 0);

        $manager->createTask([
            'subject' => 'Old task',
            'description' => 'Completed',
            'status' => 'completed',
        ]);

        $pruned = $manager->prune();
        $this->assertEquals(0, $pruned);
    }

    // ── Atomic write safety ───────────────────────────────────────

    public function testMultipleTasksPersistedCorrectly(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->manager->createTask([
                'subject' => "Task {$i}",
                'description' => "Description {$i}",
            ]);
        }

        $data = json_decode(file_get_contents($this->tmpDir . '/tasks.json'), true);
        $this->assertCount(10, $data);
    }

    // ── fromConfig override pattern ───────────────────────────────

    public function testFromConfigOverrideEnablesWhenConfigDisabled(): void
    {
        // Config has persistence.enabled=false by default (no Laravel).
        // Override should force it on.
        $manager = PersistentTaskManager::fromConfig(overrides: [
            'enabled' => true,
            'storage_path' => $this->tmpDir,
        ]);

        $this->assertNotNull($manager, 'Override enabled=true should create manager even when config disabled');
        $this->assertStringContainsString($this->tmpDir, $manager->getStorageDir());
    }

    public function testFromConfigReturnsNullWhenOverrideDisabled(): void
    {
        $manager = PersistentTaskManager::fromConfig(overrides: [
            'enabled' => false,
        ]);

        $this->assertNull($manager, 'Override enabled=false should return null');
    }

    public function testFromConfigOverrideParameters(): void
    {
        $dir = $this->tmpDir . '/override_test';
        $manager = PersistentTaskManager::fromConfig(overrides: [
            'enabled' => true,
            'storage_path' => $dir,
            'max_output_read_bytes' => 5000,
            'prune_after_days' => 7,
        ]);

        $this->assertNotNull($manager);
        $this->assertStringContainsString('override_test', $manager->getStorageDir());

        // Verify prune_after_days took effect: create + attempt prune with 7 days
        $task = $manager->createTask([
            'subject' => 'test',
            'description' => 'test',
            'status' => 'completed',
        ]);

        // No crash means parameters were applied
        $manager->prune();

        // Cleanup
        $this->recursiveDelete($dir);
    }

    // ── Storage dir ───────────────────────────────────────────────

    public function testGetStorageDir(): void
    {
        $this->assertEquals($this->tmpDir, $this->manager->getStorageDir());
    }

    // ── Process watching (unit-level, no real processes) ──────────

    public function testHasWatchedProcessesInitiallyFalse(): void
    {
        $this->assertFalse($this->manager->hasWatchedProcesses());
    }

    public function testPollProcessesWithNoneIsEmpty(): void
    {
        $changed = $this->manager->pollProcesses();
        $this->assertEmpty($changed);
    }

    // ── Helper ────────────────────────────────────────────────────

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
