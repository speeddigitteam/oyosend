<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Telephony\Events\CallEnded;

final class CallEndedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'telephony.call.ended';
    }

    public static function label(): string
    {
        return 'automation.triggers.telephony.call.ended';
    }

    public static function module(): string
    {
        return 'telephony';
    }

    public static function event(): string
    {
        return CallEnded::class;
    }

    public static function requiredPermission(): string
    {
        return 'telephony.calls.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['call_id', 'direction', 'status', 'remote_number'], 'properties' => [
            'call_id' => ['type' => 'string'],
            'direction' => ['type' => 'string', 'enum' => ['outbound', 'inbound']],
            'status' => ['type' => 'string', 'enum' => ['completed', 'missed', 'busy', 'no_answer', 'failed']],
            'remote_number' => ['type' => 'string'],
            'contact_id' => ['type' => ['string', 'null']],
            'owner_id' => ['type' => ['string', 'null']],
            'duration_seconds' => ['type' => 'integer'],
        ]];
    }
}
