<?php

declare(strict_types=1);

namespace App\Modules\Projects\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Projects\Events\MilestoneCompleted;

final class MilestoneCompletedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'projects.milestone.completed';
    }

    public static function label(): string
    {
        return 'automation.triggers.projects.milestone.completed';
    }

    public static function module(): string
    {
        return 'projects';
    }

    public static function event(): string
    {
        return MilestoneCompleted::class;
    }

    public static function requiredPermission(): string
    {
        return 'projects.projects.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['project_id' => ['type' => ['string', 'null']]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['milestone_id', 'project_id', 'name'], 'properties' => ['milestone_id' => ['type' => 'string'], 'project_id' => ['type' => 'string'], 'name' => ['type' => 'string']]];
    }
}
