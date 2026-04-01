<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tasks\TaskManager;
use SuperAgent\Tasks\TaskStatus;
use SuperAgent\Tools\Builtin\TaskCreateTool;
use SuperAgent\Tools\Builtin\TaskUpdateTool;
use SuperAgent\Tools\Builtin\TaskGetTool;
use SuperAgent\Tools\Builtin\TaskListTool;
use SuperAgent\Tools\Builtin\TaskStopTool;
use SuperAgent\Tools\Builtin\TaskOutputTool;
use SuperAgent\Tools\Builtin\EnterPlanModeTool;
use SuperAgent\Tools\Builtin\ExitPlanModeTool;

class Phase8TaskManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear the TaskManager singleton state
        TaskManager::clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clear the TaskManager singleton state
        TaskManager::clear();
    }

    /**
     * Test TaskManager singleton pattern
     */
    public function testTaskManagerSingleton(): void
    {
        $manager1 = TaskManager::getInstance();
        $manager2 = TaskManager::getInstance();
        
        $this->assertSame($manager1, $manager2, 'TaskManager should implement singleton pattern');
    }

    /**
     * Test creating a task with TaskManager
     */
    public function testTaskManagerCreateTask(): void
    {
        $manager = TaskManager::getInstance();
        
        $task = $manager->createTask([
            'subject' => 'Test Task',
            'description' => 'This is a test task',
            'activeForm' => 'Testing task creation',
            'metadata' => ['priority' => 'high'],
            'owner' => 'test_user',
        ]);

        $this->assertNotNull($task);
        $this->assertStringStartsWith('t', $task->id);
        $this->assertEquals('Test Task', $task->subject);
        $this->assertEquals('This is a test task', $task->description);
        $this->assertEquals(TaskStatus::PENDING, $task->status);
        $this->assertEquals('Testing task creation', $task->activeForm);
        $this->assertEquals(['priority' => 'high'], $task->metadata);
        $this->assertEquals('test_user', $task->owner);
    }

    /**
     * Test TaskCreateTool
     */
    public function testTaskCreateTool(): void
    {
        $tool = new TaskCreateTool();
        
        $this->assertEquals('task_create', $tool->name());
        $this->assertEquals('task', $tool->category());
        $this->assertFalse($tool->isReadOnly());

        $result = $tool->execute([
            'subject' => 'Build feature X',
            'description' => 'Implement the new feature X with tests',
            'activeForm' => 'Building feature X',
            'metadata' => ['sprint' => 3],
            'owner' => 'developer1',
        ]);

        $this->assertTrue($result->isSuccess());
        $data = $result->data;
        $this->assertArrayHasKey('task', $data);
        $this->assertArrayHasKey('id', $data['task']);
        $this->assertArrayHasKey('subject', $data['task']);
        $this->assertEquals('Build feature X', $data['task']['subject']);
    }

    /**
     * Test TaskUpdateTool
     */
    public function testTaskUpdateTool(): void
    {
        $manager = TaskManager::getInstance();
        
        // Create a task first
        $task = $manager->createTask([
            'subject' => 'Original Task',
            'description' => 'Original description',
            'status' => 'pending',
        ]);

        $tool = new TaskUpdateTool();
        
        $this->assertEquals('task_update', $tool->name());
        $this->assertFalse($tool->isReadOnly());

        // Update the task
        $result = $tool->execute([
            'taskId' => $task->id,
            'subject' => 'Updated Task',
            'status' => 'in_progress',
            'metadata' => ['progress' => 50],
        ]);

        $this->assertTrue($result->isSuccess());
        $data = $result->data;
        $this->assertTrue($data['success']);
        $this->assertContains('subject', $data['updatedFields']);
        $this->assertContains('status', $data['updatedFields']);
        
        // Verify the update
        $updatedTask = $manager->getTask($task->id);
        $this->assertEquals('Updated Task', $updatedTask->subject);
        $this->assertEquals(TaskStatus::IN_PROGRESS, $updatedTask->status);
        $this->assertEquals(50, $updatedTask->metadata['progress']);
    }

    /**
     * Test task status transitions
     */
    public function testTaskStatusTransitions(): void
    {
        $manager = TaskManager::getInstance();
        
        $task = $manager->createTask([
            'subject' => 'Status Test',
            'description' => 'Testing status transitions',
        ]);

        $this->assertEquals(TaskStatus::PENDING, $task->status);
        $this->assertNull($task->startedAt);
        $this->assertNull($task->endedAt);

        // Move to in_progress
        $manager->updateTask($task->id, ['status' => 'in_progress']);
        $task = $manager->getTask($task->id);
        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->status);
        $this->assertNotNull($task->startedAt);
        $this->assertNull($task->endedAt);

        // Complete the task
        $manager->updateTask($task->id, ['status' => 'completed']);
        $task = $manager->getTask($task->id);
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertNotNull($task->endedAt);
        $this->assertTrue($task->status->isTerminal());
    }

    /**
     * Test TaskGetTool
     */
    public function testTaskGetTool(): void
    {
        $manager = TaskManager::getInstance();
        
        $task = $manager->createTask([
            'subject' => 'Get Test',
            'description' => 'Testing get functionality',
            'metadata' => ['type' => 'test'],
        ]);

        $tool = new TaskGetTool();
        
        $this->assertEquals('task_get', $tool->name());
        $this->assertTrue($tool->isReadOnly());

        $result = $tool->execute(['taskId' => $task->id]);
        
        $this->assertTrue($result->isSuccess());
        $data = $result->data;
        $this->assertArrayHasKey('task', $data);
        $this->assertEquals($task->id, $data['task']['id']);
        $this->assertEquals('Get Test', $data['task']['subject']);
        $this->assertEquals(['type' => 'test'], $data['task']['metadata']);
    }

    /**
     * Test task dependencies (blocks/blockedBy)
     */
    public function testTaskDependencies(): void
    {
        $manager = TaskManager::getInstance();
        
        // Create tasks
        $task1 = $manager->createTask([
            'subject' => 'Task 1',
            'description' => 'First task',
        ]);

        $task2 = $manager->createTask([
            'subject' => 'Task 2', 
            'description' => 'Second task',
            'blockedBy' => [$task1->id],
        ]);

        $task3 = $manager->createTask([
            'subject' => 'Task 3',
            'description' => 'Third task',
            'blocks' => [$task2->id],
        ]);

        // Update dependencies
        $manager->updateTask($task1->id, [
            'addBlocks' => [$task2->id],
        ]);

        $updatedTask1 = $manager->getTask($task1->id);
        $this->assertContains($task2->id, $updatedTask1->blocks);

        $updatedTask2 = $manager->getTask($task2->id);
        $this->assertContains($task1->id, $updatedTask2->blockedBy);
    }

    /**
     * Test task deletion
     */
    public function testTaskDeletion(): void
    {
        $manager = TaskManager::getInstance();
        
        $task = $manager->createTask([
            'subject' => 'Delete Me',
            'description' => 'Task to be deleted',
        ]);

        $taskId = $task->id;
        
        // Verify task exists
        $this->assertNotNull($manager->getTask($taskId));
        
        // Delete via status update
        $tool = new TaskUpdateTool();
        $result = $tool->execute([
            'taskId' => $taskId,
            'status' => 'deleted',
        ]);
        
        $this->assertTrue($result->isSuccess());
        
        // Verify task is deleted
        $this->assertNull($manager->getTask($taskId));
    }

    /**
     * Test TaskStopTool
     */
    public function testTaskStopTool(): void
    {
        $manager = TaskManager::getInstance();
        
        $task = $manager->createTask([
            'subject' => 'Long Running Task',
            'description' => 'Task that will be stopped',
        ]);

        // Start the task
        $manager->updateTask($task->id, ['status' => 'in_progress']);

        $tool = new TaskStopTool();
        
        // Note: TaskStopTool might need updates to use TaskManager
        // This is a placeholder test that should be expanded
        $this->assertEquals('task_stop', $tool->name());
        $this->assertFalse($tool->isReadOnly());
    }

    /**
     * Test task output management
     */
    public function testTaskOutput(): void
    {
        $manager = TaskManager::getInstance();
        
        $task = $manager->createTask([
            'subject' => 'Task with Output',
            'description' => 'Task that produces output',
        ]);

        $output = [
            'result' => 'success',
            'data' => ['key' => 'value'],
            'logs' => ['Step 1 completed', 'Step 2 completed'],
        ];

        $manager->setTaskOutput($task->id, $output);
        
        $retrievedOutput = $manager->getTaskOutput($task->id);
        $this->assertEquals($output, $retrievedOutput);
    }

    /**
     * Test task list functionality
     */
    public function testTaskList(): void
    {
        $manager = TaskManager::getInstance();
        
        // Create multiple tasks
        $task1 = $manager->createTask([
            'subject' => 'Task 1',
            'description' => 'First task',
            'status' => 'pending',
        ]);

        $task2 = $manager->createTask([
            'subject' => 'Task 2',
            'description' => 'Second task',
            'status' => 'in_progress',
        ]);

        $task3 = $manager->createTask([
            'subject' => 'Task 3',
            'description' => 'Third task',
            'status' => 'completed',
        ]);

        // List all tasks
        $allTasks = $manager->listTasks();
        $this->assertCount(3, $allTasks);

        // List by status
        $pendingTasks = $manager->listTasks('default', 'pending');
        $this->assertCount(1, $pendingTasks);
        $this->assertEquals('Task 1', $pendingTasks->first()->subject);

        $inProgressTasks = $manager->listTasks('default', 'in_progress');
        $this->assertCount(1, $inProgressTasks);
        $this->assertEquals('Task 2', $inProgressTasks->first()->subject);

        $completedTasks = $manager->listTasks('default', 'completed');
        $this->assertCount(1, $completedTasks);
        $this->assertEquals('Task 3', $completedTasks->first()->subject);
    }

    /**
     * Test EnterPlanMode and ExitPlanMode tools
     */
    public function testPlanModeTools(): void
    {
        $enterTool = new EnterPlanModeTool();
        $exitTool = new ExitPlanModeTool();

        $this->assertEquals('enter_plan_mode', $enterTool->name());
        $this->assertEquals('exit_plan_mode', $exitTool->name());
        
        $this->assertEquals('planning', $enterTool->category());
        $this->assertEquals('planning', $exitTool->category());
        
        $this->assertFalse($enterTool->isReadOnly());
        $this->assertFalse($exitTool->isReadOnly());

        // Note: These tools interact with the permission system
        // which would need more complex setup for full testing
    }

    /**
     * Test metadata management
     */
    public function testTaskMetadata(): void
    {
        $manager = TaskManager::getInstance();
        
        $task = $manager->createTask([
            'subject' => 'Metadata Test',
            'description' => 'Testing metadata',
            'metadata' => [
                'priority' => 'high',
                'sprint' => 3,
                'tags' => ['backend', 'api'],
            ],
        ]);

        // Update metadata (merge)
        $manager->updateTask($task->id, [
            'metadata' => [
                'priority' => 'critical',  // Update existing
                'assignee' => 'john',      // Add new
                'sprint' => null,          // Delete by setting null
            ],
        ]);

        $updatedTask = $manager->getTask($task->id);
        $this->assertEquals('critical', $updatedTask->metadata['priority']);
        $this->assertEquals('john', $updatedTask->metadata['assignee']);
        $this->assertArrayNotHasKey('sprint', $updatedTask->metadata);
        $this->assertEquals(['backend', 'api'], $updatedTask->metadata['tags']);
    }

    /**
     * Test error handling
     */
    public function testErrorHandling(): void
    {
        $getTool = new TaskGetTool();
        $updateTool = new TaskUpdateTool();

        // Test getting non-existent task
        $result = $getTool->execute(['taskId' => 'nonexistent']);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('not found', $result->error);

        // Test updating non-existent task
        $result = $updateTool->execute([
            'taskId' => 'nonexistent',
            'subject' => 'New Subject',
        ]);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('not found', $result->error);

        // Test missing required fields
        $createTool = new TaskCreateTool();
        $result = $createTool->execute([]);
        $this->assertFalse($result->isSuccess());
    }
}