<?php

declare(strict_types=1);

namespace App\Modules\Talent\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Talent\Events\CertificateExpiring;

final class CertificateExpiringTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'talent.certificate.expiring';
    }

    public static function label(): string
    {
        return 'automation.triggers.talent.certificate.expiring';
    }

    public static function module(): string
    {
        return 'talent';
    }

    public static function event(): string
    {
        return CertificateExpiring::class;
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
            'required' => ['enrolment_id', 'course_id', 'employee_id', 'expires_on', 'days_left'],
            'properties' => [
                'enrolment_id' => ['type' => 'string'],
                'course_id' => ['type' => 'string'],
                'employee_id' => ['type' => 'string'],
                'membership_id' => ['type' => ['string', 'null']],
                'expires_on' => ['type' => 'string'],
                'days_left' => ['type' => 'integer'],
            ],
        ];
    }
}
