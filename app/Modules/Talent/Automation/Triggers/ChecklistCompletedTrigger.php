<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\ChecklistCompleted;

final class ChecklistCompletedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.checklist.completed';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.checklist.completed';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return ChecklistCompleted::class;
    }

    public static function requiredPermission(): string
    {
        return 'talent.onboarding.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['checklist_id', 'employee_id', 'kind'],
            'properties' => [
                'checklist_id' => ['type' => 'string'],
                'employee_id' => ['type' => 'string'],
                'membership_id' => ['type' => ['string', 'null']],
                'kind' => ['type' => 'string'],
            ],
        ];
    }
}
