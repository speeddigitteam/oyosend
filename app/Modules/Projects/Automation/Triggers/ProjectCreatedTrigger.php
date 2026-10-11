<?php

declare(strict_types=1);

namespace App\Modules\Projects\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Projects\Events\ProjectCreated;

final class ProjectCreatedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'projects.project.created';
    }

    public static function label(): string
    {
        return 'automation.triggers.projects.project.created';
    }

    public static function module(): string
    {
        return 'projects';
    }

    public static function event(): string
    {
        return ProjectCreated::class;
    }

    public static function requiredPermission(): string
    {
        return 'projects.projects.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['project_id', 'name'], 'properties' => ['project_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']], 'owner_id' => ['type' => ['string', 'null']]]];
    }
}
