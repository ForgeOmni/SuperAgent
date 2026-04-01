<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tasks\TaskManager;
use SuperAgent\Tools\Builtin\TaskCreateTool;
use SuperAgent\Tools\Builtin\TaskUpdateTool;
use SuperAgent\Tools\Builtin\TaskGetTool;

class TasksTest extends TestCase
{
    private TaskManager $manager;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = TaskManager::getInstance();
    }
    
    protected function tearDown(): void
    {
        // Reset the singleton instance
        $reflection = new \ReflectionClass(TaskManager::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        
        parent::tearDown();
    }
    
    public function testTaskManagerSingleton()
    {
        $instance1 = TaskManager::getInstance();
        $instance2 = TaskManager::getInstance();
        
        $this->assertSame($instance1, $instance2);
    }
    
    public function testCreateTask()
    {
        $taskData = [
            'subject' => 'Test Task',
            'description' => 'Test task description',
            'status' => 'pending',
            'activeForm' => 'Testing task',
            'metadata' => ['priority' => 'high'],
        ];
        
        $task = $this->manager->createTask($taskData);
        
        $this->assertNotNull($task);
        $this->assertEquals('Test Task', $task->subject);
        $this->assertEquals('Test task description', $task->description);
        $this->assertEquals('pending', $task->status->value);
    }
    
    public function testGetTask()
    {
        $task = $this->manager->createTask([
            'subject' => 'Get Test Task',
            'description' => 'Task to test get',
        ]);
        
        $retrieved = $this->manager->getTask($task->id);
        
        $this->assertNotNull($retrieved);
        $this->assertEquals($task->id, $retrieved->id);
        $this->assertEquals('Get Test Task', $retrieved->subject);
    }
    
    public function testUpdateTask()
    {
        $task = $this->manager->createTask([
            'subject' => 'Update Test Task',
            'description' => 'Original description',
            'status' => 'pending',
        ]);
        
        $updated = $this->manager->updateTask($task->id, [
            'status' => 'in_progress',
            'description' => 'Updated description',
        ]);
        
        $this->assertTrue($updated);
        
        $updatedTask = $this->manager->getTask($task->id);
        $this->assertEquals('in_progress', $updatedTask->status->value);
        $this->assertEquals('Updated description', $updatedTask->description);
    }
    
    public function testDeleteTask()
    {
        $task = $this->manager->createTask([
            'subject' => 'Delete Test Task',
            'description' => 'Task to delete',
        ]);
        
        $deleted = $this->manager->deleteTask($task->id);
        $this->assertTrue($deleted);
        
        $retrieved = $this->manager->getTask($task->id);
        $this->assertNull($retrieved);
    }
    
    public function testListTasks()
    {
        // Create multiple tasks
        $this->manager->createTask([
            'subject' => 'Task 1',
            'description' => 'First task',
            'status' => 'pending',
        ]);
        
        $this->manager->createTask([
            'subject' => 'Task 2',
            'description' => 'Second task',
            'status' => 'in_progress',
        ]);
        
        $this->manager->createTask([
            'subject' => 'Task 3',
            'description' => 'Third task',
            'status' => 'completed',
        ]);
        
        $allTasks = $this->manager->listTasks();
        $this->assertCount(3, $allTasks);
        
        $pendingTasks = $this->manager->listTasks(['status' => 'pending']);
        $this->assertCount(1, $pendingTasks);
        
        $activeTasks = $this->manager->listTasks(['status' => ['pending', 'in_progress']]);
        $this->assertCount(2, $activeTasks);
    }
    
    public function testTaskDependencies()
    {
        $task1 = $this->manager->createTask([
            'subject' => 'Task 1',
            'description' => 'First task',
        ]);
        
        $task2 = $this->manager->createTask([
            'subject' => 'Task 2',
            'description' => 'Second task',
            'blockedBy' => [$task1->id],
        ]);
        
        $this->assertContains($task1->id, $task2->blockedBy);
        
        // Update task1 to know it blocks task2
        $this->manager->updateTask($task1->id, [
            'blocks' => [$task2->id],
        ]);
        
        $updatedTask1 = $this->manager->getTask($task1->id);
        $this->assertContains($task2->id, $updatedTask1->blocks);
    }
    
    public function testTaskProgress()
    {
        $task = $this->manager->createTask([
            'subject' => 'Progress Task',
            'description' => 'Task with progress',
            'metadata' => ['progress' => 0],
        ]);
        
        // Update progress
        $this->manager->updateTask($task->id, [
            'metadata' => ['progress' => 50],
        ]);
        
        $updated = $this->manager->getTask($task->id);
        $this->assertEquals(50, $updated->metadata['progress']);
    }
    
    public function testTaskLists()
    {
        // Create task list
        $list = $this->manager->createTaskList('project-1', 'Project 1 Tasks');
        
        $this->assertNotNull($list);
        $this->assertEquals('project-1', $list->id);
        $this->assertEquals('Project 1 Tasks', $list->name);
        
        // Add tasks to list
        $task1 = $this->manager->createTask([
            'subject' => 'Project Task 1',
            'description' => 'First project task',
        ], 'project-1');
        
        $task2 = $this->manager->createTask([
            'subject' => 'Project Task 2',
            'description' => 'Second project task',
        ], 'project-1');
        
        $listTasks = $this->manager->getTaskList('project-1')->getTasks();
        $this->assertCount(2, $listTasks);
    }
    
    public function testTaskCreateTool()
    {
        $tool = new TaskCreateTool();
        
        $this->assertEquals('task_create', $tool->name());
        $this->assertStringContainsString('Create', $tool->description());
        
        $result = $tool->execute([
            'subject' => 'Tool Created Task',
            'description' => 'Task created by tool',
            'status' => 'pending',
        ]);
        
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('Tool Created Task', $result['subject']);
    }
    
    public function testTaskUpdateTool()
    {
        // Create task first
        $task = $this->manager->createTask([
            'subject' => 'Tool Update Task',
            'description' => 'Original',
            'status' => 'pending',
        ]);
        
        $tool = new TaskUpdateTool();
        
        $this->assertEquals('task_update', $tool->name());
        
        $result = $tool->execute([
            'id' => $task->id,
            'status' => 'in_progress',
            'description' => 'Updated by tool',
        ]);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('in_progress', $result['task']['status']);
    }
    
    public function testTaskGetTool()
    {
        // Create task first
        $task = $this->manager->createTask([
            'subject' => 'Tool Get Task',
            'description' => 'Task to get',
        ]);
        
        $tool = new TaskGetTool();
        
        $this->assertEquals('task_get', $tool->name());
        
        $result = $tool->execute([
            'id' => $task->id,
        ]);
        
        $this->assertEquals($task->id, $result['id']);
        $this->assertEquals('Tool Get Task', $result['subject']);
    }
    
    public function testTaskPlanMode()
    {
        $planManager = $this->manager->getPlanManager();
        
        // Enter plan mode
        $planManager->enterPlanMode();
        $this->assertTrue($planManager->isInPlanMode());
        
        // Create plan
        $plan = $planManager->createPlan([
            'title' => 'Test Plan',
            'steps' => [
                'Step 1: Do something',
                'Step 2: Do something else',
            ],
        ]);
        
        $this->assertNotNull($plan);
        $this->assertEquals('Test Plan', $plan->title);
        $this->assertCount(2, $plan->steps);
        
        // Exit plan mode
        $planManager->exitPlanMode();
        $this->assertFalse($planManager->isInPlanMode());
    }
    
    public function testTaskOwnership()
    {
        $task = $this->manager->createTask([
            'subject' => 'Owned Task',
            'description' => 'Task with owner',
            'owner' => 'agent-1',
        ]);
        
        $this->assertEquals('agent-1', $task->owner);
        
        // Transfer ownership
        $this->manager->updateTask($task->id, [
            'owner' => 'agent-2',
        ]);
        
        $updated = $this->manager->getTask($task->id);
        $this->assertEquals('agent-2', $updated->owner);
    }
    
    public function testTaskSearch()
    {
        $this->manager->createTask([
            'subject' => 'Search Task 1',
            'description' => 'Contains keyword important',
        ]);
        
        $this->manager->createTask([
            'subject' => 'Search Task 2',
            'description' => 'Another task',
        ]);
        
        $this->manager->createTask([
            'subject' => 'Important Task',
            'description' => 'Also important',
        ]);
        
        $results = $this->manager->searchTasks('important');
        $this->assertCount(2, $results);
    }
    
    public function testTaskPriority()
    {
        $highPriority = $this->manager->createTask([
            'subject' => 'High Priority',
            'description' => 'Urgent task',
            'metadata' => ['priority' => 'high'],
        ]);
        
        $lowPriority = $this->manager->createTask([
            'subject' => 'Low Priority',
            'description' => 'Can wait',
            'metadata' => ['priority' => 'low'],
        ]);
        
        $sorted = $this->manager->listTasks([], ['priority' => 'desc']);
        
        // High priority should come first
        $this->assertEquals('High Priority', $sorted[0]->subject);
    }
}