<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Projects\Models\Task;

final class TaskCompleted extends TenantDomainEvent
{
    public function __construct(public readonly string $taskId, public readonly string $projectId, public readonly string $title)
    {
        parent::__construct();
    }

    public static function from(Task $task): self
    {
        return new self($task->id, $task->project_id, $task->title);
    }

    public static function name(): string
    {
        return 'projects.task.completed';
    }

    public function payload(): array
    {
        return ['task_id' => $this->taskId, 'project_id' => $this->projectId, 'title' => $this->title];
    }
}
