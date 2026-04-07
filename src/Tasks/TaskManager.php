<?php

namespace SuperAgent\Tasks;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaskManager
{
    private static ?self $instance = null;
    private Collection $tasks;
    private Collection $taskLists;

    public function __construct()
    {
        $this->tasks = collect();
        $this->taskLists = collect([
            'default' => new TaskList('default', 'Default Task List'),
        ]);
    }

    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a new task.
     */
    public function createTask(array $data, string $listId = 'default'): Task
    {
        // Convert string status to enum if needed
        $status = $data['status'] ?? 'pending';
        if (is_string($status)) {
            $status = TaskStatus::from($status);
        }

        $task = new Task(
            id: $this->generateTaskId(),
            subject: $data['subject'],
            description: $data['description'],
            status: $status,
            activeForm: $data['activeForm'] ?? null,
            metadata: $data['metadata'] ?? [],
            owner: $data['owner'] ?? null,
            blocks: $data['blocks'] ?? [],
            blockedBy: $data['blockedBy'] ?? [],
            createdAt: now(),
            updatedAt: now()
        );

        $this->tasks->put($task->id, $task);

        // Add to task list
        if ($list = $this->taskLists->get($listId)) {
            $list->addTask($task->id);
        }

        return $task;
    }

    /**
     * Get a task by ID.
     */
    public function getTask(string $taskId): ?Task
    {
        return $this->tasks->get($taskId);
    }

    /**
     * Update a task.
     */
    public function updateTask(string $taskId, array $updates): bool
    {
        $task = $this->tasks->get($taskId);
        if (!$task) {
            return false;
        }

        $updatedFields = [];

        if (isset($updates['subject'])) {
            $task->subject = $updates['subject'];
            $updatedFields[] = 'subject';
        }

        if (isset($updates['description'])) {
            $task->description = $updates['description'];
            $updatedFields[] = 'description';
        }

        if (isset($updates['status'])) {
            $oldStatus = $task->status;
            $task->status = TaskStatus::from($updates['status']);
            $updatedFields[] = 'status';

            // Update timestamps based on status
            if ($task->status === TaskStatus::IN_PROGRESS && $oldStatus === TaskStatus::PENDING) {
                $task->startedAt = now();
            } elseif (in_array($task->status, [TaskStatus::COMPLETED, TaskStatus::FAILED, TaskStatus::KILLED])) {
                $task->endedAt = now();
            }
        }

        if (isset($updates['activeForm'])) {
            $task->activeForm = $updates['activeForm'];
            $updatedFields[] = 'activeForm';
        }

        if (isset($updates['owner'])) {
            $task->owner = $updates['owner'];
            $updatedFields[] = 'owner';
        }

        if (isset($updates['metadata'])) {
            $task->metadata = array_merge($task->metadata, $updates['metadata']);
            // Remove null values (deletion)
            $task->metadata = array_filter($task->metadata, fn($v) => $v !== null);
            $updatedFields[] = 'metadata';
        }

        if (isset($updates['addBlocks'])) {
            $task->blocks = array_unique(array_merge($task->blocks, $updates['addBlocks']));
            $updatedFields[] = 'blocks';
        }

        if (isset($updates['addBlockedBy'])) {
            $task->blockedBy = array_unique(array_merge($task->blockedBy, $updates['addBlockedBy']));
            $updatedFields[] = 'blockedBy';
        }

        $task->updatedAt = now();
        $this->tasks->put($taskId, $task);

        return !empty($updatedFields);
    }

    /**
     * List all tasks.
     */
    public function listTasks(string $listId = 'default', ?string $status = null): Collection
    {
        $list = $this->taskLists->get($listId);
        if (!$list) {
            return collect();
        }

        $tasks = $this->tasks->filter(fn($task) => in_array($task->id, $list->taskIds));

        if ($status) {
            $statusEnum = TaskStatus::from($status);
            $tasks = $tasks->filter(fn($task) => $task->status === $statusEnum);
        }

        return $tasks->values();
    }

