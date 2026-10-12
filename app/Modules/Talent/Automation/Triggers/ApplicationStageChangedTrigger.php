<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\ApplicationStageChanged;

final class ApplicationStageChangedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.application.stage_changed';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.application.stage_changed';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return ApplicationStageChanged::class;
    }

    public static function requiredPermission(): string
    {
        return 'talent.applications.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['application_id', 'opening_id', 'candidate_id', 'to_stage_id', 'stage_name', 'stage_type'],
            'properties' => [
                'application_id' => ['type' => 'string'],
                'opening_id' => ['type' => 'string'],
                'candidate_id' => ['type' => 'string'],
                'from_stage_id' => ['type' => ['string', 'null']],
                'to_stage_id' => ['type' => 'string'],
                'stage_name' => ['type' => 'string'],
                'stage_type' => ['type' => 'string'],
            ],
        ];
    }
}
