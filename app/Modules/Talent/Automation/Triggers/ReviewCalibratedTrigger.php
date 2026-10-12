<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\ReviewCalibrated;

final class ReviewCalibratedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.review.calibrated';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.review.calibrated';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return ReviewCalibrated::class;
    }

    public static function requiredPermission(): string
    {
        return 'talent.reviews.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['review_id', 'cycle_id', 'employee_id', 'final_score'],
            'properties' => [
                'review_id' => ['type' => 'string'],
                'cycle_id' => ['type' => 'string'],
                'employee_id' => ['type' => 'string'],
                'membership_id' => ['type' => ['string', 'null']],
                'final_score' => ['type' => 'integer'],
            ],
        ];
    }
}