    /**
     * Stop/kill a task.
     */
    public function stopTask(string $taskId): bool
    {
        $task = $this->tasks->get($taskId);
        if (!$task) {
            return false;
        }

        // Only stop if task is running
        if (!in_array($task->status, [TaskStatus::PENDING, TaskStatus::IN_PROGRESS])) {
            return false;
        }

        $task->status = TaskStatus::KILLED;
        $task->endedAt = now();
        $task->updatedAt = now();
        $this->tasks->put($taskId, $task);

        return true;
    }

    /**
     * Delete a task.
     */
    public function deleteTask(string $taskId): bool
    {
        if (!$this->tasks->has($taskId)) {
            return false;
        }

        // Remove from all task lists
        foreach ($this->taskLists as $list) {
            $list->removeTask($taskId);
        }

        // Remove the task
        $this->tasks->forget($taskId);

        return true;
    }

    /**
     * Get task output.
     */
    public function getTaskOutput(string $taskId): ?array
    {
        $task = $this->tasks->get($taskId);
        if (!$task) {
            return null;
        }

        return $task->output ?? [];
    }

    /**
     * Set task output.
     */
    public function setTaskOutput(string $taskId, array $output): bool
    {
        $task = $this->tasks->get($taskId);
        if (!$task) {
            return false;
        }

        $task->output = $output;
        $task->updatedAt = now();
        $this->tasks->put($taskId, $task);

        return true;
    }

    /**
     * Inject a pre-built Task directly into the collection (for restore).
     */
    protected function injectTask(Task $task, string $listId = 'default'): void
    {
        $this->tasks->put($task->id, $task);

        if ($list = $this->taskLists->get($listId)) {
            $list->addTask($task->id);
        }
    }

    /**
     * Generate a unique task ID.
     */
    private function generateTaskId(): string
    {
        return 't' . Str::random(8);
    }

    /**
     * Clear all tasks (for testing).
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->tasks = collect();
            self::$instance->taskLists = collect([
                'default' => new TaskList('default', 'Default Task List'),
            ]);
        }
    }

    /**
     * Get or create a task list.
     */
    public function getOrCreateTaskList(string $id, string $name = null): TaskList
    {
        if (!$this->taskLists->has($id)) {
            $this->taskLists->put($id, new TaskList($id, $name ?? $id));
        }
        return $this->taskLists->get($id);
    }
}

class Task
{
    public function __construct(
        public string $id,
        public string $subject,
        public string $description,
        public TaskStatus $status,
        public ?string $activeForm,
        public array $metadata,
        public ?string $owner,
        public array $blocks,
        public array $blockedBy,
        public \DateTimeInterface $createdAt,
        public \DateTimeInterface $updatedAt,
        public ?\DateTimeInterface $startedAt = null,
        public ?\DateTimeInterface $endedAt = null,
        public ?array $output = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status->value,
            'activeForm' => $this->activeForm,
            'metadata' => $this->metadata,
            'owner' => $this->owner,
            'blocks' => $this->blocks,
            'blockedBy' => $this->blockedBy,
            'createdAt' => self::formatDate($this->createdAt),
            'updatedAt' => self::formatDate($this->updatedAt),
            'startedAt' => $this->startedAt ? self::formatDate($this->startedAt) : null,
            'endedAt' => $this->endedAt ? self::formatDate($this->endedAt) : null,
            'output' => $this->output,
        ];
    }

    private static function formatDate(\DateTimeInterface $date): string
    {
        if (method_exists($date, 'toIso8601String')) {
            return $date->toIso8601String();
        }
        return $date->format('c');
    }
}

class TaskList
{
    public array $taskIds = [];

    public function __construct(
        public string $id,
        public string $name,
    ) {}

    public function addTask(string $taskId): void
    {
        if (!in_array($taskId, $this->taskIds)) {
            $this->taskIds[] = $taskId;
        }
    }

    public function removeTask(string $taskId): void
    {
        $this->taskIds = array_values(array_diff($this->taskIds, [$taskId]));
    }
}

enum TaskStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case KILLED = 'killed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::KILLED]);
    }
}