<?php

declare(strict_types=1);

namespace App\Modules\Projects\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Projects\Actions\TaskManager;
use App\Modules\Projects\Automation\ActionReceiptStore;
use App\Modules\Projects\Models\Task;

final class AssignTaskAction implements ActionDefinition
{
    public function __construct(private readonly TaskManager $tasks, private readonly ActionReceiptStore $receipts) {}

    public static function key(): string
    {
        return 'projects.task.assign';
    }

    public static function label(): string
    {
        return 'automation.actions.projects.task.assign';
    }

    public static function module(): string
    {
        return 'projects';
    }

    public static function requiredPermission(): string
    {
        return 'projects.tasks.assign';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['task_id', 'membership_id'], 'properties' => ['task_id' => ['type' => 'string'], 'membership_id' => ['type' => 'string'], 'allocation_percent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['task_id', 'membership_id'], 'properties' => ['task_id' => ['type' => 'string'], 'membership_id' => ['type' => 'string']]];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $task = Task::query()->findOrFail($input['task_id']);
            $this->tasks->assign($task, [$input['membership_id'] => (int) ($input['allocation_percent'] ?? 100)]);

            return ['task_id' => $task->id, 'membership_id' => $input['membership_id']];
        });
    }
}
