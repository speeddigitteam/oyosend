<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\TrainingCompleted;

final class TrainingCompletedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.training.completed';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.training.completed';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return TrainingCompleted::class;
    }

    public static function requiredPermission(): string
    {
        return 'talent.enrolments.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['enrolment_id', 'course_id', 'employee_id'],
            'properties' => [
                'enrolment_id' => ['type' => 'string'],
                'course_id' => ['type' => 'string'],
                'employee_id' => ['type' => 'string'],
                'membership_id' => ['type' => ['string', 'null']],
                'expires_on' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
