<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\ProbationReviewDue;

final class ProbationReviewDueTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.probation.review_due';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.probation.review_due';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return ProbationReviewDue::class;
    }

    public static function requiredPermission(): string
    {
        return 'talent.probation.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['probation_id', 'employee_id', 'ends_on', 'days_left'],
            'properties' => [
                'probation_id' => ['type' => 'string'],
                'employee_id' => ['type' => 'string'],
                'membership_id' => ['type' => ['string', 'null']],
                'ends_on' => ['type' => 'string'],
                'days_left' => ['type' => 'integer'],
            ],
        ];
    }
}
