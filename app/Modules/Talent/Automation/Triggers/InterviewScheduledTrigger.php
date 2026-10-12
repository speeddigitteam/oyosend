<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\InterviewScheduled;

final class InterviewScheduledTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.interview.scheduled';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.interview.scheduled';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return InterviewScheduled::class;
    }

    public static function requiredPermission(): string
    {
        return 'talent.interviews.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['interview_id', 'application_id', 'candidate_id', 'starts_at', 'ends_at', 'timezone', 'panel_employee_ids'],
            'properties' => [
                'interview_id' => ['type' => 'string'],
                'application_id' => ['type' => 'string'],
                'candidate_id' => ['type' => 'string'],
                'starts_at' => ['type' => 'string'],
                'ends_at' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'panel_employee_ids' => ['type' => 'string'],
            ],
        ];
    }
}
