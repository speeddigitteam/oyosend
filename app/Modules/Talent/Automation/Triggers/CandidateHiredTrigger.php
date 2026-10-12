<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\CandidateHired;

final class CandidateHiredTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.candidate.hired';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.candidate.hired';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return CandidateHired::class;
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
            'required' => ['application_id', 'opening_id', 'candidate_id', 'first_name', 'job_title'],
            'properties' => [
                'application_id' => ['type' => 'string'],
                'opening_id' => ['type' => 'string'],
                'candidate_id' => ['type' => 'string'],
                'first_name' => ['type' => 'string'],
                'last_name' => ['type' => ['string', 'null']],
                'email' => ['type' => ['string', 'null']],
                'phone' => ['type' => ['string', 'null']],
                'job_title' => ['type' => 'string'],
                'employment_type' => ['type' => ['string', 'null']],
                'department_id' => ['type' => ['string', 'null']],
                'branch_id' => ['type' => ['string', 'null']],
                'hiring_manager_id' => ['type' => ['string', 'null']],
                'start_on' => ['type' => ['string', 'null']],
                'offer_amount' => ['type' => ['integer', 'null']],
                'offer_currency' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
