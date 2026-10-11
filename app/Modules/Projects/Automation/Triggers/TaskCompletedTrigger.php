<?php

declare(strict_types=1);

namespace App\Modules\Projects\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Projects\Events\TaskCompleted;

final class TaskCompletedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'projects.task.completed';
    }

    public static function label(): string
    {
        return 'automation.triggers.projects.task.completed';
    }

    public static function module(): string
    {
        return 'projects';
    }

    public static function event(): string
    {
        return TaskCompleted::class;
    }

    public static function requiredPermission(): string
    {
        return 'projects.tasks.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['project_id' => ['type' => ['string', 'null']]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['task_id', 'project_id', 'title'], 'properties' => ['task_id' => ['type' => 'string'], 'project_id' => ['type' => 'string'], 'title' => ['type' => 'string']]];
    }
}
